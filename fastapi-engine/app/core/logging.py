"""Structured JSON logging with correlation-id propagation."""

from __future__ import annotations

import json
import logging
import sys
from contextvars import ContextVar
from datetime import datetime, timezone
from uuid import uuid4


# Context variable shared across async tasks for the lifetime of a request.
correlation_id_ctx: ContextVar[str] = ContextVar("correlation_id", default="")


def get_correlation_id() -> str:
    """Return the current correlation-id or generate a new one."""
    cid = correlation_id_ctx.get()
    if not cid:
        cid = str(uuid4())[:8]
        correlation_id_ctx.set(cid)
    return cid


def set_correlation_id(cid: str) -> None:
    correlation_id_ctx.set(cid)


class JsonFormatter(logging.Formatter):
    """Emit log records as JSON lines with mandatory correlation-id."""

    def format(self, record: logging.LogRecord) -> str:
        log_entry: dict = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "correlation_id": get_correlation_id(),
            "message": record.getMessage(),
        }

        if record.exc_info and record.exc_info[1]:
            log_entry["exception"] = {
                "type": type(record.exc_info[1]).__name__,
                "message": str(record.exc_info[1]),
            }

        # Merge extra fields passed via `extra={...}`.
        for key in dir(record):
            if key not in {
                "args", "asctime", "created", "exc_info", "exc_text",
                "filename", "funcName", "levelname", "levelno", "lineno",
                "module", "msecs", "message", "msg", "name", "pathname",
                "process", "processName", "relativeCreated", "stack_info",
                "thread", "threadName",
            }:
                value = getattr(record, key, None)
                if value is not None and not key.startswith("_"):
                    log_entry[key] = value

        return json.dumps(log_entry, default=str, ensure_ascii=False)


def setup_logging(log_level: str = "INFO") -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())

    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(getattr(logging, log_level.upper(), logging.INFO))

    # Silence noisy libs
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)
    logging.getLogger("uvicorn.access").setLevel(logging.WARNING)
