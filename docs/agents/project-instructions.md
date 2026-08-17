## agent-loop workflow router

This repository uses `voku/agent-loop` for governed coding work. Keep this always-on router small; detailed procedures live in the installed skills and CLI help. Troubleshooting only: use `{{agent_loop_cli}} init status` to inspect setup and `init sync-instructions` when managed instruction projection itself needs repair.

For a durable task id:

1. Before mutating code, run `{{agent_loop_cli}} enter <task-id>`. If it reports `Mutation: ready`, use its bounded context to perform the approved host-native work; its `Next:` value is the next governed close-out action after that work and is not another mutation prerequisite. If it reports `Mutation: not_ready`, do not mutate and follow `Next:` instead; a non-zero exit means the returned prerequisite or disagreement is blocking progress.
2. Use repository-managed skills and subagents when their descriptions match the task. Do not recreate their procedures from conversational memory.
3. Before claiming completion, run `{{agent_loop_cli}} finish <task-id>`. Only exit 0 with `Complete: yes` means done; otherwise follow its canonical `Next:` action.
4. Never claim that hooks fired, checks passed, CI is green, a PR merged, or a release/deploy shipped unless current evidence proves it.

For untracked exploration, use an ephemeral session rather than inventing a durable task.
