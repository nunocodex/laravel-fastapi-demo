"""Bulkhead pattern — isolate resource pools for different operation classes.

Prevents a flood of document uploads from starving chat/completion requests.
Uses asyncio.Semaphore to cap concurrent operations per bulkhead.

Bulkheads:
- upload:    max 2 concurrent (CPU-heavy embedding + Qdrant upsert)
- chat:      max 8 concurrent (I/O-bound LLM + vector search)
- callback:  max 4 concurrent (outbound HTTP delivery)
"""

from __future__ import annotations

import asyncio
import logging
from contextlib import asynccontextmanager
from dataclasses import dataclass
from enum import Enum
from typing import Optional

from app.core import metrics as m

logger = logging.getLogger(__name__)


class BulkheadName(str, Enum):
    UPLOAD = "upload"
    CHAT = "chat"
    CALLBACK = "callback"
    ANALYZE = "analyze"


@dataclass
class BulkheadConfig:
    max_concurrent: int
    max_queue: int = 0  # 0 = no queue, reject immediately


DEFAULTS: dict[BulkheadName, BulkheadConfig] = {
    BulkheadName.UPLOAD: BulkheadConfig(max_concurrent=2),
    BulkheadName.CHAT: BulkheadConfig(max_concurrent=8),
    BulkheadName.CALLBACK: BulkheadConfig(max_concurrent=4),
    BulkheadName.ANALYZE: BulkheadConfig(max_concurrent=4),
}


class BulkheadFullError(Exception):
    """Raised when a bulkhead has no available slots."""


class Bulkhead:
    """Concurrency limiter for a named operation class."""

    def __init__(self, name: BulkheadName, config: Optional[BulkheadConfig] = None):
        self.name = name
        self.config = config or DEFAULTS.get(name, BulkheadConfig(max_concurrent=4))
        self._semaphore = asyncio.Semaphore(self.config.max_concurrent)
        self._active = 0

    @property
    def available(self) -> int:
        return self.config.max_concurrent - self._active

    @asynccontextmanager
    async def acquire(self):
        """Acquire a bulkhead slot or raise BulkheadFullError if none available."""
        if self._active >= self.config.max_concurrent and self.config.max_queue == 0:
            logger.warning(
                "bulkhead.full name=%s active=%d max=%d",
                self.name.value,
                self._active,
                self.config.max_concurrent,
            )
            raise BulkheadFullError(
                f"Bulkhead '{self.name.value}' is full ({self._active}/{self.config.max_concurrent})"
            )

        await self._semaphore.acquire()
        self._active += 1
        logger.debug(
            "bulkhead.acquired name=%s active=%d",
            self.name.value,
            self._active,
        )

        try:
            yield
        finally:
            self._active -= 1
            self._semaphore.release()
            logger.debug(
                "bulkhead.released name=%s active=%d",
                self.name.value,
                self._active,
            )


class BulkheadRegistry:
    """Holds all bulkheads; created once at startup."""

    def __init__(self):
        self._bulkheads: dict[BulkheadName, Bulkhead] = {
            name: Bulkhead(name) for name in BulkheadName
        }

    def get(self, name: BulkheadName) -> Bulkhead:
        return self._bulkheads[name]

    def status(self) -> dict:
        return {
            bh.name.value: {
                "max_concurrent": bh.config.max_concurrent,
                "active": bh._active,
                "available": bh.available,
            }
            for bh in self._bulkheads.values()
        }
