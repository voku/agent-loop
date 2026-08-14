## agent-loop workflow router

This repository uses `voku/agent-loop` for governed coding work. Keep this file as a small router; detailed procedures live in the installed skills and CLI help.

When this router is projected into a host instruction file, the content between the `agent-loop:project-instructions` markers is package-managed. Put project-specific rules outside those markers and refresh the managed block with `init install-assets` or `init sync-instructions` instead of editing it by hand.

For non-trivial coding, review, debugging, or repository-maintenance tasks:

- At the start of a fresh agent session, or when the agent-loop setup is unknown, run `{{agent_loop_cli}} init status` once. Its `Activation:` section reports the resolved CLI path, whether skills are projected into a host at all, and whether local Git hooks and the commit template are active; its `Next:` lines are the exact activation commands for this repository. Run them instead of silently bypassing the setup, and use `{{agent_loop_cli}} init doctor` for the wider environment. If the CLI itself is missing, install the Composer dependencies first. Do not repeat bootstrap diagnostics once the current session has established the setup.
- Use the installed `agent-loop-*` skills and `agent-recall-consumer` when their descriptions match the task. Do not recreate their procedures as ad-hoc prompt text.
- Use `agent-map` for bounded source discovery before broad reads. When map-backed Recall matters, build or refresh the map and search index before `workflow approve`, because approval compiles Recall from evidence that already exists.
- When a task has a durable Contract or task id, inspect `{{agent_loop_cli}} workflow status <task-id> --format=json` before mutation and continue from persisted state rather than conversational memory.
- Select applicable L2 operating prompts through the Contract / `agent-recall-compiler` before approval, then read the compiled `system.md` in the Recall output directory that `workflow approve` names. A compiled prompt is not evidence of use unless the acting agent actually received the generated Recall briefing.
- For local commits, activate the package Git hooks with the `init status` command shown above when the repository expects them and let them execute. GitHub/API connector writes cannot run local `pre-commit` or `commit-msg` hooks; report those hooks as not exercised rather than claiming they passed.
- Run the validation declared by the governed task and preserve exact command/output evidence. Do not turn workflow completion into a merged, shipped, or released claim without exact Git candidate evidence.
