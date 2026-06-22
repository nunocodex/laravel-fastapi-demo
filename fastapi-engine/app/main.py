"""
FastAPI AI Engine — Enterprise Edition (v2.0.0)
===============================================
RAG + LLM engine for the automotive AI demo stack.

Architecture:
- Routers:    health, documents, chat, analysis
- Services:   circuit breaker, callback (retry+DLQ), llm (graceful degradation),
              qdrant, embedding
- Middleware:  correlation-id propagation
- Metrics:    Prometheus /metrics endpoint
- Config:     pydantic-settings with .env support
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

import redis.asyncio as aioredis
from fastapi import FastAPI
from slowapi import Limiter
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
from fastapi import HTTPException, Request

from app.core.config import get_settings
from app.core.logging import setup_logging
from app.core.tracing import setup_tracing, instrument_app
from app.middleware.correlation import CorrelationIdMiddleware
from app.routers import health, documents, chat, analysis, admin
from app.services.circuit import CircuitBreaker
from app.services.embedding import EmbeddingService
from app.services.llm import LlmService
from app.services.qdrant import QdrantService
from app.services.bulkhead import BulkheadRegistry

settings = get_settings()
setup_logging(settings.LOG_LEVEL)
setup_tracing(settings.APP_NAME)

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    # ---- Startup ----
    logger.info("startup.begin version=%s", settings.APP_VERSION)

    # Redis (used by chat history, circuit breaker state, DLQ, cache).
    redis_client = aioredis.Redis(
        host=settings.REDIS_HOST,
        port=settings.REDIS_PORT,
        decode_responses=True,
        socket_connect_timeout=settings.REDIS_TIMEOUT_SEC,
    )

    # Qdrant.
    qdrant_svc = QdrantService(host=settings.QDRANT_HOST, port=settings.QDRANT_PORT)
    await qdrant_svc.ensure_collection(settings.COLLECTION_NAME)

    # Embedding model.
    embedder_svc = EmbeddingService()
    embedder_svc.startup()

    # LLM client.
    llm_svc = LlmService(redis_client=redis_client)
    await llm_svc.startup()

    # Circuit breaker for callback delivery.
    callback_circuit = CircuitBreaker(
        name="laravel-callback",
        redis=redis_client,
        failure_threshold=settings.CALLBACK_CIRCUIT_THRESHOLD,
        timeout_sec=settings.CALLBACK_CIRCUIT_TIMEOUT_SEC,
    )

    # Store services on the app state for router access.
    app.state.redis = redis_client
    app.state.qdrant = qdrant_svc
    app.state.embedder = embedder_svc
    app.state.llm = llm_svc
    app.state.callback_circuit = callback_circuit
    app.state.bulkheads = BulkheadRegistry()

    logger.info(
        "startup.ready qdrant=%s:%s redis=%s:%s llm=%s emb=%s",
        settings.QDRANT_HOST,
        settings.QDRANT_PORT,
        settings.REDIS_HOST,
        settings.REDIS_PORT,
        "configured" if llm_svc.is_configured else "missing-key",
        settings.EMBEDDING_MODEL,
    )

    try:
        yield
    finally:
        # ---- Shutdown ----
        logger.info("shutdown.begin")
        await llm_svc.shutdown()
        try:
            await redis_client.aclose()
        except Exception:
            logger.warning("shutdown.redis_close_failed", exc_info=True)


# ---- Rate limiter ----
limiter = Limiter(key_func=get_remote_address)

app = FastAPI(
    title=settings.APP_NAME,
    version=settings.APP_VERSION,
    description="RAG + LLM engine for automotive AI: document ingestion, vector search, multi-turn chat.",
    lifespan=lifespan,
)

app.state.limiter = limiter

# ---- Middleware ----
app.add_middleware(CorrelationIdMiddleware)


@app.exception_handler(RateLimitExceeded)
async def _rate_limit_handler(request: Request, exc: RateLimitExceeded):
    raise HTTPException(status_code=429, detail="Too many requests. Please slow down.")


# ---- Routers ----
app.include_router(health.router)
app.include_router(documents.router)
app.include_router(chat.router)
app.include_router(analysis.router)
app.include_router(admin.router)

# Auto-instrument for distributed tracing (no-op unless OTEL_EXPORTER_OTLP_ENDPOINT is set).
instrument_app(app)
