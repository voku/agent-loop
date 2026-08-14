#!/usr/bin/env bash

# Keeps the agent-map symbol index in step with the working tree after Git moved it.
#
# Rules this follows, in order of importance:
#   1. Never fail the Git operation. A navigation index is a convenience; a pull that aborts
#      because Docker is down is not. Every exit path is 0.
#   2. Never build. A missing index means nobody opted into the map, and a cold build costs
#      minutes - not something a hook may start behind someone's back.
#   3. Do nothing when no PHP file moved. `refresh` still has to hash every indexed file, so the
#      no-op case is skipped by diffing the two revisions first.
#
# Opt out with AGENT_LOOP_SKIP_MAP_REFRESH=1.
#
# Usage: agent-map-refresh.sh <old-rev> <new-rev>

set -uo pipefail

hooks_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/agent-loop-hooks.sh
. "$hooks_dir/lib/agent-loop-hooks.sh"

if [[ "${AGENT_LOOP_SKIP_MAP_REFRESH:-0}" == "1" ]]; then
    exit 0
fi

repo_root="$(agent_loop_hooks_repo_root)" || exit 0
cd "$repo_root" || exit 0

index_path="${AGENT_LOOP_MAP_INDEX:-.agent-loop/map/php-symbols.json}"
search_database="${AGENT_LOOP_MAP_SEARCH_DATABASE:-.agent-loop/map/search.sqlite}"
if [[ ! -f "$index_path" ]]; then
    exit 0
fi

old_rev="${1:-}"
new_rev="${2:-HEAD}"
if [[ -n "$old_rev" ]]; then
    if [[ "$old_rev" == "$new_rev" ]]; then
        exit 0
    fi
    if git rev-parse --quiet --verify "$old_rev" >/dev/null 2>&1; then
        changed="$(git diff --name-only "$old_rev" "$new_rev" -- '*.php' 2>/dev/null | head -n 1)"
        if [[ -z "$changed" ]]; then
            exit 0
        fi
    fi
fi

memory_limit="${AGENT_LOOP_MAP_MEMORY_LIMIT:-4G}"
phpstan_memory_limit="${AGENT_LOOP_MAP_PHPSTAN_MEMORY_LIMIT:-3G}"

refresh="php -d memory_limit=$memory_limit vendor/bin/agent-map refresh --root=. --index=$index_path --out=$index_path --phpstan-memory-limit=$phpstan_memory_limit"
# The search index is derived from the map, so it is refreshed in the same breath: leaving it behind
# would answer questions from the previous commit. It only runs when it already exists - building one
# is an explicit decision, not something a git hook does on someone's behalf.
refresh="$refresh; if [ -f $search_database ]; then php -d memory_limit=$memory_limit vendor/bin/agent-map search-index refresh --root=. --index=$index_path --database=$search_database; fi"

agent_loop_hooks_run "$refresh" || true

exit 0
