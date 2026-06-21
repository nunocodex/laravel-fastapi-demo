# Security Guide

This document is the security baseline that must be met before publishing the repository or deploying the stack in any non-throwaway environment.

## 1. Secrets management

- **Never commit secrets.** The repository `.gitignore` excludes `.env`, `*.key`, `*.pem`, `*.crt`, and `auth.json`. A `gitleaks` workflow runs under `.github/workflows/` to enforce this in CI.
- Use the committed `.env.example` files as **templates only**. Copy to `.env` locally and never commit the copy.
- For CI/CD, inject secrets via GitHub Actions Secrets, HashiCorp Vault, AWS Secrets Manager, Doppler, or your platform's native secret store. Reference them from a `docker-compose.override.yml` that is **not** committed.
- Rotate the following before any public deploy: `APP_KEY`, `POSTGRES_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, and `CALLBACK_HMAC_SECRET`.

## 2. Repository hardening

| Control                      | Status  | Where                                    |
|------------------------------|---------|------------------------------------------|
| Default branch protection    | Manual  | Repo Settings → Branches                 |
| Required signed commits      | Manual  | Repo Settings → Branches                 |
| Secret scanning (push protection) | Manual | Repo Settings → Code security        |
| Gitleaks CI scan             | Enabled | `.github/workflows/ci.yml`               |
| Dependabot                   | Manual  | Enable in Repo Settings → Code security  |
| CODEOWNERS                   | Manual  | Create `.github/CODEOWNERS`              |

Recommended GitHub branch protection rules on `main`:

- Require pull request reviews before merging (≥ 1 approving review).
- Require status checks to pass: `lint`, `gitleaks`, `docker build`.
- Require linear history.
- Include administrators.

## 3. Container security

- All images are pinned to specific versions (`postgres:17-alpine`, `redis:7-alpine`, `qdrant/qdrant:v1.18.2`, `php:8.4-fpm`, `python:3.13-slim`).
- `.dockerignore` files prevent `vendor/`, `node_modules/`, `.env`, `__pycache__/`, `.git/`, and IDE folders from being copied into the build context.
- The Laravel image runs as the default `www-data` user for the PHP-FPM master; nginx still launches as root inside the container. Add a `USER` directive if you need to harden further.
- No host `docker.sock` is mounted into any container.

## 4. Network security

- All services live on an internal bridge network (`ai_enterprise_network`).
- For production, remove the public port mappings for `ai_postgres`, `ai_redis`, and `ai_qdrant` (keep them reachable only from within the network). Override via `docker-compose.override.yml` or a separate prod compose file.
- The FastAPI engine and the Laravel app should communicate over the internal network only. Avoid exposing `:8001` to the public internet.

## 5. Application security

- **Laravel**:
  - Keep `APP_DEBUG=false` in production. `debug=true` exposes stack traces and environment values.
  - Rotate `APP_KEY` for every environment.
  - Ensure CSRF, session, and CORS middleware are configured (`config/cors.php`).
  - The `/api/ai-callback/{task_uuid}` route is HMAC-protected: the engine sends `X-AI-Signature` = `HMAC-SHA256(secret, task_uuid|X-AI-Timestamp)`. Rotate `CALLBACK_HMAC_SECRET` on both sides together.
- **FastAPI**:
  - Validate every payload with Pydantic (in place for `AnalysisRequest` and `CallbackPayload`).
  - All inter-service calls are made over the internal Docker network. Do not publish `:8001`.
- **PostgreSQL / Redis / Qdrant**:
  - Change `db_password` and any default credentials before deploying.
  - Postgres listens only on the internal network in production; do not publish 5432.
  - Redis has no password by default — set `requirepass` in `command:` for production.
  - Qdrant ships with anonymous access — set an API key via `QDRANT__SERVICE__API_KEY` and an HTTPS reverse proxy in front.

## 6. Logging and observability

- Logs are written to `storage/logs/` inside Laravel and to stdout for every container.
- Do not log secrets, PII, or full request bodies. Add a redaction middleware if you centralize logs.
- Ship container logs to a central aggregator (Loki, ELK, Datadog) for retention and alerting.

## 7. CI / supply chain

`.github/workflows/ci.yml` runs on every push and pull request:

1. **lint** — `ruff check fastapi-engine` (Python), `hadolint` (Dockerfiles), `docker compose config -q` (compose file validation).
2. **gitleaks** — secret scan (full git history).
3. **docker build** — builds both images and runs a Laravel boot smoke test (`php artisan --version`, `php artisan route:list`) and a FastAPI import smoke test.

Before publishing, also:

- Enable Dependabot security updates for `requirements.txt` and `composer.json`.
- Add SBOM generation (`docker sbom`) and vulnerability scanning (`trivy`, `grype`) as follow-up CI jobs.

## 8. Reporting vulnerabilities

Please open a private security advisory (GitHub Security Advisories) instead of a public issue. Do not disclose vulnerabilities publicly until a fix is released.
