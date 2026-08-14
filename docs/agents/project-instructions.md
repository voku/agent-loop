## agent-loop workflow router

This repository uses `voku/agent-loop` for governed coding work. Keep this file as a small router; detailed procedures live in the installed skills and CLI help.

For non-trivial coding, review, debugging, or repository-maintenance tasks:

- Start with `vendor/bin/agent-loop init status`. If expected skills, subagents, hooks, or tools are missing, use `vendor/bin/agent-loop init doctor` and fix the setup instead of silently bypassing it.
- Use the installed `agent-loop-*` skills and `agent-recall-consumer` when their descriptions match the task. Do not recreate their procedures as ad-hoc prompt text.
- Use `agent-map` for bounded source discovery before broad reads. When map-backed Recall matters, build or refresh the map and search index before `workflow approve`, because approval compiles Recall from evidence that already exists.
- When a task has a durable Contract or task id, inspect `vendor/bin/agent-loop workflow status <task-id> --format=json` before mutation and continue from persisted state rather than conversational memory.
- Select applicable L2 operating prompts through the Contract / `agent-recall-compiler` before approval. A compiled prompt is not evidence of use unless the acting agent actually received the generated Recall briefing.
- For local commits, install the package Git hooks with `vendor/bin/agent-loop init sync-githooks` when the repository expects them and let them execute. GitHub/API connector writes cannot run local `pre-commit` or `commit-msg` hooks; report those hooks as not exercised rather than claiming they passed.
- Run the validation declared by the governed task and preserve exact command/output evidence. Do not turn workflow completion into a merged, shipped, or released claim without exact Git candidate evidence.
