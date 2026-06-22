"""Legacy analysis endpoint + stats endpoint."""

from __future__ import annotations

import asyncio
from datetime import datetime, timezone

from fastapi import APIRouter, HTTPException, Request, status

from app.core.config import get_settings
from app.models import AnalysisAck, AnalysisRequest, CallbackPayload

router = APIRouter(tags=["analysis"])
settings = get_settings()


# Keep legacy analysis for backward compat with Laravel task flow.
async def retrieve_context(document_id: int, prompt_template: str) -> str:
    await asyncio.sleep(0.05)
    return (
        f"[vector-context for doc={document_id} | template='{prompt_template[:32]}'] "
        f"sample-embedding-#{document_id}"
    )


async def run_inference(document_id: int, prompt_template: str, context: str) -> str:
    await asyncio.sleep(0.1)
    fake_tokens = 64 + (document_id % 16)
    return (
        f"Inference completed for document {document_id} using template "
        f"'{prompt_template}'. Context tokens resolved: {fake_tokens}. "
        f"Result confidence: 0.{(document_id * 7) % 100:02d}"
    )


@router.post(
    "/api/v1/analyze/{task_uuid}",
    response_model=AnalysisAck,
    status_code=status.HTTP_202_ACCEPTED,
)
async def analyze(request: Request, task_uuid: str, payload: AnalysisRequest) -> AnalysisAck:
    from app.services.callback import send_callback

    try:
        import uuid as _uuid
        validated_uuid = str(_uuid.UUID(task_uuid))
    except ValueError:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Invalid task_uuid. Must be a valid UUID string.",
        )

    received_at = datetime.now(timezone.utc).isoformat()

    async def process_and_callback():
        try:
            context = await retrieve_context(payload.document_id, payload.prompt_template)
            inference_result = await run_inference(
                payload.document_id, payload.prompt_template, context,
            )

            cb = CallbackPayload(
                status="completed",
                result={
                    "document_id": payload.document_id,
                    "prompt_template": payload.prompt_template,
                    "inference": inference_result,
                    "context_excerpt": context,
                },
                metadata={
                    "engine_version": settings.APP_VERSION,
                    "completed_at": datetime.now(timezone.utc).isoformat(),
                },
            )

            await send_callback(
                validated_uuid, cb,
                request.app.state.callback_circuit,
                request.app.state.redis,
            )
        except Exception:
            import logging
            logger = logging.getLogger(__name__)
            logger.error("analyze.background_error task=%s", validated_uuid, exc_info=True)
            try:
                await send_callback(
                    validated_uuid,
                    CallbackPayload(status="failed", result={}, error="Background processing error"),
                    request.app.state.callback_circuit,
                    request.app.state.redis,
                )
            except Exception:
                pass

    asyncio.create_task(process_and_callback())

    return AnalysisAck(
        task_uuid=validated_uuid,
        document_id=payload.document_id,
        prompt_template=payload.prompt_template,
        received_at=received_at,
    )


@router.get("/api/v1/stats")
async def engine_stats(request: Request):
    qdrant_svc = request.app.state.qdrant
    redis_client = request.app.state.redis

    qdrant_stats = await qdrant_svc.get_collection_info(settings.COLLECTION_NAME)

    redis_status = "unknown"
    try:
        await redis_client.ping()
        redis_status = "up"
    except Exception:
        redis_status = "down"

    return {
        "qdrant": qdrant_stats,
        "redis": {
            "status": redis_status,
            "host": settings.REDIS_HOST,
            "port": settings.REDIS_PORT,
        },
        "fastapi": {
            "status": "up",
            "version": settings.APP_VERSION,
            "embedding_model": settings.EMBEDDING_MODEL,
            "embedding_dim": settings.EMBEDDING_DIM,
            "collection_name": settings.COLLECTION_NAME,
            "chunk_size": settings.CHUNK_SIZE,
            "chunk_overlap": settings.CHUNK_OVERLAP,
            "llm_provider": "deepseek",
            "llm_model": settings.DEEPSEEK_MODEL if settings.DEEPSEEK_API_KEY else None,
            "llm_status": "configured" if settings.DEEPSEEK_API_KEY else "missing-key",
        },
    }
