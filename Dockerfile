# syntax=docker/dockerfile:1
#
# Targets:
#   prod — the shipped image (build with --target prod): Alpine, no build tools, code baked in.
#          The only state is config.json, mounted read-only by the operator.
#   dev  — local development image (docker-compose.yml), code bind-mounted. It is the last
#          stage, so a build with no target still gives the dev image.

ARG FRANKENPHP=1.12-php8.4

############################ prod: PHP dependencies ############################
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction --no-progress \
        --ignore-platform-reqs
COPY src ./src
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

############################ prod: runtime ############################
FROM dunglas/frankenphp:${FRANKENPHP}-alpine AS prod

RUN install-php-extensions opcache \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"

RUN addgroup -g 1000 app && adduser -D -u 1000 -G app app \
 && chown -R app:app /config/caddy /data/caddy

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
COPY config.json.example ./

USER app
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO /dev/null http://127.0.0.1:8080/favicon.svg || exit 1

# Last, so a new tag does not invalidate the layers above. The footer shows it.
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION} \
    SERVER_NAME=:8080

############################ dev (default) ############################
FROM dunglas/frankenphp:php8.4 AS dev

WORKDIR /app

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    libzip-dev \
    libicu-dev \
    unzip \
    git \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    intl \
    zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Non-root user, UID matched to the host so bind-mounted files stay editable.
ARG USER=appuser
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} ${USER} && \
    useradd -m -u ${UID} -g ${GID} ${USER}; \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
    chown -R ${USER}:${USER} /config/caddy /data/caddy
USER ${USER}

RUN echo 'alias l="ls -lah --color=auto"' >> ~/.bashrc
