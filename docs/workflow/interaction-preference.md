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
# Clear an override and use the session default again:
agent-loop workflow interaction "$AGENT_LOOP_HOST_SESSION_ID" inherit TASK-1
# Present decision cards in this session's language (after a mode is set):
agent-loop workflow interaction "$AGENT_LOOP_HOST_SESSION_ID" --language=de-DE
# Go back to the project's prompting.language:
agent-loop workflow interaction "$AGENT_LOOP_HOST_SESSION_ID" --language=inherit
```

The session language is presentation only. With `AGENT_LOOP_HOST_SESSION_ID` set, `enter`, `finish`, `workflow plan` and `workflow context` render `human_decision.card` and the response-language line in it; the project's `prompting.language` in `.agent-loop/init.json` is never written. A language needs an existing session preference (set a mode first), and an invalid tag such as `DE_de` is refused. Card text exists in English and German; for other languages the card stays English, `requested_language` names the asked-for tag, and `unknown` tells the host to translate the prose.

SessionStart exposes the host hook's `session_id` when it is a safe identifier. For task context, the host must
set `AGENT_LOOP_HOST_SESSION_ID` to that same identity. SessionStart binds a new session to the last default stored in that host-owned directory; task overrides remain session-specific. With no preference the
host follows the user's existing instructions and may ask when a preference
matters; it must not infer consent. A task override applies only to the named task.
Autonomous mode guides ordinary implementation, verification and in-scope repair
through the existing lifecycle. Collaborative mode invites discussion of meaningful
choices. Both preserve Contract approval, independent review, human decisions,
merge and deployment gates. Preferences are not mutation authorization.
