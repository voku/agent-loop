#!/bin/sh

set -eu

if [ -s /run/secrets/proxy_ca ]; then
    ca_bundle="${COMPOSER_HOME:-/tmp/composer}/ca-bundle.pem"
    mkdir -p "$(dirname "$ca_bundle")"
    cat /etc/ssl/certs/ca-certificates.crt /run/secrets/proxy_ca > "$ca_bundle"
    chmod 600 "$ca_bundle"
    export COMPOSER_CAFILE="$ca_bundle"
fi

exec /opt/agent-ui/vendor/bin/agent-ui "$@"
