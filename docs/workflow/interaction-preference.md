# Interaction preference

Host sessions may choose `collaborative` or `autonomous` behavior. The session identity
and the preference directory belong to the host; neither is a task Session or project
configuration.

| Input | Source, first set value wins |
| --- | --- |
| Session identity | `AGENT_LOOP_HOST_SESSION_ID`, then `CLAUDE_CODE_SESSION_ID` (exported by Claude Code; needs no setup) |
| Preference directory | `AGENT_LOOP_INTERACTION_DIR`, then `$XDG_STATE_HOME/agent-loop/interaction`, then `~/.local/state/agent-loop/interaction` |

Other hosts set `AGENT_LOOP_HOST_SESSION_ID` themselves. An identity that does not match
`[A-Za-z0-9._-]+` without a leading `-` (at most 128 bytes) is reported as `invalid` and never replaced by the
next source.

`enter` and `workflow context` report `interaction.host_session` in the context: `state`
(`unbound`, `invalid`, `bound`), `preference` (`not_applicable`, `unavailable`, `unknown`,
`known`), `mode` and `mode_source`. When the session is bound and no preference is saved,
`interaction.question` holds one non-blocking question with a command per answer, and
`enter` repeats it as top-level `session_question`. Ask it once before host work and run
the chosen command; skip it when nobody is attending. Once a session default, a task
override or a shared default from an earlier session exists, there is no question. Reading
never writes a preference, and the question changes no `next_action`. It is rendered
outside the context budget, so it never displaces Contract content. A session without its
own preference follows the shared default (`mode_source: shared_default`) until SessionStart,
a task override or a language binds it to the mode current at that time.

Set the default and optional task override:

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

The session language is presentation only. In a bound session, `enter`, `finish`, `workflow plan` and `workflow context` render `human_decision.card` and the response-language line in it; the project's `prompting.language` in `.agent-loop/init.json` is never written. A language needs an existing session preference (set a mode first), and an invalid tag such as `DE_de` is refused. Card text exists in English and German; for other languages the card stays English, `requested_language` names the asked-for tag, and `unknown` tells the host to translate the prose.

SessionStart exposes the host hook's `session_id` when it is a safe identifier. For task context, a host without
a recognized session variable must set `AGENT_LOOP_HOST_SESSION_ID` to that same identity. SessionStart binds a new session to the last default stored in that host-owned directory; task overrides remain session-specific. With no preference (and no answer to the question) the
host follows the user's existing instructions and may ask when a preference
matters; it must not infer consent. A task override applies only to the named task.
Autonomous mode guides ordinary implementation, verification and in-scope repair
through the existing lifecycle. Collaborative mode invites discussion of meaningful
choices. Both preserve Contract approval, independent review, human decisions,
merge and deployment gates. Preferences are not mutation authorization.
