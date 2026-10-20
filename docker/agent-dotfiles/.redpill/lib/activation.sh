# Reviewed, fixed asset preflight: never discover or execute host plugins.
_agent_dotfiles_activate() {
    local _agent_home="$1" _agent_name _agent_names
    for _agent_name in .exports .functions .redpill/lib/deactivation.sh .redpill/config/agent.env .redpill/config/ripgreprc; do
        if [ ! -f "$_agent_home/$_agent_name" ] || [ ! -r "$_agent_home/$_agent_name" ]; then
            printf 'agent-dotfiles: required %s is missing or unreadable\n' "$_agent_name" >&2
            return 2
        fi
    done
    if [ "${AGENT_DOTFILES_HOME+x}" = x ]; then
        printf 'agent-dotfiles: AGENT_DOTFILES_HOME already exists; deactivate first\n' >&2
        return 2
    fi
    # The owned namespace is exactly the `agent_*()` definitions in .functions,
    # so a new helper needs no second list. agent_deactivate is defined in lib/.
    _agent_names=$(sed -n 's/^\(agent_[a-z_]*\)() .*/\1/p' "$_agent_home/.functions")
    while IFS= read -r _agent_name; do
        if typeset -f "$_agent_name" >/dev/null 2>&1 || alias "$_agent_name" >/dev/null 2>&1; then
            printf 'agent-dotfiles: owned namespace collision: %s\n' "$_agent_name" >&2
            return 2
        fi
    done <<EOT
$_agent_names
agent_deactivate
EOT
    # shellcheck source=../../.exports
    . "$_agent_home/.exports" "$_agent_home"
    # Recorded so agent_deactivate removes exactly these functions.
    # shellcheck disable=SC2034
    AGENT_DOTFILES_FUNCTIONS="$_agent_names"
    # shellcheck source=../../.functions
    . "$_agent_home/.functions"
}
