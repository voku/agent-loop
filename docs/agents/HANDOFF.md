# Durable task handoff

Use this when useful current-session state must survive for another coding agent that will not have the current chat or Session-private context.

The handoff path deliberately promotes only bounded Session-owned working memory. Before compiling the handoff, record any fact that must survive pruning through the existing Session surfaces (`plan.md`, decision/assumption records, or a checkpoint). Do not dump an opaque chat transcript into durable task state.

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Current handoff state" \
  --body "Verified state, remaining work, blockers, and exact next action."

vendor/bin/agent-loop workflow handoff <task-id>
```

`workflow handoff` resolves the active governed Session, projects its bounded `SessionHandoff`, includes durable Contract evidence, includes the current agent-kanban card projection when one exists, and selects agent-recall-compiler's bundled `todo-card-handoff` L2 recipe. It writes the derived handoff Recall below `.agent-loop/recall/<task-id>/handoff/` (or the configured Recall root).

The generated `system.md` is the self-contained prompt for the acting agent. That agent updates the repository's existing durable task/card through its owner. The command itself does not synthesize or persist task prose, because that is model work and the board remains owned by agent-kanban.

A handoff should therefore preserve this boundary:

```text
pruneable Session working memory
        |
        | bounded projection
        v
SessionHandoff + current Contract + current board card
        |
        | existing Recall todo-card-handoff recipe
        v
self-contained handoff prompt
        |
        | acting agent/model
        v
existing durable task/card owner
```

If no active governed Session exists, the command fails instead of inventing session context. If no board card exists, Recall still receives the Session/Contract evidence and the bundled recipe must not invent a task owner that current evidence does not establish.
