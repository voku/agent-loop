# syntax=docker/dockerfile:1
FROM composer:2 AS composer

FROM php:8.4-cli

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

COPY composer.json ./composer.json
RUN --mount=type=secret,id=proxy_ca,required=false \
    set -eu; \
    if [ -s /run/secrets/proxy_ca ]; then \
        cat /etc/ssl/certs/ca-certificates.crt /run/secrets/proxy_ca > /tmp/agent-loop-ca.pem; \
        export COMPOSER_CAFILE=/tmp/agent-loop-ca.pem; \
    fi; \
    composer install --no-dev --no-interaction --no-progress --prefer-dist; \
    rm -f /tmp/agent-loop-ca.pem

COPY bin/ ./bin/
COPY src/ ./src/
COPY resources/ ./resources/

RUN chmod -R a+rX /opt/agent-loop

ENTRYPOINT ["php", "/opt/agent-loop/bin/agent-loop"]
