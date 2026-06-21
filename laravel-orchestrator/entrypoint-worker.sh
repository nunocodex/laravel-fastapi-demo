#!/bin/sh
# Worker container entrypoint.
# Mirrors entrypoint.sh but does not start nginx (the worker does not serve
# HTTP). Responsibilities, in order:
#   1. Bootstrap .env from .env.example on first boot.
#   2. Bootstrap vendor/ if missing.
#   3. Generate APP_KEY on first boot if empty.
#   4. Wait for Postgres + Redis (using PHP fsockopen, see entrypoint.sh).
#   5. Run database migrations (idempotent).
#   6. Optionally cache config/routes in production.
#   7. exec the CMD (php artisan queue:work).
set -eu

cd /var/www/html

# 1. .env bootstrap
if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

# 2. vendor/ bootstrap
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint-worker] vendor/ missing, running composer install..."
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
    composer dump-autoload --no-dev --optimize
fi

# 3. APP_KEY bootstrap
if [ -f .env ] && grep -qE '^APP_KEY= *$' .env; then
    if command -v php >/dev/null 2>&1; then
        php artisan key:generate --force --no-interaction || true
    fi
fi

# 4. Wait for dependencies (uses PHP fsockopen, not /dev/tcp, because
# /bin/sh on Debian is dash and /dev/tcp is a bash-only feature).
wait_for() {
    name="$1"; host="$2"; port="$3"
    echo "[entrypoint-worker] waiting for $name at $host:$port..."
    php -r '
        $h = $argv[1]; $p = (int) $argv[2];
        for ($i = 0; $i < 60; $i++) {
            $errno = 0; $errstr = "";
            $fp = @fsockopen($h, $p, $errno, $errstr, 1.0);
            if ($fp) { fclose($fp); echo "ok\n"; exit(0); }
            sleep(1);
        }
        echo "timeout\n"; exit(1);
    ' "$host" "$port" >/dev/null && echo "[entrypoint-worker] $name reachable." || echo "[entrypoint-worker] WARNING: $name not reachable after 60s, continuing anyway."
}

env_value() {
    key="$1"
    if [ -f .env ]; then
        val=$(awk -F= -v k="$key" '
            $1 == k {
                $1=""; sub(/^=/, ""); print
                exit
            }
        ' .env | tr -d ' "\047' | xargs || true)
        if [ -n "$val" ]; then
            printf '%s' "$val"
            return
        fi
    fi
    printf '%s' "$2"
}

pg_host=$(env_value DB_HOST ai_postgres)
pg_port=$(env_value DB_PORT 5432)
redis_host=$(env_value REDIS_HOST ai_redis)
redis_port=$(env_value REDIS_PORT 6379)

wait_for "postgres" "$pg_host" "$pg_port"
wait_for "redis"    "$redis_host" "$redis_port"

# 5. Run database migrations (idempotent: migrate is a no-op when up to date).
if command -v php >/dev/null 2>&1; then
    echo "[entrypoint-worker] running database migrations (idempotent)..."
    php artisan migrate --force --no-interaction || echo "[entrypoint-worker] WARNING: migration failed, continuing."
fi

# 5.5 Detect Windows bind mounts where file ownership cannot be changed.
#     On such mounts, the worker writes to storage/ for framework cache and
#     Laravel's BladeCompiler may fail with "Utime failed: Operation not permitted".
#     Ensure directories are writable by any user on bind mounts.
owner_test="storage/framework/views/.owner_test_worker"
rm -f "$owner_test"
if su -s /bin/sh www-data -c "touch /var/www/html/$owner_test" 2>/dev/null \
   && su -s /bin/sh www-data -c "php -r 'if (! touch(\"/var/www/html/$owner_test\", 1)) exit(1);'" 2>/dev/null; then
    rm -f "$owner_test"
else
    rm -f "$owner_test"
    echo "[entrypoint-worker] Windows/bind-mount ownership detected; widening storage permissions."
    chmod -R 777 storage bootstrap/cache 2>/dev/null || true
fi

# 6. Production-only caches
if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan config:cache || true
    php artisan route:cache  || true
fi

# 7. exec the CMD
exec "$@"
