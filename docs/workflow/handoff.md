# Durable task handoff

Use this when useful current-session context must survive for another coding agent that will not have the current chat or prior-agent memory.

The current chat is not an authority artifact and agent-session 0.6.x deliberately stores only pruneable working memory. `workflow handoff` therefore requires the acting host to supply a **bounded summary** of the current session explicitly. The command binds those candidate notes to the current governed Run/Session, adds durable Contract evidence and the current board-card projection when available, then selects agent-recall-compiler's existing `todo-card-handoff` recipe. Do not dump an opaque transcript.

For a short handoff:

```bash
vendor/bin/agent-loop workflow handoff TASK-123 \
  --context "Verified: PR #230 is green. Next: verify merged-main ancestry, then run the installed-consumer falsification."
```

For a larger current-session summary, write a temporary/task-local note and pass it explicitly:

```bash
vendor/bin/agent-loop workflow handoff TASK-123 \
  --context-file /tmp/TASK-123-handoff.md
```

The supplied notes are labelled as candidate context, not durable truth. The generated L2 prompt requires material claims to be re-grounded against current repository/owner evidence before they are written into a durable task.

`workflow handoff` resolves the current governed Run and its exact Session identity, reads only the published Session metadata API, includes durable Contract evidence, includes the current typed agent-kanban card projection when one exists, and selects agent-recall-compiler's bundled `todo-card-handoff` L2 recipe. It writes derived handoff Recall below `.agent-loop/recall/<task-id>/handoff/` (or the configured Recall root).

The generated `system.md` is the self-contained prompt for the acting agent. That agent updates the repository's existing durable task/card through its owner. The command itself does not synthesize or persist task prose, because that is model work and the board remains owned by agent-kanban.

The ownership path is:

```text
current chat / acting-agent context
        |
        | explicit bounded --context / --context-file
        v
candidate handoff notes + governed Session identity
        + current Contract + current board card
        |
        | existing Recall todo-card-handoff recipe
        v
self-contained handoff prompt
        |
        | acting agent/model re-grounds material claims
        v
existing durable task/card owner
```

If no governed Run exists, the command fails instead of inventing Session identity. If the bound Session cannot be loaded, it fails rather than reading some other open Session. If no board card exists, Recall still receives the explicit notes plus Session/Contract evidence and the bundled recipe must not invent a task owner that current evidence does not establish.

A future released agent-session version may expose richer owner-projected handoff content. Until then, agent-loop intentionally does not read Session-private files or copy unreleased projection semantics into the lifecycle package.
