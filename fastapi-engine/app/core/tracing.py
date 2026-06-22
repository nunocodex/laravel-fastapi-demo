"""OpenTelemetry tracing setup.

Exports spans to an OTLP collector (e.g., Jaeger, Grafana Tempo, Honeycomb).
Configurable via env vars; if OTEL_EXPORTER_OTLP_ENDPOINT is not set, tracing
is a no-op (spans are still created but not exported).

Env vars (OpenTelemetry standard):
  OTEL_EXPORTER_OTLP_ENDPOINT   e.g. http://jaeger:4317
  OTEL_SERVICE_NAME             defaults to "ai-fastapi-engine"
"""

from __future__ import annotations

import logging
import os

logger = logging.getLogger(__name__)


def setup_tracing(app_name: str = "ai-fastapi-engine") -> None:
    """Initialise OpenTelemetry SDK if an OTLP endpoint is configured."""
    otlp_endpoint = os.getenv("OTEL_EXPORTER_OTLP_ENDPOINT", "")
    if not otlp_endpoint:
        logger.info("otel.disabled (set OTEL_EXPORTER_OTLP_ENDPOINT to enable)")
        return

    try:
        from opentelemetry import trace
        from opentelemetry.sdk.trace import TracerProvider
        from opentelemetry.sdk.trace.export import BatchSpanProcessor
        from opentelemetry.sdk.resources import Resource, SERVICE_NAME
        from opentelemetry.exporter.otlp.proto.grpc.trace_exporter import OTLPSpanExporter

        resource = Resource(attributes={SERVICE_NAME: os.getenv("OTEL_SERVICE_NAME", app_name)})
        provider = TracerProvider(resource=resource)

        exporter = OTLPSpanExporter(endpoint=otlp_endpoint, insecure=True)
        provider.add_span_processor(BatchSpanProcessor(exporter))
        trace.set_tracer_provider(provider)

        logger.info("otel.enabled endpoint=%s service=%s", otlp_endpoint, app_name)
    except ImportError:
        logger.warning(
            "otel.import_failed (install opentelemetry-exporter-otlp)",
            exc_info=True,
        )
    except Exception:
        logger.warning("otel.setup_failed", exc_info=True)


def instrument_app(app) -> None:
    """Apply auto-instrumentation to a FastAPI app.

    Must be called AFTER the app is created but BEFORE the first request.
    """
    if not os.getenv("OTEL_EXPORTER_OTLP_ENDPOINT"):
        return

    try:
        from opentelemetry import trace as otel_trace
        from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor
        from opentelemetry.instrumentation.redis import RedisInstrumentor

        FastAPIInstrumentor.instrument_app(
            app,
            excluded_urls="/metrics,/health,/up",
            tracer_provider=otel_trace.get_tracer_provider() if hasattr(otel_trace, 'get_tracer_provider') else None,
        )
        RedisInstrumentor().instrument()

        logger.info("otel.instrumented fastapi=%s redis=true", app.title)
    except Exception:
        logger.warning("otel.instrument_failed", exc_info=True)
