## agent-loop workflow router

This repository uses `voku/agent-loop` for governed coding work. Keep this always-on router small; detailed procedures live in the installed skills and CLI help.

For a durable task id:

1. Before mutating code, run `{{agent_loop_cli}} enter <task-id>`. Follow its bounded context and canonical `Next:` action. Mutate only when it reports `Mutation: ready`; if it exits non-zero, satisfy the returned prerequisite instead of bypassing it.
2. Use repository-managed skills and subagents when their descriptions match the task. Do not recreate their procedures from conversational memory.
3. Before claiming completion, run `{{agent_loop_cli}} finish <task-id>`. Only exit 0 with `Complete: yes` means done; otherwise follow its canonical `Next:` action.
4. Never claim that hooks fired, checks passed, CI is green, a PR merged, or a release/deploy shipped unless current evidence proves it.

For untracked exploration, use an ephemeral session rather than inventing a durable task.
