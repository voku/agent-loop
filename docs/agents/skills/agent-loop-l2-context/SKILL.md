---
name: agent-loop-l2-context
description: Compile and use agent-loop recall/L2 meta-prompt artifacts from the current repository without mistaking generated context for executed agent actions.
---

# Agent Loop L2 Context

Use this skill when the task asks for L2 prompts, recall compilation, context
briefing, or agent context from the current repository.

## Fast Path

Compile task-scoped context from selected files:

```bash
vendor/bin/agent-loop recall compile \
  --root <learning-root> \
  --task <task-id> \
  --file <path>
```

Then inspect the state and run review:

```bash
vendor/bin/agent-loop workflow status <task-id>
vendor/bin/agent-loop review blindspots <task-id>
vendor/bin/agent-loop review code <task-id>
```

## What Recall Compile Does

`recall compile` selects task-scoped context from the files you pass and writes
artifacts under `recall/<task-id>/` (the default when `--output-dir` is not set):

- `system.md` — compiled briefing for an agent or harness
- `validation-plan.md` — validation steps derived from the task scope
- `recall-log.draft.json` — structured log of what was compiled
- `meta.json` — metadata and output hash used by `agent-loop verify`

Check that `recall/<task-id>/meta.json` exists after compiling:

```bash
ls recall/<task-id>/
```

## Warning: Artifacts Are Not Auto-Injected

> Recall artifacts are not injected into ChatGPT, Codex, Claude, Copilot,
> Gemini, or Antigravity automatically.

After a successful compile, `agent-loop` prints:

```text
[NOTE] Recall artifacts were written for review or harness ingestion.
[ACTION REQUIRED] Pass system.md / validation-plan.md into your agent workflow manually
unless your harness consumes them automatically.
```

You must explicitly read or pass `system.md` and `validation-plan.md` into
your active workflow. They are review inputs or harness inputs, not
automatically executed agent actions.

## When To Recompile

Recompile when important files changed since the last compile. Stale context
misleads the review and verify gates. `agent-loop verify` checks that the
output hash in `meta.json` still matches the artifacts on disk — a mismatch
means the briefing was edited or regenerated out of band.

## Review After Compiling

Use `review blindspots` and `review code` after implementation or before
close, not as a substitute for implementation:

```bash
vendor/bin/agent-loop review blindspots <task-id>
vendor/bin/agent-loop review code <task-id>
```

Review output is deterministic. It is not human approval. It does not approve
code or durable learning.

## Log Outcomes After Work

Log outcomes only after actual work happened:

```bash
vendor/bin/agent-loop recall log-outcome \
  --root <learning-root> \
  --by <actor> \
  --commit <sha>
```

Do not log outcomes before the work is done.

## Validation

- Check `recall/<task-id>/meta.json` exists
- Verify generated artifacts were inspected before use
- Run `vendor/bin/agent-loop verify` to confirm the briefing is not stale

## Skill Boundary

This skill owns:

- compiling and using recall/L2 context from the current repository
- understanding what recall compile writes and where
- knowing that artifacts are review/harness inputs, not auto-executed
- recompile discipline when files change

This skill does not own:

- the task opening step (see `agent-loop-task-start`)
- the review and close steps (see `agent-loop-review-close`)
- developing `agent-loop` itself

## Example Triggers

- "Run the L2 meta prompt for this repo."
- "Compile recall context from these files."
- "Use the generated validation plan before coding."
- "Review blind spots from the compiled context."
