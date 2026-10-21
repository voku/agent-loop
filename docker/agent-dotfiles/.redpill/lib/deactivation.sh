# Remove exactly what activation added; never restore or touch host state.
agent_deactivate() {
    local _agent_name
    while IFS= read -r _agent_name; do
        [ -n "$_agent_name" ] && unset "$_agent_name"
    done <<EOT
${AGENT_DOTFILES_EXPORTED-}
EOT
    while IFS= read -r _agent_name; do
        [ -n "$_agent_name" ] && unset -f "$_agent_name"
    done <<EOT
${AGENT_DOTFILES_FUNCTIONS-}
EOT
    unset AGENT_DOTFILES_HOME AGENT_DOTFILES_EXPORTED AGENT_DOTFILES_FUNCTIONS
    unset -f agent_deactivate
}
