# syntax=docker/dockerfile:1
FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM php:8.4.26-cli-trixie@sha256:1d2cf2b7d715b5b3aa959f0150ccbcc7b71e0181b637e443abf40a7fb56bf79a

RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        git \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        unzip \
    && docker-php-ext-install intl mbstring pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

WORKDIR /opt/agent-loop

COPY composer.json composer.lock ./
RUN --mount=type=secret,id=proxy_ca,required=false \
    set -eu; \
    if [ -s /run/secrets/proxy_ca ]; then \
        cat /etc/ssl/certs/ca-certificates.crt /run/secrets/proxy_ca > /tmp/agent-loop-ca.pem; \
        export COMPOSER_CAFILE=/tmp/agent-loop-ca.pem; \
    fi; \
    composer install --no-dev --no-interaction --no-progress --prefer-dist; \
    rm -f /tmp/agent-loop-ca.pem; \
    rm -rf "$COMPOSER_HOME"

COPY bin/ ./bin/
COPY src/ ./src/
COPY resources/ ./resources/
COPY docker/entrypoint.sh /usr/local/bin/agent-loop-entrypoint

RUN chmod -R a+rX /opt/agent-loop && chmod 755 /usr/local/bin/agent-loop-entrypoint

USER 1000:1000

ENTRYPOINT ["/usr/local/bin/agent-loop-entrypoint"]
