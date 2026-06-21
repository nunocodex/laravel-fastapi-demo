"""Admin endpoints for Dead Letter Queue management."""

from __future__ import annotations

from fastapi import APIRouter, Request

from app.services.callback import replay_dlq

router = APIRouter(prefix="/api/v1/admin/dlq", tags=["admin"])


@router.get("/size")
async def dlq_size(request: Request):
    redis_client = request.app.state.redis
    try:
        size = await redis_client.llen("dlq:callbacks")
        return {"size": size}
    except Exception as exc:
        return {"size": -1, "error": str(exc)}


@router.post("/replay")
async def dlq_replay(request: Request):
    redis_client = request.app.state.redis
    circuit = request.app.state.callback_circuit
    replayed = await replay_dlq(redis_client, circuit)
    return {"replayed": replayed, "ok": True}
