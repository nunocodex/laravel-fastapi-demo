"""Pydantic models shared across the FastAPI application."""

from __future__ import annotations

import uuid as _uuid
from datetime import datetime, timezone
from typing import List, Optional

from pydantic import BaseModel, Field, field_validator


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str
    timestamp: str
    dependencies: dict


class AnalysisRequest(BaseModel):
    document_id: int = Field(..., gt=0)
    prompt_template: str = Field(..., min_length=1)


class AnalysisAck(BaseModel):
    task_uuid: str
    document_id: int
    prompt_template: str
    received_at: str


class CallbackPayload(BaseModel):
    status: str
    result: dict
    metadata: Optional[dict] = None
    error: Optional[str] = None


class ChatRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=4096)
    session_id: Optional[str] = Field(default=None, max_length=64)
    document_id: Optional[str] = Field(default=None, max_length=128)

    @field_validator("session_id")
    @classmethod
    def _validate_session_id(cls, value: Optional[str]) -> Optional[str]:
        if value is None:
            return value
        try:
            _uuid.UUID(value)
        except ValueError as exc:
            raise ValueError("session_id must be a valid UUID") from exc
        return value

    @field_validator("document_id")
    @classmethod
    def _validate_document_id(cls, value: Optional[str]) -> Optional[str]:
        if value == "":
            return None
        return value


class ChatResponse(BaseModel):
    reply: str
    session_id: str
    sources: List[dict]
    model: Optional[str] = None
    retrieved_chunks: int = 0
    prompt_tokens: Optional[int] = None
    completion_tokens: Optional[int] = None
    cached: bool = False


class DocumentOut(BaseModel):
    document_id: str
    filename: str
    num_chunks: int
    created_at: str
    chunk_size: int
    chunk_overlap: int
    embedding_model: str
    embedding_dim: int
    collection_name: str
