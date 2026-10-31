# syntax=docker/dockerfile:1
FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer

FROM php:8.5.12-cli-trixie@sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554

RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        bash \
        git \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        ripgrep \
        unzip \
    && docker-php-ext-install intl mbstring pdo_sqlite \
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
