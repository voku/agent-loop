## agent-loop workflow router

This repository uses `voku/agent-loop` for governed coding work. Keep this always-on router small; detailed procedures live in the installed skills and CLI help. Troubleshooting only: use `{{agent_loop_cli}} init status` to inspect setup and `init sync-instructions` when managed instruction projection itself needs repair.

For a durable task id:

1. Before mutating code, run `{{agent_loop_cli}} enter <task-id> --format=json` and obey the returned `next_action_kind` / `next_action`. `command` means execute it as written; `decision_required` means supply the missing model and/or human decision values first; `host_work` means perform the described host-native implementation/model work; `none` means there is no further lifecycle action. Never fabricate an approval or risk owner.
2. Use repository-managed skills and subagents when their descriptions match the task. Do not recreate their procedures from conversational memory. In particular, do not pre-build Map/Search, create Session/Recall state, or infer approval/close ordering: deterministic prerequisites and repairs must come from the canonical lifecycle result.
3. When host-native mutation is complete, run `{{agent_loop_cli}} finish <task-id> --format=json`, then obey its canonical next step until `next_action_kind=none` and the result is complete. If an advertised command deterministically refuses without changing the next step, report a workflow defect rather than teaching the host a private workaround.
4. Never claim that hooks fired, checks passed, CI is green, a PR merged, or a release/deploy shipped unless current evidence proves it.

For untracked exploration, use an ephemeral session rather than inventing a durable task.
