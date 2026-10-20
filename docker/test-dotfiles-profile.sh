#!/usr/bin/env bash

set -euo pipefail

if [[ -z "${AGENT_DOTFILES_HOME:-}" ]] || ! declare -F agent_deactivate >/dev/null; then
    printf '%s\n' 'dotfiles profile did not activate for this shell' >&2
    exit 1
fi

child_script=$(mktemp)
child_stderr=$(mktemp)
trap 'rm -f "$child_script" "$child_stderr"' EXIT

cat >"$child_script" <<'CHILD'
set -euo pipefail

[[ -n "${AGENT_DOTFILES_HOME:-}" ]]
[[ "${AGENT_LOOP_CONTAINER_PROFILE_ACTIVE:-}" == 1 ]]
declare -F agent_deactivate >/dev/null

agent_deactivate

[[ -z "${AGENT_DOTFILES_HOME:-}" ]]
[[ -z "${AGENT_LOOP_CONTAINER_PROFILE_ACTIVE:-}" ]]
! declare -F agent_git >/dev/null
! declare -F agent_rg >/dev/null

# A later Bash process must start with a clean profile and activate it again.
bash -c '
    set -euo pipefail
    [[ -n "${AGENT_DOTFILES_HOME:-}" ]]
    [[ "${AGENT_LOOP_CONTAINER_PROFILE_ACTIVE:-}" == 1 ]]
    declare -F agent_git >/dev/null
    declare -F agent_rg >/dev/null
    declare -F agent_deactivate >/dev/null
'
CHILD

if ! bash "$child_script" 2>"$child_stderr"; then
    cat "$child_stderr" >&2
    exit 1
fi

if [[ -s "$child_stderr" ]]; then
    cat "$child_stderr" >&2
    printf '%s\n' 'child-shell profile transition emitted diagnostics' >&2
    exit 1
fi

printf '%s\n' 'dotfiles profile activated, deactivated, and reactivated across nested Bash shells'
