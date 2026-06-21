"""Qdrant vector database operations."""

from __future__ import annotations

import logging
import time
import uuid
from typing import List, Optional

from qdrant_client import QdrantClient
from qdrant_client.models import (
    Distance,
    FieldCondition,
    Filter,
    MatchValue,
    PointStruct,
    VectorParams,
)

from app.core import metrics
from app.core.config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()


class QdrantService:
    """Encapsulates Qdrant operations with metrics and error handling."""

    def __init__(self, host: str, port: int):
        self._client = QdrantClient(host=host, port=port, timeout=settings.QDRANT_TIMEOUT_SEC)

    async def ensure_collection(self, collection_name: str) -> None:
        try:
            self._client.get_collection(collection_name)
        except Exception:
            logger.info("qdrant.create_collection name=%s dim=%d", collection_name, settings.EMBEDDING_DIM)
            self._client.create_collection(
                collection_name=collection_name,
                vectors_config=VectorParams(
                    size=settings.EMBEDDING_DIM,
                    distance=Distance.COSINE,
                ),
            )

    async def search(
        self,
        query_vector: List[float],
        collection_name: str,
        document_id: Optional[str] = None,
        top_k: int = 4,
    ) -> List[dict]:
        search_filter = None
        if document_id:
            search_filter = Filter(
                must=[FieldCondition(key="document_id", match=MatchValue(value=document_id))]
            )

        start = time.monotonic()
        try:
            result = self._client.query_points(
                collection_name=collection_name,
                query=query_vector,
                query_filter=search_filter,
                limit=top_k,
                with_payload=True,
            )
            elapsed = time.monotonic() - start
            metrics.qdrant_search_duration_seconds.observe(elapsed)
            logger.info(
                "qdrant.search collection=%s hits=%d elapsed=%.3fs",
                collection_name,
                len(result.points),
                elapsed,
            )
            return [
                {
                    "document_id": hit.payload.get("document_id"),
                    "filename": hit.payload.get("filename"),
                    "chunk_index": hit.payload.get("chunk_index"),
                    "score": hit.score,
                    "text": hit.payload.get("text", "")[:300],
                }
                for hit in result.points
            ]
        except Exception as exc:
            logger.error("qdrant.search_failed collection=%s", collection_name, exc_info=True)
            raise

    async def search_raw(
        self,
        query_vector: List[float],
        collection_name: str,
        document_id: Optional[str] = None,
        top_k: int = 4,
    ):
        """Return full Qdrant hits (with payload text) for context assembly."""
        search_filter = None
        if document_id:
            search_filter = Filter(
                must=[FieldCondition(key="document_id", match=MatchValue(value=document_id))]
            )

        start = time.monotonic()
        result = self._client.query_points(
            collection_name=collection_name,
            query=query_vector,
            query_filter=search_filter,
            limit=top_k,
            with_payload=True,
        )
        metrics.qdrant_search_duration_seconds.observe(time.monotonic() - start)
        return result.points

    async def upsert(
        self,
        collection_name: str,
        vectors: List[List[float]],
        chunks: List[str],
        document_id: str,
        filename: str,
    ) -> int:
        from datetime import datetime, timezone

        points = [
            PointStruct(
                id=str(uuid.uuid4()),
                vector=vectors[i],
                payload={
                    "document_id": document_id,
                    "filename": filename,
                    "chunk_index": i,
                    "text": chunks[i],
                    "created_at": datetime.now(timezone.utc).isoformat(),
                },
            )
            for i in range(len(chunks))
        ]

        start = time.monotonic()
        try:
            self._client.upsert(collection_name=collection_name, points=points)
            elapsed = time.monotonic() - start
            metrics.qdrant_upsert_duration_seconds.observe(elapsed)
            logger.info(
                "qdrant.upsert doc_id=%s chunks=%d elapsed=%.3fs",
                document_id,
                len(chunks),
                elapsed,
            )
            return len(points)
        except Exception as exc:
            logger.error("qdrant.upsert_failed doc_id=%s", document_id, exc_info=True)
            raise

    async def list_documents(self, collection_name: str) -> List[dict]:
        scroll = self._client.scroll(
            collection_name=collection_name,
            limit=1000,
            with_payload=True,
            with_vectors=False,
        )
        docs: dict = {}
        for point in scroll[0]:
            did = point.payload.get("document_id")
            if did and did not in docs:
                docs[did] = {
                    "document_id": did,
                    "filename": point.payload.get("filename"),
                    "num_chunks": 0,
                    "created_at": point.payload.get("created_at"),
                }
            if did:
                docs[did]["num_chunks"] += 1
        return list(docs.values())

    async def delete_document(self, collection_name: str, document_id: str) -> bool:
        self._client.delete(
            collection_name=collection_name,
            points_selector=Filter(
                must=[FieldCondition(key="document_id", match=MatchValue(value=document_id))]
            ),
        )
        logger.info("qdrant.delete doc_id=%s", document_id)
        return True

    def get_collection_info(self, collection_name: str) -> dict:
        info = self._client.get_collection(collection_name)
        scroll = self._client.scroll(
            collection_name=collection_name,
            limit=10000,
            with_payload=True,
            with_vectors=False,
        )
        doc_ids = {p.payload.get("document_id") for p in scroll[0] if p.payload}
        return {
            "status": "up",
            "points_count": info.points_count,
            "documents_count": len(doc_ids),
            "host": settings.QDRANT_HOST,
            "port": settings.QDRANT_PORT,
            "collection_name": collection_name,
            "vector_size": settings.EMBEDDING_DIM,
            "distance": "cosine",
        }

    async def health(self) -> dict:
        try:
            self._client.get_collections()
            return {"qdrant": "up"}
        except Exception as exc:
            return {"qdrant": f"down ({exc.__class__.__name__})"}
