"""Prometheus metrics for the FastAPI engine.

Exposes counters, histograms, and gauges for:
- HTTP request latencies and counts
- Callback delivery attempts / failures
- Qdrant search latencies
- LLM inference latencies and token usage
- Embedding generation latencies
- Circuit breaker state changes
"""

from __future__ import annotations

from prometheus_client import Counter, Gauge, Histogram, generate_latest
from fastapi import Response

# ---- HTTP ----
http_requests_total = Counter(
    "ai_http_requests_total",
    "Total HTTP requests",
    ["method", "endpoint", "status"],
)

http_request_duration_seconds = Histogram(
    "ai_http_request_duration_seconds",
    "HTTP request duration",
    ["method", "endpoint"],
    buckets=[0.01, 0.05, 0.1, 0.5, 1, 2, 5, 10, 30],
)

# ---- Callbacks ----
callback_attempts_total = Counter(
    "ai_callback_attempts_total",
    "Callback delivery attempts",
    ["status"],  # delivered / failed / circuit_open
)

callback_duration_seconds = Histogram(
    "ai_callback_duration_seconds",
    "Callback delivery duration",
    buckets=[0.1, 0.5, 1, 2, 5, 10, 30],
)

dlq_size = Gauge(
    "ai_callback_dlq_size",
    "Number of callbacks in the Dead Letter Queue",
)

# ---- Qdrant ----
qdrant_search_duration_seconds = Histogram(
    "ai_qdrant_search_duration_seconds",
    "Qdrant vector search duration",
    buckets=[0.01, 0.05, 0.1, 0.5, 1, 2, 5],
)

qdrant_upsert_duration_seconds = Histogram(
    "ai_qdrant_upsert_duration_seconds",
    "Qdrant upsert duration",
    buckets=[0.05, 0.1, 0.5, 1, 2, 5, 10],
)

# ---- LLM ----
llm_inference_duration_seconds = Histogram(
    "ai_llm_inference_duration_seconds",
    "LLM inference duration",
    ["model"],
    buckets=[0.5, 1, 2, 5, 10, 20, 30, 60],
)

llm_tokens_total = Counter(
    "ai_llm_tokens_total",
    "LLM token usage",
    ["model", "type"],  # prompt / completion
)

llm_errors_total = Counter(
    "ai_llm_errors_total",
    "LLM call errors",
    ["model", "error_type"],
)

# ---- Embedding ----
embedding_duration_seconds = Histogram(
    "ai_embedding_duration_seconds",
    "Embedding generation duration",
    ["model"],
    buckets=[0.05, 0.1, 0.5, 1, 2, 5, 10],
)

embedding_chunks_total = Counter(
    "ai_embedding_chunks_total",
    "Total chunks embedded",
    ["model"],
)

# ---- Circuit Breaker ----
circuit_breaker_state = Gauge(
    "ai_circuit_breaker_state",
    "Circuit breaker state (0=closed, 1=open, 2=half-open)",
    ["name"],
)

# ---- Cache ----
cache_hits_total = Counter(
    "ai_cache_hits_total",
    "Response cache hits",
)
cache_misses_total = Counter(
    "ai_cache_misses_total",
    "Response cache misses",
)


def metrics_response():
    """Return a Prometheus text-format response."""
    return Response(
        content=generate_latest(),
        media_type="text/plain; version=0.0.4; charset=utf-8",
    )
