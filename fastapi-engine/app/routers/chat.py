"""Chat / RAG endpoint with multi-turn conversation support."""

from __future__ import annotations

import json
import uuid
from typing import List, Optional

from fastapi import APIRouter, HTTPException, Request
from fastapi.responses import StreamingResponse

from app.core import metrics
from app.core.config import get_settings
from app.models import ChatRequest, ChatResponse
from app.services.bulkhead import BulkheadFullError, BulkheadName

router = APIRouter(prefix="/api/v1/chat", tags=["chat"])
settings = get_settings()


def _session_key(session_id: str) -> str:
    return f"chat:{session_id}"


async def load_history(redis_client, session_id: str) -> List[dict]:
    key = _session_key(session_id)
    items = await redis_client.lrange(key, -settings.MAX_HISTORY, -1)
    return [json.loads(x) for x in items]


async def append_history(redis_client, session_id: str, role: str, content: str):
    key = _session_key(session_id)
    await redis_client.rpush(key, json.dumps({"role": role, "content": content}))
    await redis_client.ltrim(key, -settings.MAX_HISTORY, -1)


async def _search_and_build_context(
    qdrant_svc,
    embedder_svc,
    query: str,
    document_id: Optional[str],
) -> tuple[str, List[dict]]:
    query_vector = (await embedder_svc.embed([query]))[0]
    hits = await qdrant_svc.search_raw(
        query_vector,
        settings.COLLECTION_NAME,
        document_id=document_id,
        top_k=settings.TOP_K,
    )

    context_blocks = []
    sources = []
    for idx, hit in enumerate(hits):
        text = hit.payload.get("text", "")
        context_blocks.append(f"[PASSAGGIO {idx + 1}]\n{text}")
        sources.append({
            "document_id": hit.payload.get("document_id"),
            "filename": hit.payload.get("filename"),
            "chunk_index": hit.payload.get("chunk_index"),
            "score": hit.score,
            "text": text[:300],
        })

    context = "\n\n".join(context_blocks) if context_blocks else "Nessun contesto disponibile."
    return context, sources


async def _build_messages(
    redis_client,
    session_id: str,
    query: str,
    context: str,
) -> List[dict]:
    system_prompt = (
        "Sei un assistente esperto. Rispondi in italiano usando esclusivamente il contesto fornito. "
        "Se il contesto non contiene la risposta, dillo chiaramente. "
        "Cita i passaggi rilevanti quando possibile."
    )
    messages = [{"role": "system", "content": system_prompt}]
    messages.extend(await load_history(redis_client, session_id))
    messages.append({
        "role": "user",
        "content": f"Contesto:\n{context}\n\nDomanda: {query}",
    })
    return messages


@router.post("", response_model=ChatResponse)
async def chat(request: Request, req: ChatRequest):
    llm_svc = request.app.state.llm
    if not llm_svc.is_configured and not settings.FEATURE_GRACEFUL_DEGRADATION:
        raise HTTPException(status_code=503, detail="LLM not configured: set DEEPSEEK_API_KEY")

    qdrant_svc = request.app.state.qdrant
    embedder_svc = request.app.state.embedder
    redis_client = request.app.state.redis

    session_id = req.session_id or str(uuid.uuid4())
    query = req.message.strip()

    # Vector search.
    try:
        context, sources = await _search_and_build_context(
            qdrant_svc, embedder_svc, query, req.document_id,
        )
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"Vector search failed: {exc}") from exc

    messages = await _build_messages(redis_client, session_id, query, context)

    # Bulkhead isolation: cap concurrent LLM calls.
    bulkhead = request.app.state.bulkheads.get(BulkheadName.CHAT)
    try:
        async with bulkhead.acquire():
            completion = await llm_svc.chat_completion(messages, use_cache=True)
    except BulkheadFullError:
        raise HTTPException(
            status_code=429,
            detail="Too many concurrent requests. Please retry shortly.",
        )

    reply = (
        completion.choices[0].message.content
        if not isinstance(completion, type)
        else completion.content
    )
    reply = reply or "Nessuna risposta generata."

    await append_history(redis_client, session_id, "user", query)
    await append_history(redis_client, session_id, "assistant", reply)

    usage = completion.usage if hasattr(completion, "usage") else None
    is_cached = getattr(completion, "cached", False)

    return ChatResponse(
        reply=reply,
        session_id=session_id,
        sources=sources,
        model=settings.DEEPSEEK_MODEL,
        retrieved_chunks=len(sources),
        prompt_tokens=usage.prompt_tokens if usage else None,
        completion_tokens=usage.completion_tokens if usage else None,
        cached=is_cached,
    )


@router.post("/stream")
async def chat_stream(request: Request, req: ChatRequest):
    llm_svc = request.app.state.llm
    if not llm_svc.is_configured:
        raise HTTPException(status_code=503, detail="LLM not configured: set DEEPSEEK_API_KEY")

    qdrant_svc = request.app.state.qdrant
    embedder_svc = request.app.state.embedder
    redis_client = request.app.state.redis

    session_id = req.session_id or str(uuid.uuid4())
    query = req.message.strip()

    try:
        context, sources = await _search_and_build_context(
            qdrant_svc, embedder_svc, query, req.document_id,
        )
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"Vector search failed: {exc}") from exc

    messages = await _build_messages(redis_client, session_id, query, context)

    async def event_stream():
        completion = await llm_svc._client.chat.completions.create(
            model=settings.DEEPSEEK_MODEL,
            messages=messages,
            temperature=settings.LLM_TEMPERATURE,
            max_tokens=settings.LLM_MAX_TOKENS,
            stream=True,
        )
        full_reply = ""
        async for chunk in completion:
            delta = chunk.choices[0].delta
            content = delta.content or ""
            if content:
                full_reply += content
                yield f"data: {json.dumps({'chunk': content})}\n\n"
        yield f"data: {json.dumps({'done': True, 'session_id': session_id, 'sources': sources, 'sources_count': len(sources)})}\n\n"

        await append_history(redis_client, session_id, "user", query)
        await append_history(redis_client, session_id, "assistant", full_reply)

    return StreamingResponse(event_stream(), media_type="text/event-stream")
