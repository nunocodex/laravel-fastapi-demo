"""LLM client service with feature flags, graceful degradation, and caching."""

from __future__ import annotations

import hashlib
import json
import logging
import time
from typing import List, Optional

import httpx
from openai import AsyncOpenAI

from app.core import metrics
from app.core.config import get_settings

logger = logging.getLogger(__name__)
settings = get_settings()


class LlmService:
    """Wraps the OpenAI-compatible client with fallback, caching, and metrics."""

    def __init__(self, redis_client=None):
        self._client: Optional[AsyncOpenAI] = None
        self._http_client: Optional[httpx.AsyncClient] = None
        self._redis = redis_client

    @property
    def is_configured(self) -> bool:
        return bool(settings.DEEPSEEK_API_KEY)

    async def startup(self) -> None:
        if not self.is_configured:
            logger.warning("llm.not_configured")
            return
        self._http_client = httpx.AsyncClient(timeout=settings.LLM_TIMEOUT_SEC)
        self._client = AsyncOpenAI(
            api_key=settings.DEEPSEEK_API_KEY,
            base_url=settings.DEEPSEEK_BASE_URL,
            http_client=self._http_client,
        )
        logger.info("llm.configured provider=deepseek model=%s", settings.DEEPSEEK_MODEL)

    async def shutdown(self) -> None:
        if self._http_client:
            try:
                await self._http_client.aclose()
            except Exception:
                pass

    async def chat_completion(
        self,
        messages: List[dict],
        *,
        model: Optional[str] = None,
        temperature: Optional[float] = None,
        max_tokens: Optional[int] = None,
        stream: bool = False,
        use_cache: bool = True,
    ):
        """Call the LLM with graceful degradation and optional caching.

        Raises LlmUnavailableError if the LLM is not configured and graceful
        degradation is disabled.
        """
        if not self._client:
            if settings.FEATURE_GRACEFUL_DEGRADATION:
                return await self._degraded_response(messages)
            raise LlmUnavailableError("LLM not configured — set DEEPSEEK_API_KEY")

        target_model = model or settings.DEEPSEEK_MODEL
        target_temp = temperature if temperature is not None else settings.LLM_TEMPERATURE
        target_tokens = max_tokens or settings.LLM_MAX_TOKENS

        # Cache check for non-streaming requests.
        if use_cache and not stream and settings.FEATURE_CACHE_ENABLED and self._redis:
            cached = await self._cache_get(messages, target_model)
            if cached:
                metrics.cache_hits_total.inc()
                logger.info("llm.cache_hit model=%s", target_model)
                return cached
            metrics.cache_misses_total.inc()

        try:
            start = time.monotonic()
            completion = await self._client.chat.completions.create(
                model=target_model,
                messages=messages,
                temperature=target_temp,
                max_tokens=target_tokens,
                stream=stream,
            )
            elapsed = time.monotonic() - start

            if stream:
                return completion

            # Record metrics.
            metrics.llm_inference_duration_seconds.labels(model=target_model).observe(elapsed)
            if completion.usage:
                metrics.llm_tokens_total.labels(model=target_model, type="prompt").inc(
                    completion.usage.prompt_tokens
                )
                metrics.llm_tokens_total.labels(model=target_model, type="completion").inc(
                    completion.usage.completion_tokens
                )

            # Cache the non-streaming result.
            if use_cache and settings.FEATURE_CACHE_ENABLED and self._redis:
                await self._cache_set(messages, target_model, completion)

            return completion

        except Exception as exc:
            metrics.llm_errors_total.labels(
                model=target_model, error_type=exc.__class__.__name__
            ).inc()
            logger.error("llm.call_failed model=%s err=%s", target_model, exc)

            # Try fallback model.
            fallback = settings.DEEPSEEK_FALLBACK_MODEL
            if fallback and fallback != target_model:
                try:
                    logger.info("llm.fallback model=%s → %s", target_model, fallback)
                    return await self.chat_completion(
                        messages, model=fallback, temperature=temperature,
                        max_tokens=max_tokens, stream=stream, use_cache=False,
                    )
                except Exception:
                    pass

            if settings.FEATURE_GRACEFUL_DEGRADATION:
                return await self._degraded_response(messages)
            raise LlmUnavailableError(f"LLM call failed: {exc}") from exc

    async def _degraded_response(self, messages: List[dict]):
        """Return a synthetic response when the LLM is unavailable."""
        last_user_msg = next(
            (m["content"] for m in reversed(messages) if m["role"] == "user"),
            "",
        )
        logger.warning("llm.degraded_response query_len=%d", len(last_user_msg))
        return DegradedCompletion(
            content=(
                "⚠️ Il servizio LLM non è al momento disponibile. "
                "Non posso elaborare la tua richiesta. Riprova tra qualche minuto "
                "o contatta l'amministratore di sistema."
            ),
        )

    async def _cache_key(self, messages: List[dict], model: str) -> str:
        payload = json.dumps({"messages": messages, "model": model}, sort_keys=True)
        return f"llm_cache:{hashlib.sha256(payload.encode()).hexdigest()[:16]}"

    async def _cache_get(self, messages: List[dict], model: str):
        try:
            key = await self._cache_key(messages, model)
            raw = await self._redis.get(key)
            if raw:
                return DegradedCompletion(content=raw.decode("utf-8"), cached=True)
        except Exception:
            pass
        return None

    async def _cache_set(self, messages: List[dict], model: str, completion) -> None:
        try:
            content = completion.choices[0].message.content or ""
            key = await self._cache_key(messages, model)
            await self._redis.setex(key, settings.FEATURE_CACHE_TTL_SEC, content)
        except Exception:
            pass


class LlmUnavailableError(Exception):
    """Raised when the LLM is not reachable and graceful degradation is off."""


class DegradedCompletion:
    """Mock completion object returned during graceful degradation."""

    def __init__(self, content: str = "", cached: bool = False):
        self.content = content
        self.cached = cached
        self.usage = None
        self.choices = [
            type("Choice", (), {"message": type("Message", (), {"content": content})})()
        ]
