# Installation Guide

This document covers everything required to install and run `laravel-fastapi-demo` on a Windows workstation with **WSL 2** enabled. macOS and Linux users can follow the same steps verbatim.

All commands below are bash and **must be run inside the WSL 2 distribution** (Ubuntu 22.04+ recommended), not in PowerShell or Windows cmd. This avoids the well-known Docker Desktop / WSL2 file-sharing performance trap and the CRLF / line-ending bugs that surface when mounting bind volumes from the Windows filesystem.

## 1. Prerequisites

### 1.1 On Windows

1. **Install WSL 2** with a default Ubuntu distribution:
   ```powershell
   wsl --install
   wsl --set-default-version 2
   ```
2. **Install Docker Desktop for Windows** and enable:
   - *Use WSL 2 based engine* (Settings → General).
   - *Use the WSL 2 based engine* integration with your distro (Settings → Resources → WSL Integration → enable for Ubuntu).
3. **Confirm the WSL integration**: in Settings → Resources → WSL Integration, your Ubuntu distro must be listed and toggled on. By default it is, but it is worth verifying.
4. Restart Docker Desktop. Verify the WSL engine is active in the bottom-left status bar.

### 1.2 Inside WSL 2 (Ubuntu)

Open the Ubuntu app (or `wsl -d Ubuntu` from PowerShell) and install the toolchain:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git curl unzip ca-certificates build-essential

# Docker CLI inside WSL talks to the Docker Desktop engine via the
# /var/run/docker.sock socket exposed by the WSL integration.
docker --version
docker compose version
```

#### WSL2 socket permission

On a fresh Docker Desktop install, `/var/run/docker.sock` is mounted in your WSL distro but your user is not in the `docker` group, so every `docker` command fails with `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`. Add yourself to the group, restart Docker Desktop, and reopen the WSL shell:

```bash
sudo usermod -aG docker $USER
# Restart Docker Desktop from the system tray, then:
newgrp docker   # or close and reopen the WSL shell
docker info | head -5
```

If the socket is not exposed at all, enable the distro in Docker Desktop → Settings → Resources → WSL Integration, or fall back to the TCP daemon (see the `docker compose not recognized` block below).

If `docker compose` is not recognized, open Docker Desktop → Settings → *Expose daemon on tcp://localhost:2375 without TLS* and add to your WSL shell profile:

```bash
echo 'export DOCKER_HOST=tcp://localhost:2375' >> ~/.bashrc
source ~/.bashrc
```

### 1.3 Toolchain versions

| Tool             | Version            | Notes                                                       |
|------------------|--------------------|-------------------------------------------------------------|
| Docker Desktop   | 4.x (current)      | Must be running, with the WSL 2 engine active.              |
| Docker Compose   | v2 (`docker compose`) | Bundled with Docker Desktop 4.x+.                    |
| WSL 2 kernel     | 5.15+              | Updated by Windows Update.                                  |
| Git              | 2.40+              | Install inside WSL: `sudo apt install -y git`.              |

Composer and PHP are **not required on the host** — both images install their own toolchains.

## 2. Clone the repository inside WSL

**Clone into the WSL ext4 filesystem** (e.g. `~/projects/`), not into the Windows mount (`/mnt/c/...`). Cross-filesystem bind mounts are slow and produce permission issues with Docker.

```bash
mkdir -p ~/projects && cd ~/projects
git clone https://github.com/<your-org>/laravel-fastapi-demo.git
cd laravel-fastapi-demo
```

## 3. Configure environment variables

```bash
cp .env.example .env
```

The default `.env` is safe for local development. **Do not commit it** — `.env` is ignored by `.gitignore`. For production, inject values through your CI/CD secrets manager (see `docs/SECURITY.md`).

Both `.env.example` files (root and `laravel-orchestrator/.env.example`) share the cross-service variables (Postgres, Redis, Reverb, FastAPI URL, HMAC secret). Edit the root `.env` for compose-level values; the Laravel image picks up its own copy from `laravel-orchestrator/.env` (created on first boot by `entrypoint.sh`).

## 4. Build and launch the stack

The whole stack is **fully self-bootstrapping on first boot**. A single command brings everything up, including PHP dependencies, application key, database migrations, and queue workers:

```bash
docker compose up -d --build
```

On first boot, both `ai_laravel_app` and `ai_laravel_worker` run an entrypoint that:

1. Copies `laravel-orchestrator/.env` from `.env.example` if missing.
2. Runs `composer install` if `vendor/` is missing (the bind mount overwrites build-time `vendor/` on every container start, so this check is what makes the bind-mount workflow work).
3. Generates `APP_KEY` if it is still empty.
4. Waits for Postgres and Redis to be reachable (up to 60 s).
5. Runs `php artisan migrate --force` (idempotent — no-op when there are no pending migrations).
6. Starts nginx (web only) and `php-fpm` / `php artisan queue:work`.

There is **no** manual `php artisan migrate` step. The migration runs on every container start, and `migrate` is a no-op when the database is already up to date. If you want to re-seed:

```bash
docker compose exec ai_laravel_app php artisan db:seed --force
```

| Service                | Host port | Container port |
|------------------------|-----------|----------------|
| `ai_laravel_app`       | 8000      | 80             |
| `ai_laravel_app`       | 8080      | 8080 (Reverb)  |
| `ai_laravel_worker`    | —         | —              |
| `ai_fastapi_engine`    | 8001      | 8000           |
| `ai_postgres`          | 5432      | 5432           |
| `ai_redis`             | 6379      | 6379           |
| `ai_qdrant`            | 6333 / 6334 | 6333 / 6334 |

> **Port publishing on WSL2:** when you hit `http://localhost:8000` from Windows, the request goes to the WSL2 localhost which Docker Desktop forwards to the container. If the page does not load, check `wsl hostname -I` and use that IP instead, or open *Settings → Resources → WSL Integration* in Docker Desktop.

## 5. Smoke test

```bash
curl http://localhost:8000/                          # Laravel welcome
curl http://localhost:8000/up                        # Laravel health endpoint
curl http://localhost:8001/                          # FastAPI health
curl http://localhost:6333/collections               # Qdrant
docker compose exec ai_redis redis-cli ping
```

## 7. End-to-end callback smoke test

The Laravel orchestrator owns the task lifecycle. Drive it via `POST /api/ai-tasks`; the row is created in `ai_tasks`, dispatched to the FastAPI engine, and updated when the engine's callback lands.

```bash
RESP=$(curl -fsS -X POST http://localhost:8000/api/ai-tasks \
  -H "Content-Type: application/json" \
  -d '{"document_id": 1, "prompt_template": "smoke"}')
echo "$RESP"
TASK=$(echo "$RESP" | python -c "import sys, json; print(json.load(sys.stdin)['task_uuid'])")
sleep 1
docker compose exec ai_laravel_app php artisan tinker --execute="echo \App\Models\AiTask::where('task_uuid','$TASK')->first()?->status;"
```

You should see `completed` (or `failed` if the engine errored or the callback was rejected for some reason).

If you want to drive the engine directly (skipping the Laravel dispatcher), POST to `http://localhost:8001/api/v1/analyze/{uuid}`. The engine will still try to callback to `/api/ai-callback/{uuid}` on Laravel — if no row exists, the callback returns 404 `task_not_found`.

## 8. Shutdown

```bash
docker compose down                 # stop services
docker compose down -v              # stop + remove named volumes
```

## Troubleshooting

- **`permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`** — Every `docker` command prompts for `sudo`. Docker Desktop is exposing the socket in WSL but your user is not in the `docker` group. The recommended fix is one-time and permanent:
  ```bash
  sudo usermod -aG docker $USER
  # Restart Docker Desktop from the system tray, then reopen the WSL shell
  newgrp docker
  docker info | head -5
  ```
  The `sudo docker compose …` workaround works but is not recommended for daily use (it leaves bind-mounted files root-owned and breaks `storage/`/`bootstrap/cache/` ownership in the Laravel container).
  As a fallback, route the client through TCP: Docker Desktop → Settings → Advanced → "Expose daemon on `tcp://localhost:2375` without TLS" → Apply & restart, then in WSL `echo 'export DOCKER_HOST=tcp://localhost:2375' >> ~/.bashrc && source ~/.bashrc`.

- **`docker compose` not found** — Update Docker Desktop or set `DOCKER_HOST` as shown in §1.2.
- **`APP_KEY` still empty after first boot** — `docker compose exec ai_laravel_app php artisan key:generate`.
- **Permission errors on `storage/` or `bootstrap/cache/`** — `docker compose exec ai_laravel_app chown -R www-data:www-data storage bootstrap/cache` (or `docker compose down -v` and rebuild with a clean named volume).
- **Port already in use** — Edit `docker-compose.yml` port mappings or stop the conflicting process.
- **Stale containers after refactor** — `docker compose down --remove-orphans && docker compose up -d --build`.
- **Repository cloned under `/mnt/c/` is slow** — Move it to `~/projects/` inside the WSL filesystem and re-clone.
- **Line-ending / CRLF warnings from Git** — `git config core.autocrlf input` inside WSL.
- **HMAC signature rejected (`invalid_signature` from `/api/ai-callback`)** — The root `.env` and `laravel-orchestrator/.env` (generated from `.env.example`) must contain the same `CALLBACK_HMAC_SECRET`. If you changed one, change the other.
