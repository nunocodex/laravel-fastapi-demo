# AGENTS.md

Operational notes for agents working in this repo. Keep it terse; this file is not a tutorial.

## What this repo is

Multi-service Docker Compose stack: **Laravel 13** (PHP-FPM + nginx, business orchestrator) + **FastAPI** (AI/vector engine) + Postgres 17, Redis 7, Qdrant 1.18, on a shared `ai_enterprise_network` bridge. See `README.md` for the architecture diagram.

```
laravel-fastapi-demo/
├── docker-compose.yml              # all 6 services
├── docker-compose.override.yml.example
├── .env.example                    # cross-service defaults
├── laravel-orchestrator/           # Laravel 13 app (source tracked, vendor/ in image)
├── fastapi-engine/                 # Python 3.13 app
├── docs/INSTALL.md, USAGE.md, SECURITY.md
└── docker-compose/postgres/init.sql
```

## Laravel source is tracked; `vendor/` is built in the image

The Laravel skeleton (`app/`, `routes/`, `config/`, `database/`, `resources/`, `tests/`, `composer.json`, `composer.lock`, `artisan`, `public/index.php`) is committed. `vendor/`, `node_modules/`, `storage/framework/*`, `storage/logs/*`, `bootstrap/cache/*.php`, and the generated `.env` are gitignored. The Dockerfile runs `composer install --no-dev` to populate `vendor/`. The container's `entrypoint.sh` copies `laravel-orchestrator/.env.example` → `.env` on first boot and runs `php artisan key:generate --force` if `APP_KEY=` is empty.

There is **no** `scripts/install-laravel.sh` runtime step. To reset the framework source after a bad edit, re-clone (or use git) — do not introduce a runtime composer create step.

## Host environment

- **WSL 2 is the supported dev target** (Windows + Docker Desktop with WSL2 engine, or any Linux). Clone into the WSL ext4 filesystem (`~/projects/`), **not** `/mnt/c/`.
- **Docker daemon access from WSL2:** if you see `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`, your user is not in the `docker` group. Run `sudo usermod -aG docker $USER`, restart Docker Desktop, then reopen the WSL shell (or `newgrp docker`). The TCP-export fallback (`DOCKER_HOST=tcp://localhost:2375`, requires the matching toggle in Docker Desktop → Settings → Advanced) is documented in `docs/INSTALL.md` §1.2 and §Troubleshooting.
- All commands in `docs/*.md` are bash. Do not invoke shell scripts from PowerShell.
- `.gitattributes` forces LF on `*.sh`, `Dockerfile`, `*.yml`, `*.json`, `*.md`. Git config: `git config core.autocrlf input` inside WSL.

## Dev commands

```bash
cp .env.example .env
docker compose up -d --build        # first start
docker compose up -d                # subsequent starts
docker compose down                 # stop
docker compose down -v              # stop + drop named volumes (pgdata, redisdata, qdrantdata)

# Laravel CLI (always inside the container)
docker compose exec ai_laravel_app php artisan migrate --force
docker compose exec ai_laravel_app php artisan tinker
docker compose exec ai_laravel_app composer require vendor/package
docker compose exec ai_laravel_app php artisan key:generate    # if APP_KEY is missing
docker compose exec ai_laravel_app php artisan queue:failed
docker compose exec ai_laravel_app php artisan queue:retry all

# Logs
docker compose logs -f ai_laravel_app
docker compose logs -f ai_laravel_worker
docker compose logs -f ai_fastapi_engine

# Smoke tests
curl http://localhost:8000/         # Laravel
curl http://localhost:8000/up       # Laravel health
curl http://localhost:8001/         # FastAPI health
curl http://localhost:6333/collections
docker compose exec ai_redis redis-cli ping
```

### Service ports (host → container)

| Service              | Host port(s)        | Container port(s) |
|----------------------|---------------------|-------------------|
| `ai_laravel_app`     | 8000, 8080          | 80, 8080          |
| `ai_laravel_worker`  | —                   | — (no ports)      |
| `ai_fastapi_engine`  | 8001                | 8000              |
| `ai_postgres`        | 5432                | 5432              |
| `ai_redis`           | 6379                | 6379              |
| `ai_qdrant`          | 6333, 6334          | 6333, 6334        |

`ai_laravel_worker` overrides the image's `entrypoint`/`command` to run `php artisan queue:work --verbose --tries=3 --timeout=90`; it has no HTTP port.

## FastAPI engine

- Single file: `fastapi-engine/app/main.py`. There is no `app/api/` package, no tests, no separate router. Add code here.
- Dependencies pinned in `fastapi-engine/requirements.txt`: `fastapi==0.137.2`, `uvicorn==0.49.0`, `pydantic==2.13.4`, `httpx==0.28.1`, `qdrant-client==1.18.0`, `redis==5.0.8`. (`redis==8.0.0` is a non-existent version — never reintroduce it.)
- Run locally without Docker: `pip install -r requirements.txt && uvicorn app.main:app --reload --port 8000`. Required env: `QDRANT_HOST`, `REDIS_HOST` (defaults point at the compose service names).
- The engine POSTs completion callbacks to `LARAVEL_BASE_URL` (default `http://ai_laravel_app`) at path `/api/ai-callback/{task_uuid}` with HMAC headers `X-AI-Signature`, `X-AI-Timestamp`, `X-AI-Engine`. The Laravel side is implemented at `app/Http/Controllers/Api/AiCallbackController.php`, with the model at `app/Models/AiTask.php` and migration `database/migrations/2026_06_19_000001_create_ai_tasks_table.php`. Run `php artisan migrate` to create the table.
- Laravel owns the task lifecycle via `POST /api/ai-tasks` (`app/Http/Controllers/Api/AiTaskController.php`). It creates an `ai_tasks` row, calls the FastAPI engine synchronously, and returns `202` with the `task_uuid`. The engine then POSTs the completion to the callback route, which updates the row. If you hit FastAPI directly without first creating a row on the Laravel side, the callback will return 404 `task_not_found`.
- The matching Laravel-side env is `FASTAPI_INTERNAL_URL` (default `http://ai_fastapi_engine:8000`). The two services use different env names to reach each other.
- Endpoints: `GET /` (health, pings Qdrant + Redis), `POST /api/v1/analyze/{task_uuid}` (validates UUID, runs fake retrieval+inference, posts callback, returns 202), `POST /api/v1/chat` (RAG chat), `POST /api/v1/chat/stream` (SSE streaming chat), `GET /api/v1/stats` (system stats), `POST /api/v1/documents` (upload), `GET /api/v1/documents` (list), `DELETE /api/v1/documents/{id}` (delete).
- Qdrant/Redis clients are created in the FastAPI `lifespan` context manager and stored on `app.state`. Healthcheck reads them from there.

## Laravel orchestrator

- **Laravel 13.x** (upgraded 2026-06-20 from 12.x). Minimum PHP 8.3. The image is `php:8.4-fpm` (Debian bookworm) plus `nginx`, `composer`, and the PHP extensions `pdo_pgsql pgsql bcmath gd zip pcntl opcache intl` plus PECL `redis`.
- **Livewire 4.x** — the demo UI at `/demo` is a full-page Livewire component (`app/Livewire/Demo.php` + `resources/views/livewire/demo.blade.php`). The layout is at `resources/views/layouts/app.blade.php` (created by `php artisan livewire:layout`).
- **Vite 6 + Tailwind CSS 4** — frontend assets are built via `npm run build` on the host WSL. Entry points: `resources/css/app.css`, `resources/css/demo.css`, `resources/js/app.js`. Vite config: `laravel-orchestrator/vite.config.js`.
- **Pest 4.x** for testing (PHPUnit 12 compat). Tests live in `tests/`.
- **league/commonmark** for server-side Markdown rendering in chat replies.
- Nginx config is baked in from `laravel-orchestrator/.docker/nginx/default.conf` in the Dockerfile — edit there, not inside the running container. The legacy Alpine-based Dockerfile is preserved as `laravel-orchestrator/Dockerfile.alpine.bak` for reference; do not use it for builds.
- `entrypoint.sh` (set as `ENTRYPOINT`) bootstraps `vendor/` if missing, copies `.env.example` to `.env` if missing, generates `APP_KEY` if empty, then `exec`s the CMD (`php-fpm -F` in the web container, overridden to `php artisan queue:work` in the worker).
- `php artisan migrate` is **not** run on first boot. Run it manually after `docker compose up -d`.
- `.env.example` is the only tracked env file. `APP_KEY=` is intentionally blank — `entrypoint.sh` fills it on first boot.

## Environment files

- One root `.env.example` is the single source of truth for cross-service vars. All services in `docker-compose.yml` use `env_file: .env`.
- `laravel-orchestrator/.env.example` is also committed; it is the Laravel-specific copy that the image's `entrypoint.sh` falls back to. Keep the cross-service keys (`POSTGRES_*`, `REDIS_*`, `REVERB_*`, `FASTAPI_INTERNAL_URL`, `CALLBACK_HMAC_SECRET`) in sync between the two.
- Never commit `.env`, `*.key`, `*.pem`, `*.crt`, `auth.json` (see `.gitignore`).
- Production override goes in an untracked `docker-compose.override.yml` (template in `docker-compose.override.yml.example`); see `docs/USAGE.md` §10.

## CI

`.github/workflows/ci.yml` runs on PRs and `main`:

1. **lint** — `ruff check fastapi-engine` (pinned `ruff==0.5.0`), `hadolint` (both Dockerfiles), `docker compose config -q`.
2. **gitleaks** — secret scan (full git history).
3. **docker build** — builds both images and runs a Laravel boot test (`php artisan --version`, `php artisan route:list`) and a FastAPI import test.

There is no PHP test job beyond the artisan boot smoke test. There is no automated test suite in the repo (no Pest/PHPUnit specs, no pytest). `tests/` exists in the skeleton and is gitignored.

## Conventions and gotchas

- **Do not commit Laravel build artifacts.** `vendor/`, `node_modules/`, `public/storage`, `public/build`, `storage/framework/*`, `bootstrap/cache/*.php`, and the generated `.env` are all gitignored.
- **Run Laravel artisan inside the container**, never on the host — host PHP versions and extensions will not match the image, and `.env` is image-side.
- **Base image: `php:8.4-fpm` (Debian bookworm).** Migrated 2026-06-19 from `php:8.4-fpm-alpine` to align with enterprise security standards (faster CVE patching, more predictable release cadence) while keeping runtime performance identical. System packages are installed via `apt-get`; no C toolchain is needed at build or runtime because Debian-slim ships precompiled PHP extension binaries. Add new extensions with `docker-php-ext-install` (bundled) or `pecl install` (PECL). Ignore older docs/snippets that mention `apk`, `musl`, or Alpine. The legacy Alpine Dockerfile is preserved as `laravel-orchestrator/Dockerfile.alpine.bak` for reference only.
- **Nginx config edits:** change `laravel-orchestrator/.docker/nginx/default.conf`, then `docker compose build --no-cache ai_laravel_app && docker compose up -d`. There is no volume mount for the config.
- **Storage permissions on WSL:** if you see `Permission denied` on `storage/` or `bootstrap/cache/`, run `docker compose exec ai_laravel_app chown -R www-data:www-data storage bootstrap/cache`. The image no longer chmods these at build time. On Windows bind mounts (`C:\`, `/mnt/c/`) `chown` cannot change ownership, so `entrypoint.sh` auto-detects this situation and runs php-fpm as root (with the `-R` flag) as a dev workaround. For the cleanest experience, clone into WSL ext4 (`~/projects/`).
- **Boot sequence is fully automated.** Both `entrypoint.sh` (web) and `entrypoint-worker.sh` (queue) run a self-bootstrapping sequence on every container start: copy `.env` from `.env.example` if missing, run `composer install` if `vendor/` is missing (the bind mount overwrites build-time vendor/), generate `APP_KEY` if empty, wait for Postgres + Redis, run `php artisan migrate --force`. The whole stack is a single-command bring-up: `docker compose up -d --build`. There is no manual `php artisan migrate` step. Both containers' healthchecks depend on this working (`php artisan --version` for the web, a one-shot `queue:work` for the worker).
- **Port already in use on Windows:** `wsl hostname -I` then try the WSL IP; or stop the conflicting process. Docker Desktop forwards `localhost` to the WSL2 engine.
- **Callback security:** the FastAPI→Laravel callback is HMAC-signed via `CALLBACK_HMAC_SECRET`. Rotate the secret on both sides together. If the Laravel container returns 401, check that both env files carry the same value.
- **Port publishing for production:** drop the public mappings for `ai_postgres`, `ai_redis`, and `ai_qdrant` (override compose file) and set `APP_DEBUG=false`. Do not expose `:8001` to the public internet — use the internal `ai_enterprise_network`.
- **Qdrant anonymous access:** the stock image has no auth. For non-local use, set `QDRANT__SERVICE__API_KEY` and put it behind TLS.

## Where to look first when something breaks

| Symptom                                              | First check                                                   |
|------------------------------------------------------|---------------------------------------------------------------|
| `permission denied ... unix:///var/run/docker.sock`   | `sudo usermod -aG docker $USER` + restart Docker Desktop + reopen WSL shell. See `docs/INSTALL.md` §1.2. |
| `docker compose up` fails on `ai_laravel_app`         | `docker compose logs ai_laravel_app` — usually permissions on `storage/` or a missing entrypoint line |
| Laravel 500 / blank page                              | `docker compose logs ai_laravel_app`; check `APP_KEY` in `laravel-orchestrator/.env` inside the container |
| `composer` errors during build                        | `docker compose build --no-cache ai_laravel_app` and inspect the failed `composer install` step |
| `redis==8.0.0` not found on a fresh install          | You reverted a real fix — `redis==5.0.8` is the current pin. |
| FastAPI healthcheck says `down` for qdrant/redis     | The container is down or unreachable on the bridge network |
| FastAPI callback returns 401 (`invalid_signature`)   | `CALLBACK_HMAC_SECRET` mismatch between root `.env` and `laravel-orchestrator/.env`. |
| FastAPI callback returns 404 (`task_not_found`)      | The `ai_tasks` row was not created — Laravel did not enqueue a task. Check `php artisan queue:work` logs. |
| `touch(): Utime failed: Operation not permitted`     | Windows bind-mount ownership. `entrypoint.sh` auto-detects this and runs php-fpm as root with `-R`; rebuild/restart `ai_laravel_app`. |
| Slow filesystem on Windows                           | Move the clone out of `/mnt/c/` into `~/projects/` (WSL ext4) |
