"""Document ingestion and management endpoints."""

from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Optional

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile

from app.core.config import get_settings
from app.models import DocumentOut
from app.services.bulkhead import BulkheadFullError, BulkheadName

router = APIRouter(prefix="/api/v1/documents", tags=["documents"])
settings = get_settings()


@router.post("", response_model=DocumentOut)
async def upload_document(
    request: Request,
    file: UploadFile = File(...),
    document_id: Optional[str] = Form(None),
):
    from app.services.embedding import EmbeddingService

    if not file.filename:
        raise HTTPException(status_code=400, detail="filename is required")

    filename_lower = file.filename.lower()
    if not any(filename_lower.endswith(ext) for ext in settings.ALLOWED_EXTENSIONS):
        raise HTTPException(
            status_code=415,
            detail=f"unsupported file type. Allowed: {', '.join(sorted(settings.ALLOWED_EXTENSIONS))}",
        )

    content = await file.read()
    if len(content) > settings.MAX_FILE_BYTES:
        raise HTTPException(
            status_code=413,
            detail=f"file too large (max {settings.MAX_FILE_BYTES // (1024 * 1024)} MB)",
        )

    text = await EmbeddingService.extract_text(file.filename, content)
    if not text.strip():
        raise HTTPException(status_code=400, detail="empty document")

    embedder_svc = request.app.state.embedder
    qdrant_svc = request.app.state.qdrant

    chunks = embedder_svc.chunk_text(text)
    if not chunks:
        raise HTTPException(status_code=400, detail="could not extract usable text")

    doc_id = document_id or str(uuid.uuid4())

    bulkhead = request.app.state.bulkheads.get(BulkheadName.UPLOAD)
    try:
        async with bulkhead.acquire():
            embeddings = await embedder_svc.embed(chunks)

            await qdrant_svc.upsert(
                collection_name=settings.COLLECTION_NAME,
                vectors=embeddings,
                chunks=chunks,
                document_id=doc_id,
                filename=file.filename,
            )
    except BulkheadFullError:
        raise HTTPException(
            status_code=429,
            detail="Too many concurrent uploads. Please retry shortly.",
        )

    return DocumentOut(
        document_id=doc_id,
        filename=file.filename,
        num_chunks=len(chunks),
        created_at=datetime.now(timezone.utc).isoformat(),
        chunk_size=settings.CHUNK_SIZE,
        chunk_overlap=settings.CHUNK_OVERLAP,
        embedding_model=settings.EMBEDDING_MODEL,
        embedding_dim=settings.EMBEDDING_DIM,
        collection_name=settings.COLLECTION_NAME,
    )


@router.get("")
async def list_documents(request: Request):
    qdrant_svc = request.app.state.qdrant
    try:
        docs = await qdrant_svc.list_documents(settings.COLLECTION_NAME)
        return {"documents": docs}
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"Qdrant scroll failed: {exc}") from exc


@router.delete("/{document_id}")
async def delete_document(request: Request, document_id: str):
    qdrant_svc = request.app.state.qdrant
    try:
        await qdrant_svc.delete_document(settings.COLLECTION_NAME, document_id)
        return {"ok": True, "document_id": document_id}
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"Qdrant delete failed: {exc}") from exc
