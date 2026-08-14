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

expected_errors=(
  'Workflow orchestration must not instantiate focused-package CLI voku\AgentSession\Cli.'
  'Workflow commands must not accept --learning-root.'
  'Production code must not name retired state root infra/doc/agent-learning.'
  'proc_open must receive an argv array, not shell-shaped command text.'
  'Child PHP commands must place -n immediately after PHP_BINARY'
  'ProjectLayout::learningRoot() result is discarded.'
  'PHPDoc contract tags must be on separate lines'
)

for expected_error in "${expected_errors[@]}"; do
  if ! grep -Fq "${expected_error}" <<< "${output}"; then
    echo "Expected project PHPStan error was not reported: ${expected_error}" >&2
    exit 1
  fi
done

echo 'Project PHPStan rule dogfood: PASSED'
