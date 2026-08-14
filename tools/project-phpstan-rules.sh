#!/usr/bin/env bash
set -euo pipefail

set +e
output="$(vendor/bin/phpstan analyse \
  --configuration=phpstan/project-rule-test.neon \
  --no-progress \
  --error-format=raw 2>&1)"
exit_code=$?
set -e

printf '%s\n' "${output}"

if [[ "${exit_code}" -ne 1 ]]; then
  echo "Expected project PHPStan fixtures to fail with exit 1, got ${exit_code}." >&2
  exit 1
fi

expected=(
  'Workflow orchestration must not instantiate focused-package CLI voku\AgentSession\Cli.'
  'Workflow commands must not expose --learning-root.'
  'Project-specific PHPStan rule fixtures must run in an isolated PHPStan subprocess'
  'Do not infer Git repository support from is_dir(.git)'
  'Workflow orchestration must not call SessionStore::create() directly.'
  'Workflow orchestration must not own historical state path "infra/doc/agent-learning".'
)

for diagnostic in "${expected[@]}"; do
  if ! grep -Fq "${diagnostic}" <<< "${output}"; then
    echo "Expected project PHPStan diagnostic was not reported: ${diagnostic}" >&2
    exit 1
  fi
done

echo 'Project PHPStan rule dogfood: PASSED'
