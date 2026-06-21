#!/bin/sh
# Web container entrypoint.
# Responsibilities, in order:
#   1. Bootstrap .env from .env.example on first boot (bind mount overwrites it).
#   2. Bootstrap vendor/ if missing (bind mount also overwrites build-time
#      vendor/, so we re-run composer install). Idempotent: skipped on
#      subsequent boots once vendor/ exists.
#   3. Generate APP_KEY on first boot if empty.
#   4. Wait for Postgres + Redis to be reachable.
#   5. Run database migrations (idempotent: no-op when up to date).
#   6. Optionally cache config/routes in production.
#   7. Start nginx in the background and exec the CMD (php-fpm -F in foreground).
#
# All steps are idempotent and safe to re-run on every container start.
set -eu

cd /var/www/html

# 1. .env bootstrap
if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

# 2. vendor/ bootstrap (bind mount overwrites build-time vendor/)
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing, running composer install..."
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
    composer dump-autoload --no-dev --optimize
fi

# 3. APP_KEY bootstrap
if [ -f .env ] && grep -qE '^APP_KEY= *$' .env; then
    if command -v php >/dev/null 2>&1; then
        php artisan key:generate --force --no-interaction || true
    fi
fi

# 4. Wait for dependencies. Uses a small PHP one-liner with fsockopen() instead
# of /dev/tcp because /bin/sh on Debian is dash, which does not support
# /dev/tcp (only bash does). fsockopen is guaranteed to work because PHP is
# the very thing this container is meant to run.
wait_for() {
    name="$1"; host="$2"; port="$3"
    echo "[entrypoint] waiting for $name at $host:$port..."
    php -r '
        $h = $argv[1]; $p = (int) $argv[2];
        for ($i = 0; $i < 60; $i++) {
            $errno = 0; $errstr = "";
            $fp = @fsockopen($h, $p, $errno, $errstr, 1.0);
            if ($fp) { fclose($fp); echo "ok\n"; exit(0); }
            sleep(1);
        }
        echo "timeout\n"; exit(1);
    ' "$host" "$port" >/dev/null && echo "[entrypoint] $name reachable." || echo "[entrypoint] WARNING: $name not reachable after 60s, continuing anyway."
}

# Extract host/port from .env, with sane defaults matching docker-compose.
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

# 5. Run database migrations. `migrate --force` is non-interactive in non-local
# environments. The whole block is wrapped so a migration failure does not
# block the web server from coming up.
if command -v php >/dev/null 2>&1; then
    echo "[entrypoint] running database migrations..."
    php artisan migrate --force --no-interaction || echo "[entrypoint] WARNING: migration failed, continuing."
fi

# 6. Detect Windows bind mounts where file ownership cannot be changed.
#    On such mounts, files created by www-data appear as root:root, so
#    Laravel's BladeCompiler (which touch()es compiled views with an explicit
#    timestamp) fails with "Utime failed: Operation not permitted". Run php-fpm
#    as root as a dev workaround. This only applies when www-data cannot set an
#    arbitrary timestamp on a file it created.
owner_test="storage/framework/views/.owner_test"
rm -f "$owner_test"
if su -s /bin/sh www-data -c "touch /var/www/html/$owner_test" 2>/dev/null \
   && su -s /bin/sh www-data -c "php -r 'if (! touch(\"/var/www/html/$owner_test\", 1)) exit(1);'" 2>/dev/null; then
    rm -f "$owner_test"
else
    rm -f "$owner_test"
    echo "[entrypoint] Windows/bind-mount ownership detected; running php-fpm as root for dev."
    sed -i 's/^user = www-data/user = root/' /usr/local/etc/php-fpm.d/www.conf
    sed -i 's/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf
    # Keep the unix socket accessible to nginx (which runs workers as www-data).
    sed -i 's/^;listen.owner = www-data/listen.owner = www-data/' /usr/local/etc/php-fpm.d/www.conf
    sed -i 's/^;listen.group = www-data/listen.group = www-data/' /usr/local/etc/php-fpm.d/www.conf
    # php-fpm refuses to run as root unless the -R flag is passed.
    if [ "$1" = "php-fpm" ]; then
        shift
        set -- php-fpm -R "$@"
    fi
fi

# 7. Production-only caches
if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan config:cache || true
    php artisan route:cache  || true
fi

# 8. Disable the stock Debian nginx site (which sets root /var/www/html and a
#    no-fallback try_files =404). The php:8.4-fpm base image ships it symlinked
#    into /etc/nginx/sites-enabled/, and nginx.conf includes both sites-enabled/*
#    and conf.d/*.conf. Our custom config lives in /etc/nginx/conf.d/default.conf,
#    so without this removal the stock site would win on `listen 80 default_server`
#    and our config would be ignored. Idempotent: -f makes the rm a no-op if the
#    symlink is gone.
if [ -e /etc/nginx/sites-enabled/default ]; then
    rm -f /etc/nginx/sites-enabled/default
    echo "[entrypoint] removed stock Debian nginx site from sites-enabled."
fi

# 9. Launch nginx in the background, then exec the CMD (php-fpm -F) as PID 1.
if command -v nginx >/dev/null 2>&1; then
    nginx
fi

exec "$@"
