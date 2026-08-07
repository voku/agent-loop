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
  echo "Expected project PHPStan fixture to fail with exit 1, got ${exit_code}." >&2
  exit 1
fi

if ! grep -Fq 'Workflow orchestration must not instantiate focused-package CLI voku\AgentSession\Cli.' <<< "${output}"; then
  echo 'Expected focused-package CLI boundary error was not reported.' >&2
  exit 1
fi

echo 'Project PHPStan rule dogfood: PASSED'
