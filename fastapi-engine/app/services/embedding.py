"""Text chunking and embedding generation service."""

from __future__ import annotations

import asyncio
import logging
import time
from typing import List

from fastembed import TextEmbedding

from app.core import metrics
from app.core.config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()


class EmbeddingService:
    """Handles text chunking and vector embedding generation."""

    def __init__(self):
        self._embedder: TextEmbedding | None = None

    @property
    def is_ready(self) -> bool:
        return self._embedder is not None

    def startup(self) -> None:
        self._embedder = TextEmbedding(model_name=settings.EMBEDDING_MODEL)
        logger.info(
            "embedding.loaded model=%s dim=%d",
            settings.EMBEDDING_MODEL,
            settings.EMBEDDING_DIM,
        )

    async def embed(self, texts: List[str]) -> List[List[float]]:
        """Generate embeddings for a list of texts (CPU-bound → run in executor)."""
        if not self._embedder:
            raise RuntimeError("Embedder not initialised")

        start = time.monotonic()
        loop = asyncio.get_event_loop()
        vectors = await loop.run_in_executor(
            None,
            lambda: list(self._embedder.embed(texts)),
        )
        elapsed = time.monotonic() - start

        metrics.embedding_duration_seconds.labels(model=settings.EMBEDDING_MODEL).observe(elapsed)
        metrics.embedding_chunks_total.labels(model=settings.EMBEDDING_MODEL).inc(len(texts))

        logger.info(
            "embedding.generated count=%d elapsed=%.3fs avg=%.1fms/chunk",
            len(texts),
            elapsed,
            (elapsed / len(texts)) * 1000 if texts else 0,
        )
        return [list(v) for v in vectors]

    def chunk_text(self, text: str, chunk_size: int | None = None, overlap: int | None = None) -> List[str]:
        """Split text into overlapping chunks.

        For automotive documentation (manuals with headings, tables, procedures),
        prioritises semantic boundaries (double newlines, sections) over naive
        sliding-window splitting.
        """
        cs = chunk_size or settings.CHUNK_SIZE
        ov = overlap or settings.CHUNK_OVERLAP

        if not text.strip():
            return []

        # --- Semantic chunking pass ---
        # Split on double newlines first (paragraph / section boundaries).
        sections = [s.strip() for s in text.split("\n\n") if s.strip()]

        # If most sections are already small enough, keep them as-is.
        avg_section_words = sum(len(s.split()) for s in sections) / max(len(sections), 1)
        if avg_section_words <= cs * 0.8:
            logger.info(
                "embedding.semantic_chunks sections=%d avg_words=%.0f",
                len(sections),
                avg_section_words,
            )
            return sections

        # --- Fallback: sliding window ---
        words = text.split()
        chunks: List[str] = []
        step = max(1, cs - ov)
        for i in range(0, len(words), step):
            chunk = " ".join(words[i : i + cs])
            if chunk:
                chunks.append(chunk)
        logger.info("embedding.sliding_window_chunks count=%d", len(chunks))
        return chunks

    @staticmethod
    async def extract_text(filename: str, content: bytes) -> str:
        """Extract text from uploaded file (PDF, TXT, MD)."""
        import io

        from fastapi import HTTPException
        from pypdf import PdfReader

        filename_lower = filename.lower()
        if filename_lower.endswith(".pdf"):
            try:
                reader = PdfReader(io.BytesIO(content))
                return "\n".join(page.extract_text() or "" for page in reader.pages)
            except Exception as exc:
                raise HTTPException(status_code=400, detail=f"Cannot parse PDF: {exc}") from exc
        try:
            return content.decode("utf-8")
        except UnicodeDecodeError:
            try:
                return content.decode("latin-1")
            except Exception as exc:
                raise HTTPException(status_code=400, detail=f"Cannot decode file: {exc}") from exc
