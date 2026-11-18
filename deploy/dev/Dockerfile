# Local test-panel image: built from THIS working tree only, no upstream image.
#
# Why not `FROM ghcr.io/cedar2025/xboard:latest` + overlay: the app stage of the
# repo root Dockerfile does a build-time `git clone` of origin/master, so
# unpushed local commits can never reach the container, and an overlay image
# silently inherits vendor/ from whatever upstream published.
#
# The `runtime` stage below is a copy of ../../Dockerfile's runtime stage (same
# base, same extensions, same system packages). Keep the two in sync when PHP
# extensions or apk packages change. `app` differs on purpose: it copies the
# local context and installs vendor from the committed composer.lock instead of
# cloning a branch.

# syntax=docker/dockerfile:1

FROM phpswoole/swoole:php8.2-alpine AS runtime

RUN php -r 'if (version_compare(PHP_VERSION, "8.2.35", "<")) { \
    fwrite(STDERR, "PHP >= 8.2.35 is required for security fixes; found " . PHP_VERSION . "\n"); exit(1); \
}'

COPY --from=mlocati/php-extension-installer:2.12.3 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

# Install these extensions separately to preserve the ARM64 compiler workarounds.
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath

RUN IPE_PROCESSOR_COUNT=2 install-php-extensions zip

# intl backs User::normalizeEmail (Normalizer + idn_to_ascii) and Helper::emailSuffixVerify.
RUN IPE_PROCESSOR_COUNT=2 install-php-extensions intl

# Keep Redis independent of Alpine's package version to preserve RDB 14 compatibility.
COPY --from=redis:8-alpine /usr/local/bin/redis-* /usr/local/bin/

RUN apk --no-cache add \
        shadow \
        sqlite \
        mysql-client \
        mysql-dev \
        mariadb-connector-c \
        git \
        patch \
        supervisor \
        libssl3 \
        libcrypto3 \
        caddy && \
    redis-server --version && \
    redis-cli --version && \
    addgroup -S -g 1000 www && \
    adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis) && \
    php -r 'foreach (["swoole", "pcntl", "posix", "bcmath", "mbstring", "intl", "pdo_mysql", "pdo_sqlite", "sockets", "zip", "igbinary", "redis"] as $extension) { \
        if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } \
    }'

FROM runtime AS app

WORKDIR /www

# Service configs come from the working tree so local edits apply.
COPY .docker/entrypoint.sh /entrypoint.sh
COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

# Local code. .dockerignore of this Dockerfile (deploy/dev/Dockerfile.dockerignore)
# drops .git, host vendor/, .env and runtime junk but keeps composer.lock.
COPY app /www/app
COPY bootstrap /www/bootstrap
COPY config /www/config
COPY database /www/database
COPY routes /www/routes
COPY resources /www/resources
# SYSTEM_THEME_DIR in ThemeService is base_path('theme/'); without it every
# route renders 500 "主题加载失败".
COPY theme /www/theme
COPY public /www/public
COPY storage /www/storage
COPY plugins /www/plugins
COPY plugins-core /www/plugins-core
COPY artisan /www/artisan
COPY composer.json composer.lock /www/

# Host bootstrap manifests are built by a dev install and reference require-dev
# packages absent from --no-dev vendor; drop them so Laravel rebuilds them here.
RUN rm -f /www/bootstrap/cache/packages.php /www/bootstrap/cache/services.php && \
    mkdir -p /www/storage/app/public \
             /www/storage/framework/cache/data \
             /www/storage/framework/sessions \
             /www/storage/framework/views \
             /www/storage/logs

RUN composer install --no-cache --no-dev --no-security-blocking \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001

RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
