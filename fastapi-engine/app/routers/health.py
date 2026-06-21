"""Health-check and metrics endpoints."""

from __future__ import annotations

from datetime import datetime, timezone

from fastapi import APIRouter, Request

from app.core import metrics as m
from app.core.config import get_settings
from app.models import HealthResponse

router = APIRouter(tags=["health"])
settings = get_settings()


@router.get("/", response_model=HealthResponse)
async def healthcheck(request: Request) -> HealthResponse:
    qdrant_svc = request.app.state.qdrant
    redis_client = request.app.state.redis
    embedder_svc = request.app.state.embedder

    deps: dict = {"qdrant": "unknown", "redis": "unknown", "embedder": "unknown", "llm": "unknown"}

    try:
        qdrant_health = await qdrant_svc.health()
        deps.update(qdrant_health)
    except Exception as exc:
        deps["qdrant"] = f"down ({exc.__class__.__name__})"

    try:
        pong = await redis_client.ping()
        deps["redis"] = "up" if pong else "down"
    except Exception as exc:
        deps["redis"] = f"down ({exc.__class__.__name__})"

    try:
        deps["embedder"] = "up" if embedder_svc.is_ready else "down"
    except Exception as exc:
        deps["embedder"] = f"down ({exc.__class__.__name__})"

    deps["llm"] = "configured" if request.app.state.llm.is_configured else "missing-key"

    overall = (
        "ok"
        if deps["qdrant"] == "up" and deps["redis"] == "up" and deps["embedder"] == "up"
        else "degraded"
    )

    return HealthResponse(
        status=overall,
        service=settings.APP_NAME,
        version=settings.APP_VERSION,
        timestamp=datetime.now(timezone.utc).isoformat(),
        dependencies=deps,
    )


@router.get("/metrics")
async def prometheus_metrics():
    """Prometheus scrape endpoint."""
    return m.metrics_response()


@router.get("/bulkheads")
async def bulkhead_status(request: Request):
    """Return current bulkhead utilisation."""
    return request.app.state.bulkheads.status()
