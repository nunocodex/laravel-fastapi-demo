# laravel-fastapi-demo

Enterprise prototype integrating **Laravel 13** as a business orchestrator and **FastAPI** as a high-performance AI / vector computation engine. The full stack ships as a multi-service Docker Compose project with PostgreSQL 17, Redis 7, Qdrant 1.18, and Nginx on PHP 8.4 / Python 3.13.

## Architecture

```
                             ┌──────────────────────┐
                             │   Browser / Client   │
                             └──────────┬───────────┘
                                        │
                             ┌──────────▼───────────┐
                             │ Nginx (in Laravel    │
                             │ container) :8000     │
                             └──────────┬───────────┘
                                        │
                             ┌──────────▼───────────┐         async POST callback
                             │  Laravel 13 (PHP-FPM)│ ─────────────────────────┐
                             │  ai_laravel_app      │                          │
                             │  ai_laravel_worker   │                          │
                             └──┬──────────┬────────┘                          │
                                │          │                                   │
                        ┌───────▼──┐   ┌────▼────┐   ┌──────────────┐  ┌───────▼────────┐
                        │ Postgres │   │  Redis  │   │   Qdrant     │  │ FastAPI engine │
                        │   :5432  │   │  :6379  │   │   :6333      │  │   :8001        │
                        └──────────┘   └─────────┘   └──────────────┘  └────────────────┘
```

All services share the `ai_enterprise_network` bridge network.

## Repository layout

```
laravel-fastapi-demo/
├── docker-compose.yml                  # all 6 services
├── docker-compose.override.yml.example # opt-in dev override (--reload, .env bind)
├── .env.example                        # cross-service env defaults
├── docs/
│   ├── INSTALL.md
│   ├── USAGE.md
│   └── SECURITY.md
├── AGENTS.md
├── laravel-orchestrator/               # Laravel 13 app (tracked source)
│   ├── Dockerfile
│   ├── entrypoint.sh                   # APP_KEY bootstrap + nginx boot
│   ├── .docker/nginx/default.conf      # baked into the image (not mounted)
│   └── .env.example                    # Laravel-specific env
├── fastapi-engine/                     # FastAPI app
│   ├── Dockerfile
│   ├── requirements.txt
│   └── app/main.py
└── docker-compose/
    └── postgres/init.sql               # CREATE EXTENSION (uuid-ossp, pgcrypto)
```

The Laravel framework source (`app/`, `routes/`, `database/`, `config/`, `resources/`, `tests/`, `composer.json`, `composer.lock`, `artisan`, `public/index.php`) is **tracked** in the repo. `vendor/`, `node_modules/`, and the generated `.env` are not — they are produced at image build time or first boot. The `laravel-orchestrator/public/` directory is part of the skeleton; it is no longer empty in a fresh clone.

## Quick start (WSL 2 / bash)

```bash
# 1. Inside WSL 2, clone into the ext4 filesystem (NOT /mnt/c/...)
mkdir -p ~/projects && cd ~/projects
git clone https://github.com/<your-org>/laravel-fastapi-demo.git
cd laravel-fastapi-demo

# 2. Copy the cross-service env defaults
cp .env.example .env

# 3. Build and run the stack
docker compose up -d --build

# 4. Run migrations (only needed once per fresh DB volume)
docker compose exec ai_laravel_app php artisan migrate --force

# 5. Verify
curl http://localhost:8000/         # Laravel
curl http://localhost:8001/         # FastAPI engine
curl http://localhost:6333/         # Qdrant
```

> **Windows hosts only:** this project is designed to run inside **WSL 2** with Docker Desktop's WSL 2 engine enabled. Cloning under `/mnt/c/` will work but is significantly slower due to the 9P bind mount.

See `docs/INSTALL.md` for prerequisites, `docs/USAGE.md` for day-to-day operations, and `docs/SECURITY.md` for the security baseline before publishing.

## License

MIT — see `LICENSE`.
