"""Centralised configuration via pydantic-settings.

Reads from environment + .env file. Every setting has a sane default
matching the docker-compose service names so local dev without a .env
file works out of the box.
"""

from __future__ import annotations

import os
from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # ---- App ----
    APP_NAME: str = "ai-fastapi-engine"
    APP_VERSION: str = "2.0.0"
    LOG_LEVEL: str = "INFO"

    # ---- Inter-service networking ----
    LARAVEL_BASE_URL: str = "http://ai_laravel_app"
    LARAVEL_CALLBACK_PATH: str = "/api/ai-callback"

    # ---- Security ----
    CALLBACK_HMAC_SECRET: str = "dev-only-change-me"
    CALLBACK_MAX_RETRIES: int = 5
    CALLBACK_RETRY_BACKOFF_MS: int = 1000
    CALLBACK_CIRCUIT_THRESHOLD: int = 5
    CALLBACK_CIRCUIT_TIMEOUT_SEC: int = 30

    # ---- Qdrant ----
    QDRANT_HOST: str = "ai_qdrant"
    QDRANT_PORT: int = 6333
    QDRANT_TIMEOUT_SEC: int = 10

    # ---- Redis ----
    REDIS_HOST: str = "ai_redis"
    REDIS_PORT: int = 6379
    REDIS_TIMEOUT_SEC: int = 2

    # ---- LLM (DeepSeek / OpenAI-compatible) ----
    DEEPSEEK_API_KEY: str = ""
    DEEPSEEK_BASE_URL: str = "https://api.deepseek.com/v1"
    DEEPSEEK_MODEL: str = "deepseek-chat"
    DEEPSEEK_FALLBACK_MODEL: str = "deepseek-chat-lite"
    LLM_TIMEOUT_SEC: float = 120.0
    LLM_TEMPERATURE: float = 0.3
    LLM_MAX_TOKENS: int = 1024

    # ---- Embedding ----
    EMBEDDING_MODEL: str = "BAAI/bge-small-en-v1.5"
    EMBEDDING_DIM: int = 384

    # ---- RAG parameters ----
    COLLECTION_NAME: str = "documents"
    CHUNK_SIZE: int = 512
    CHUNK_OVERLAP: int = 64
    TOP_K: int = 4
    MAX_HISTORY: int = 10

    # ---- Rate limiting ----
    RATE_LIMIT_CHAT: str = "30/minute"
    RATE_LIMIT_UPLOAD: str = "20/minute"
    RATE_LIMIT_ANALYZE: str = "30/minute"

    # ---- Feature flags ----
    FEATURE_CACHE_ENABLED: bool = True
    FEATURE_CACHE_TTL_SEC: int = 3600
    FEATURE_DLQ_ENABLED: bool = True
    FEATURE_GRACEFUL_DEGRADATION: bool = True

    # ---- Upload limits ----
    MAX_FILE_BYTES: int = 10 * 1024 * 1024  # 10 MB
    ALLOWED_EXTENSIONS: set[str] = {".pdf", ".txt", ".md"}


@lru_cache()
def get_settings() -> Settings:
    return Settings()
