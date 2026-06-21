FROM php:8.4-fpm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

# Install system packages and PHP extension headers. On Debian-slim the official
# php image ships precompiled .so files in /usr/src/php/ext/<name>/ for most
# extensions, so docker-php-ext-install only configures and links, it does not
# compile from C source. No toolchain (gcc/make/autoconf) is needed at runtime.
# - libpq-dev: PostgreSQL client headers (pdo_pgsql, pgsql)
# - libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev: GD image
# - libzip-dev: zip
# - libicu-dev: intl
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        nginx \
        bash \
        curl \
        git \
        ca-certificates \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        zip \
        unzip \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions. -j"$(nproc)" runs the configure/link steps in parallel.
# - pdo_pgsql, pgsql: Postgres connectivity (Laravel)
# - bcmath: arbitrary precision (Laravel cashier/billing)
# - gd: image processing
# - zip: archive handling
# - pcntl: signal handling for php-fpm workers
# - opcache: opcode cache (mandatory for Laravel in production)
# - intl: ICU/i18n
# - redis (PECL): Redis queue/cache driver
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gd zip pcntl opcache intl \
 && pecl install redis \
 && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Bake nginx site config and entrypoints into the image.
# Debian nginx (php:8.4-fpm) loads /etc/nginx/conf.d/*.conf and
# /etc/nginx/sites-enabled/*, NOT /etc/nginx/http.d/*.conf (Alpine path).
# Place our config in conf.d/ and let entrypoint.sh remove the stock
# sites-enabled/default symlink so only our server block answers on :80.
COPY .docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/entrypoint-worker.sh

# Pre-install PHP dependencies. Layer is cached as long as composer.json
# and composer.lock are unchanged, so source-only edits do not invalidate it.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

# Now copy the rest of the application source and finalize the autoloader.
COPY . .
RUN composer dump-autoload --no-dev --optimize \
 && mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
