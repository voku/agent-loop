# Loaded by Bash for every non-interactive shell in the CLI image.
# shellcheck shell=bash

if [[ "${AGENT_LOOP_CONTAINER_PROFILE_ACTIVE:-}" != 1 ]]; then
    if ! . /opt/agent-dotfiles/.bashrc; then
        printf '%s\n' 'agent-loop: failed to activate the bundled agent-dotfiles profile' >&2
        exit 2
    fi
    export AGENT_LOOP_CONTAINER_PROFILE_ACTIVE=1
fi

# Bash does not inherit functions unless they are exported. Export only the
# reviewed dotfiles helper namespace so child `bash -c` commands keep the same
# environment without attempting to activate the profile a second time.
export -f agent_git agent_rg agent_head agent_deactivate
