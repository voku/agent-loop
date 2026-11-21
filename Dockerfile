# syntax=docker/dockerfile:1
FROM composer:2.11.2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93 AS composer

FROM php:8.6.0-cli-trixie@sha256:d84acf3155d907afaa5afa9b9457c5f12016b6dc7ade84659f40cdc505cc087d

RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        bash \
        git \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        ripgrep \
        unzip \
    && docker-php-ext-install intl mbstring pcntl pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    BASH_ENV=/opt/agent-loop/docker/container-bash-env.sh

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
COPY docker/container-bash-env.sh ./docker/container-bash-env.sh
COPY docker/agent-dotfiles/ /opt/agent-dotfiles/

RUN chmod -R a+rX /opt/agent-loop /opt/agent-dotfiles && chmod 755 /usr/local/bin/agent-loop-entrypoint

USER 1000:1000

ENTRYPOINT ["/usr/local/bin/agent-loop-entrypoint"]
