#!/bin/bash

set -eu

composer_home=${COMPOSER_HOME:-/tmp/composer}
mkdir -p "$composer_home"

if [ -s /run/secrets/proxy_ca ]; then
    ca_bundle="$composer_home/ca-bundle.pem"
    cat /etc/ssl/certs/ca-certificates.crt /run/secrets/proxy_ca > "$ca_bundle"
    chmod 600 "$ca_bundle"
    export COMPOSER_CAFILE="$ca_bundle"
fi

if [ "${1:-}" = "--exec" ]; then
    shift
    if [ "$#" -eq 0 ]; then
        printf '%s\n' 'Usage: agent-loop --exec <command> [arguments...]' >&2
        exit 2
    fi

    exec "$@"
fi

exec php /opt/agent-loop/bin/agent-loop "$@"
