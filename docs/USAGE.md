# Usage Guide

All commands below are bash and assume you are running inside the WSL 2 distribution where the project is checked out (see `docs/INSTALL.md`). The Docker CLI inside WSL 2 talks to the Docker Desktop engine automatically.

## 1. Daily workflow

```bash
# Start the stack (idempotent)
docker compose up -d

# Tail logs
docker compose logs -f ai_laravel_app
docker compose logs -f ai_laravel_worker
docker compose logs -f ai_fastapi_engine

# Stop the stack
docker compose down
```

## 2. Running Artisan / Composer commands

Always run Laravel CLI commands inside `ai_laravel_app` so that the PHP runtime, extensions, and the `.env` from the image are used:

```bash
docker compose exec ai_laravel_app php artisan migrate
docker compose exec ai_laravel_app php artisan tinker
docker compose exec ai_laravel_app composer require vendor/package
```

## 3. Talking to the FastAPI engine

The Laravel orchestrator owns the task lifecycle. Use the Laravel endpoint to create a task; the row is inserted in `ai_tasks`, the request is forwarded to the FastAPI engine, and the row is updated when the engine's callback lands.

```bash
RESP=$(curl -fsS -X POST http://localhost:8000/api/ai-tasks \
  -H "Content-Type: application/json" \
  -d '{"document_id": 42, "prompt_template": "summarize"}')
echo "$RESP"
# {"ok":true,"task_uuid":"...","status":"running","engine_status":202}
```

The Laravel side calls FastAPI via `FASTAPI_INTERNAL_URL` (default `http://ai_fastapi_engine:8000`). The engine returns `202 Accepted` immediately and POSTs the completion back to Laravel at `/api/ai-callback/{task_uuid}` with:

```json
{
  "status": "completed",
  "result": { "document_id": 42, "inference": "...", "context_excerpt": "..." },
  "metadata": { "engine_version": "1.0.0", "completed_at": "..." }
}
```

Laravel verifies an HMAC-SHA256 over `task_uuid|timestamp` using `CALLBACK_HMAC_SECRET` (header `X-AI-Signature`), then updates the `ai_tasks` row.

You can drive the engine directly (skipping Laravel):

```bash
TASK=$(uuidgen)
curl -X POST http://localhost:8001/api/v1/analyze/$TASK \
  -H "Content-Type: application/json" \
  -d '{"document_id": 42, "prompt_template": "summarize"}'
```

In that case the engine still calls back to `/api/ai-callback/{uuid}`. If no `ai_tasks` row exists for the UUID (because Laravel never created one), the callback returns 404 `task_not_found` and the response is logged but otherwise ignored.

## 4. Queue worker

`ai_laravel_worker` runs:

```
php artisan queue:work --verbose --tries=3 --timeout=90
```

To watch a specific job:

```bash
docker compose logs -f ai_laravel_worker
```

To replay failed jobs interactively:

```bash
docker compose exec ai_laravel_app php artisan queue:failed
docker compose exec ai_laravel_app php artisan queue:retry all
```

## 5. Reverb (WebSockets)

Reverb listens on port 8080 inside the Laravel container (mapped to host 8080). The relevant env vars live in `.env`:

```
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http
```

Start it explicitly if it is not running in the same container:

```bash
docker compose exec ai_laravel_app php artisan reverb:start
```

## 6. Postgres & Qdrant

```bash
# Postgres shell
docker compose exec ai_postgres psql -U db_user -d enterprise_core

# Qdrant REST API
curl http://localhost:6333/collections
```

## 7. Inspecting callback deliveries

```bash
# Latest tasks
docker compose exec ai_laravel_app php artisan tinker --execute='print \App\Models\AiTask::latest()->take(5)->get(["task_uuid","status","completed_at"])->toJson(JSON_PRETTY_PRINT);'

# A specific task
docker compose exec ai_laravel_app php artisan tinker --execute='print \App\Models\AiTask::where("task_uuid","<uuid>")->first()->toJson(JSON_PRETTY_PRINT);'
```

## 8. Refreshing PHP dependencies

After editing `composer.json` / `composer.lock`:

```bash
docker compose build --no-cache ai_laravel_app ai_laravel_worker
docker compose up -d
```

## 9. Production checklist

- Set `APP_ENV=production` and `APP_DEBUG=false` in `.env`.
- Provide a strong `APP_KEY` and a non-default `POSTGRES_PASSWORD` and `CALLBACK_HMAC_SECRET` (or, better, inject from a secret store).
- Use `docker-compose.prod.yml` (you provide) to drop public port mappings for Postgres, Redis, and Qdrant. A template is not committed because the production override should be private.
- Place the stack behind a TLS-terminating reverse proxy (Traefik, Caddy, or cloud LB).
- Enable the security baseline described in `docs/SECURITY.md`.

## 10. Local dev hot-reload

For Python hot-reload and host `.env` override:

```bash
cp docker-compose.override.yml.example docker-compose.override.yml
docker compose up -d
```

`docker-compose.override.yml` is gitignored and per-developer.
