# Loaded by Bash for every non-interactive shell in the CLI image.
# shellcheck shell=bash

if [[ "${AGENT_LOOP_CONTAINER_PROFILE_ACTIVE:-}" != 1 ]]; then
    if ! . /opt/agent-dotfiles/.bashrc; then
        printf '%s\n' 'agent-loop: failed to activate the bundled agent-dotfiles profile' >&2
        exit 2
    fi

    # The upstream deactivation helper unsets exactly the environment names
    # recorded here. Track the container marker too, so a deactivated child
    # shell can start a later Bash process and activate the profile again.
    AGENT_DOTFILES_EXPORTED="${AGENT_DOTFILES_EXPORTED-}AGENT_LOOP_CONTAINER_PROFILE_ACTIVE
"
    export AGENT_LOOP_CONTAINER_PROFILE_ACTIVE=1
elif [[ "${AGENT_DOTFILES_HOME:-}" != /opt/agent-dotfiles \
    || -z "${AGENT_DOTFILES_EXPORTED+x}" \
    || -z "${AGENT_DOTFILES_FUNCTIONS+x}" ]]; then
    printf '%s\n' 'agent-loop: incomplete state for the active agent-dotfiles profile' >&2
    exit 2
fi

# Bash does not inherit functions unless they are exported. Export only the
# reviewed dotfiles helper namespace so child `bash -c` commands keep the same
# environment without attempting to activate the profile a second time. A
# POSIX shell between Bash processes can drop exported Bash functions, so
# restore the pinned helper definitions when their activation marker survives.
_agent_loop_restore_functions=0
for _agent_loop_function in agent_git agent_rg agent_head agent_deactivate; do
    if ! declare -F "$_agent_loop_function" >/dev/null; then
        _agent_loop_restore_functions=1
        break
    fi
done

if [[ "$_agent_loop_restore_functions" == 1 ]]; then
    if [[ ! -r /opt/agent-dotfiles/.functions || ! -r /opt/agent-dotfiles/.redpill/lib/deactivation.sh ]]; then
        printf '%s\n' 'agent-loop: bundled agent-dotfiles helpers are missing or unreadable' >&2
        exit 2
    fi
    . /opt/agent-dotfiles/.functions
    . /opt/agent-dotfiles/.redpill/lib/deactivation.sh
fi

export AGENT_DOTFILES_EXPORTED AGENT_DOTFILES_FUNCTIONS
for _agent_loop_function in agent_git agent_rg agent_head agent_deactivate; do
    if declare -F "$_agent_loop_function" >/dev/null; then
        export -f "$_agent_loop_function"
    fi
done
unset _agent_loop_function
unset _agent_loop_restore_functions
