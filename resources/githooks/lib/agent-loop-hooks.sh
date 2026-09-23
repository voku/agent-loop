#!/usr/bin/env bash

# Shared helpers for the package-owned Git hooks.
#
# A hook runs on the host, but the tooling it needs usually lives in the project
# container. These helpers resolve that once, so every hook stays a few readable
# lines instead of repeating the same container lookup.
#
# Values come from agent-loop-hooks.env next to this file, which `init
# sync-githooks` renders from the host's configuration. Anything unset falls back
# to a plain host execution, so a project without containers still works.

# shellcheck disable=SC2034  # sourced by hooks that use only part of this API

agent_loop_hooks_lib_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

AGENT_LOOP_CONTAINER_SERVICE=""
AGENT_LOOP_CONTAINER_IMAGE=""
AGENT_LOOP_CONTAINER_WORKDIR=""
AGENT_LOOP_CONTAINER_USER=""

if [[ -f "$agent_loop_hooks_lib_dir/agent-loop-hooks.env" ]]; then
    # shellcheck source=/dev/null
    . "$agent_loop_hooks_lib_dir/agent-loop-hooks.env"
fi

agent_loop_hooks_repo_root() {
    # A linked worktree can load hook files from the common checkout. Resolve
    # Git before following the hook file's path, otherwise the helper changes
    # into that common checkout and runs its tools instead of this worktree's.
    if git rev-parse --show-toplevel 2>/dev/null; then
        return 0
    fi

    cd -- "$agent_loop_hooks_lib_dir/../.." >/dev/null 2>&1 || return 1
    git rev-parse --show-toplevel 2>/dev/null || pwd
}

agent_loop_hooks_inside_container() {
    [[ -f '/.dockerenv' && -n "$AGENT_LOOP_CONTAINER_WORKDIR" && -d "$AGENT_LOOP_CONTAINER_WORKDIR" ]]
}

agent_loop_hooks_docker_available() {
    command -v docker >/dev/null 2>&1
}

# Prints the container name for the configured image, or nothing when no such
# container runs. Matching by image is deliberate: container names change between
# compose projects, the image does not.
agent_loop_hooks_container_name() {
    [[ -n "$AGENT_LOOP_CONTAINER_IMAGE" ]] || return 0

    docker ps --format '{{.Names}} {{.Image}}' 2>/dev/null |
        awk -v image="$AGENT_LOOP_CONTAINER_IMAGE" '$2 == image {print $1; exit}'
}

# Runs a command where the project tooling actually lives: inside the current
# container, through compose, through a matching container, or on the host.
# Returns the command's own exit code; callers decide whether that may fail.
# The workdir is passed as an argument, never spliced into shell source: a quote
# in it would otherwise end the path literal and could skip the check.
agent_loop_hooks_run() {
    local command="$1"
    shift
    local -a extra_args=("$@")

    if agent_loop_hooks_inside_container; then
        bash -lc 'cd -- "$1" && eval "$2"' agent-loop-hook "$AGENT_LOOP_CONTAINER_WORKDIR" "$command"

        return $?
    fi

    if ! agent_loop_hooks_docker_available || [[ -z "$AGENT_LOOP_CONTAINER_WORKDIR" ]]; then
        bash -lc "$command"

        return $?
    fi

    local -a user_args=()
    if [[ -n "$AGENT_LOOP_CONTAINER_USER" ]]; then
        user_args=(--user "$AGENT_LOOP_CONTAINER_USER")
    fi

    if [[ -n "$AGENT_LOOP_CONTAINER_SERVICE" ]] && [[ -n "$(docker compose ps -q "$AGENT_LOOP_CONTAINER_SERVICE" 2>/dev/null || true)" ]]; then
        docker compose exec -T "${user_args[@]}" "${extra_args[@]}" "$AGENT_LOOP_CONTAINER_SERVICE" \
            bash -lc 'cd -- "$1" && eval "$2"' agent-loop-hook "$AGENT_LOOP_CONTAINER_WORKDIR" "$command"

        return $?
    fi

    local container_name
    container_name="$(agent_loop_hooks_container_name)"
    if [[ -z "$container_name" ]]; then
        bash -lc "$command"

        return $?
    fi

    docker exec "${user_args[@]}" "${extra_args[@]}" "$container_name" \
        bash -lc 'cd -- "$1" && eval "$2"' agent-loop-hook "$AGENT_LOOP_CONTAINER_WORKDIR" "$command"
}

# Maps a host path inside the repository to its path inside the container, so a
# hook can pass Git's temporary index (`git commit --only`) through unchanged.
agent_loop_hooks_map_path() {
    local host_path="$1"
    local repo_root="$2"

    if [[ -z "$host_path" || -z "$AGENT_LOOP_CONTAINER_WORKDIR" ]]; then
        printf '%s' "$host_path"

        return 0
    fi

    if [[ "$host_path" == "$repo_root"/* ]]; then
        printf '%s/%s' "$AGENT_LOOP_CONTAINER_WORKDIR" "${host_path#"$repo_root"/}"

        return 0
    fi

    if [[ "$host_path" == /* ]]; then
        return 1
    fi

    printf '%s' "$host_path"
}

# A freshly created package worktree may not have a generated
# agent-loop-hooks.env yet. Prefer the installed Composer wrapper when it
# exists, then fall back to the package's own executable so the hook remains
# usable before dependency installation or hook re-synchronisation.
agent_loop_hooks_resolve_default_bin() {
    [[ -n "${AGENT_LOOP_BIN:-}" ]] && return 0

    local repo_root
    repo_root="$(agent_loop_hooks_repo_root)" || return 1

    if [[ -x "$repo_root/vendor/bin/agent-loop" ]]; then
        AGENT_LOOP_BIN="$repo_root/vendor/bin/agent-loop"
    elif [[ -x "$repo_root/bin/agent-loop" ]]; then
        AGENT_LOOP_BIN="$repo_root/bin/agent-loop"
    fi

    export AGENT_LOOP_BIN
}

agent_loop_hooks_resolve_default_bin || true
