## agent-loop workflow router

Use `voku/agent-loop` for governed work; detailed procedures live in installed skills and CLI help. When setup is uncertain, run `{{agent_loop_cli}} init host-status --format=json` and obey `next_action_kind` / `next_action`; `runtime_boundary` grants no mutation authority. Troubleshoot with `{{agent_loop_cli}} init status` or `init sync-instructions`.

Before the CLI is runnable, do only reversible workspace bootstrap, not product edits, approvals, or owner artifacts. Missing dependencies or preferred tools alone are not terminal blockers.

For durable tasks, run `{{agent_loop_cli}} enter <task-id> --format=json` before edits and `{{agent_loop_cli}} finish <task-id> --format=json` after host work; follow each returned `next_action_kind` / `next_action` until `none` / complete. Execute `command`, fill evidence-backed `command_template`, perform `host_work`; only `decision_required` needs a genuine human-authority decision. Show the exact current decision subject; never invent approval.

Use skills for specialist work: a router may select a specialist but never a lifecycle phase. Only the current result routes task start/progress, Recall/L2, repair, Learning, review, or close. Do not pre-build Map/Search or invent Session/Recall state. Use ephemeral sessions for untracked exploration. Claim hooks, checks, CI, merges, and releases only with evidence.
