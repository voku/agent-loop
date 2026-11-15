# Interaction preference

Host sessions may choose `collaborative` or `autonomous` behavior. The host supplies
an independent session identity and a host-owned directory; neither is a task
Session or project configuration. Set the default and optional task override:

```bash
export AGENT_LOOP_INTERACTION_DIR=/path/to/private/host-preferences
export AGENT_LOOP_HOST_SESSION_ID=host-session-id
agent-loop workflow interaction "$AGENT_LOOP_HOST_SESSION_ID" autonomous
# Or reuse a prior session default explicitly:
agent-loop workflow interaction "$AGENT_LOOP_HOST_SESSION_ID" --reuse previous-host-session-id
agent-loop workflow interaction "$AGENT_LOOP_HOST_SESSION_ID" collaborative TASK-1
```

SessionStart exposes the host hook's `session_id` when it is a safe identifier. For task context, the host must
set `AGENT_LOOP_HOST_SESSION_ID` to that same identity. A new session uses the last session default stored in that host-owned directory; task overrides remain session-specific. With no preference the
host follows the user's existing instructions and may ask when a preference
matters; it must not infer consent. A task override applies only to the named task.
Autonomous mode guides ordinary implementation, verification and in-scope repair
through the existing lifecycle. Collaborative mode invites discussion of meaningful
choices. Both preserve Contract approval, independent review, human decisions,
merge and deployment gates. Preferences are not mutation authorization.
