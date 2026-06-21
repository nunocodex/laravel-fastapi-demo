"""Redis-backed circuit breaker for inter-service calls.

Implements the standard three-state model (closed → open → half-open → closed)
with configurable failure threshold, timeout, and per-circuit tracking.
"""

from __future__ import annotations

import asyncio
import logging
import time
from dataclasses import dataclass, field
from enum import Enum
from typing import Optional

import redis.asyncio as aioredis

from app.core.metrics import circuit_breaker_state as cb_gauge

logger = logging.getLogger(__name__)


class CircuitState(Enum):
    CLOSED = 0       # Normal operation; calls proceed.
    OPEN = 1         # Failing; calls are rejected immediately.
    HALF_OPEN = 2    # Probing; a single trial call is allowed.


@dataclass
class CircuitBreaker:
    """Thread-safe (async) circuit breaker with Redis state store."""

    name: str
    redis: aioredis.Redis
    failure_threshold: int = 5
    timeout_sec: int = 30

    # Local cache to avoid Redis round-trips on every call.
    _state: CircuitState = field(default=CircuitState.CLOSED, init=False)
    _failures: int = field(default=0, init=False)
    _opened_at: float = field(default=0.0, init=False)
    _lock: asyncio.Lock = field(default_factory=asyncio.Lock, init=False)

    def _redis_key(self, suffix: str) -> str:
        return f"cb:{self.name}:{suffix}"

    async def _load_state(self) -> None:
        """Restore circuit state from Redis (survives process restarts)."""
        try:
            state_raw = await self.redis.get(self._redis_key("state"))
            if state_raw:
                self._state = CircuitState(int(state_raw))
            failures_raw = await self.redis.get(self._redis_key("failures"))
            if failures_raw:
                self._failures = int(failures_raw)
            opened_raw = await self.redis.get(self._redis_key("opened_at"))
            if opened_raw:
                self._opened_at = float(opened_raw)
        except Exception:
            logger.warning("circuit_breaker.load_state_failed name=%s", self.name, exc_info=True)

        self._maybe_transition()
        self._emit_gauge()

    async def _save_state(self) -> None:
        try:
            async with asyncio.TaskGroup() as tg:
                tg.create_task(self.redis.set(self._redis_key("state"), self._state.value, ex=3600))
                tg.create_task(self.redis.set(self._redis_key("failures"), self._failures, ex=3600))
                tg.create_task(self.redis.set(self._redis_key("opened_at"), self._opened_at, ex=3600))
        except Exception:
            logger.warning("circuit_breaker.save_state_failed name=%s", self.name, exc_info=True)

    def _emit_gauge(self) -> None:
        cb_gauge.labels(name=self.name).set(self._state.value)

    def _maybe_transition(self) -> None:
        """Check elapsed time and transition OPEN → HALF_OPEN if timeout expired."""
        if self._state == CircuitState.OPEN:
            if time.monotonic() - self._opened_at >= self.timeout_sec:
                self._state = CircuitState.HALF_OPEN
                logger.info("circuit_breaker.half_open name=%s", self.name)

    async def _on_success(self) -> None:
        if self._state == CircuitState.HALF_OPEN:
            self._state = CircuitState.CLOSED
            logger.info("circuit_breaker.closed name=%s", self.name)
        self._failures = 0
        await self._save_state()
        self._emit_gauge()

    async def _on_failure(self) -> None:
        self._failures += 1
        if self._state == CircuitState.HALF_OPEN or self._failures >= self.failure_threshold:
            self._state = CircuitState.OPEN
            self._opened_at = time.monotonic()
            logger.warning(
                "circuit_breaker.opened name=%s failures=%d timeout=%ds",
                self.name, self._failures, self.timeout_sec,
            )
        await self._save_state()
        self._emit_gauge()

    @property
    def is_open(self) -> bool:
        self._maybe_transition()
        return self._state == CircuitState.OPEN

    @property
    def state(self) -> CircuitState:
        self._maybe_transition()
        return self._state

    async def __aenter__(self) -> "CircuitBreaker":
        await self._lock.acquire()
        if self._failures == 0 and self._state == CircuitState.CLOSED:
            # First call since creation — pull state from Redis.
            await self._load_state()
        self._maybe_transition()

        if self._state == CircuitState.OPEN:
            self._lock.release()
            logger.warning("circuit_breaker.rejected name=%s", self.name)
            raise CircuitOpenError(
                f"Circuit '{self.name}' is OPEN. "
                f"Retry after {self.timeout_sec - (time.monotonic() - self._opened_at):.1f}s."
            )
        return self

    async def __aexit__(self, exc_type, exc_val, exc_tb):
        try:
            if exc_type is None:
                await self._on_success()
            elif exc_type is not None and not isinstance(exc_val, CircuitOpenError):
                await self._on_failure()
        finally:
            self._lock.release()


class CircuitOpenError(Exception):
    """Raised when a call is rejected because the circuit is open."""
