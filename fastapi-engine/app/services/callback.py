"""Callback delivery service with retry, circuit breaker, and Dead Letter Queue.

Delivers completion/failure notifications from FastAPI → Laravel.
Retries with exponential backoff, pushes to a Redis-based DLQ on
terminal exhaustion, and honours the circuit breaker state.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import time
from datetime import datetime, timezone

import httpx
from tenacity import (
    before_sleep_log,
    retry,
    retry_if_exception_type,
    stop_after_attempt,
    wait_exponential,
)

from app.core import metrics
from app.core.config import get_settings
from app.models import CallbackPayload
from app.services.circuit import CircuitBreaker, CircuitOpenError

logger = logging.getLogger(__name__)
settings = get_settings()


def build_signature(task_uuid: str, timestamp: str) -> str:
    if not settings.CALLBACK_HMAC_SECRET:
        return ""
    msg = f"{task_uuid}|{timestamp}".encode("utf-8")
    return hmac.new(
        settings.CALLBACK_HMAC_SECRET.encode("utf-8"),
        msg,
        hashlib.sha256,
    ).hexdigest()


class CallbackDeliveryError(Exception):
    """Transient delivery failure (will be retried)."""


class CallbackFatalError(Exception):
    """Non-retryable delivery failure (pushed to DLQ immediately)."""


async def send_callback(
    task_uuid: str,
    payload: CallbackPayload,
    circuit: CircuitBreaker,
    redis_client,
) -> dict:
    """Deliver callback to Laravel with retry + circuit breaker + DLQ.

    Returns a dict with delivery metadata.
    """
    url = f"{settings.LARAVEL_BASE_URL}{settings.LARAVEL_CALLBACK_PATH}/{task_uuid}"

    @retry(
        stop=stop_after_attempt(settings.CALLBACK_MAX_RETRIES),
        wait=wait_exponential(
            multiplier=1,
            min=settings.CALLBACK_RETRY_BACKOFF_MS / 1000,
            max=30,
        ),
        retry=retry_if_exception_type((CallbackDeliveryError, httpx.NetworkError)),
        before_sleep=before_sleep_log(logger, logging.WARNING),
    )
    async def _deliver() -> dict:
        try:
            async with circuit:
                return await _do_deliver(url, task_uuid)
        except CircuitOpenError:
            metrics.callback_attempts_total.labels(status="circuit_open").inc()
            raise CallbackDeliveryError("Circuit open — will retry")

    try:
        start = time.monotonic()
        result = await _deliver()
        metrics.callback_duration_seconds.observe(time.monotonic() - start)
        metrics.callback_attempts_total.labels(status="delivered").inc()
        return result
    except Exception as exc:
        metrics.callback_attempts_total.labels(status="failed").inc()

        if settings.FEATURE_DLQ_ENABLED:
            await _push_to_dlq(redis_client, task_uuid, payload, str(exc))

        logger.error(
            "callback.exhausted task=%s retries=%d",
            task_uuid,
            settings.CALLBACK_MAX_RETRIES,
        )
        return {
            "callback_url": url,
            "status_code": 0,
            "error": f"Exhausted retries: {exc.__class__.__name__}: {exc}",
        }


async def _do_deliver(url: str, task_uuid: str) -> dict:
    timestamp = str(int(time.time()))
    signature = build_signature(task_uuid, timestamp)
    headers = {
        "X-AI-Engine": f"{settings.APP_NAME}/{settings.APP_VERSION}",
        "X-AI-Timestamp": timestamp,
        "X-AI-Signature": signature,
        "X-Correlation-ID": task_uuid,
    }

    async with httpx.AsyncClient(timeout=10.0) as client:
        try:
            response = await client.post(
                url,
                json=CallbackPayload(
                    status="completed",
                    result={},
                    metadata={
                        "engine_version": settings.APP_VERSION,
                        "completed_at": datetime.now(timezone.utc).isoformat(),
                    },
                ).model_dump() if "completed" in url else {},
                headers=headers,
            )
            response.raise_for_status()
            logger.info("callback.delivered task=%s status=%s", task_uuid, response.status_code)
            return {
                "callback_url": url,
                "status_code": response.status_code,
                "response": (
                    response.json()
                    if response.headers.get("content-type", "").startswith("application/json")
                    else response.text
                ),
            }
        except httpx.HTTPStatusError as exc:
            status_code = exc.response.status_code
            # 4xx errors (except 429) are non-retryable.
            if 400 <= status_code < 500 and status_code != 429:
                raise CallbackFatalError(
                    f"HTTP {status_code}: {exc.response.text[:200]}"
                ) from exc
            raise CallbackDeliveryError(
                f"HTTP {status_code}: {exc.response.text[:200]}"
            ) from exc


async def _push_to_dlq(
    redis_client,
    task_uuid: str,
    payload: CallbackPayload,
    error: str,
) -> None:
    """Push a failed callback to the Redis-based Dead Letter Queue."""
    dlq_entry = {
        "task_uuid": task_uuid,
        "payload": payload.model_dump(),
        "error": error,
        "queued_at": datetime.now(timezone.utc).isoformat(),
    }
    try:
        key = "dlq:callbacks"
        await redis_client.rpush(key, json.dumps(dlq_entry))
        dlq_len = await redis_client.llen(key)
        metrics.dlq_size.set(dlq_len)
        logger.info("callback.dlq_pushed task=%s dlq_size=%d", task_uuid, dlq_len)
    except Exception as exc:
        logger.error("callback.dlq_push_failed task=%s err=%s", task_uuid, exc)


async def replay_dlq(redis_client, circuit: CircuitBreaker) -> int:
    """Replay all entries in the Dead Letter Queue. Returns count replayed."""
    key = "dlq:callbacks"
    replayed = 0
    while True:
        raw = await redis_client.lpop(key)
        if raw is None:
            break
        entry = json.loads(raw)
        try:
            payload_data = entry.get("payload", {})
            await send_callback(
                entry["task_uuid"],
                CallbackPayload(**payload_data),
                circuit,
                redis_client,
            )
            replayed += 1
        except Exception:
            # Re-push if it still fails.
            await _push_to_dlq(
                redis_client,
                entry["task_uuid"],
                CallbackPayload(**entry.get("payload", {})),
                "DLQ replay failed",
            )
    metrics.dlq_size.set(0)
    logger.info("callback.dlq_replayed count=%d", replayed)
    return replayed
