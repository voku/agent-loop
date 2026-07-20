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
  --root <learning-root-path> \
  --task <task-id> \
  --file <path-to-file-1> \
  --file <path-to-file-2>
```

Then inspect the bounded working view:

```bash
vendor/bin/agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000
vendor/bin/agent-loop workflow status <task-id>
```

`workflow context` reads existing brief, session, recall, validation, and map
artifacts. It is read-only: it never recompiles recall, refreshes a map, or
embeds source bodies.

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

## ctx Versus Recall

Use `ctx` when you need to search prior local agent sessions for historical
raw material:

```bash
ctx search "<task / module / error / command>"
ctx show event <ctx-event-id> --window 5
```

Use recall compile when you need approved task guidance selected from
agent-learning artifacts. ctx hits are not durable memory and are not
automatically trusted by recall.

If the default recall output location belongs to another active task, compile
into a task-specific output directory instead of trampling it:

```bash
vendor/bin/agent-loop recall compile \
  --root <learning-root-path> \
  --task <task-id> \
  --output-dir recall/<task-id> \
  --file <path-to-file-1>
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

## Compact Map Locations

`map` is a plain lookup tool, not something gated behind `workflow
plan/approve` -- reach for it any time a task needs "where is this
class/method defined" or "what else references it" across more than one or
two files, the same way you'd reach for `rg`. Use it as a local query index
rather than a broad prompt dump or a multi-file `grep` sweep:

```bash
vendor/bin/agent-loop init tools
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map query SomeClass
vendor/bin/agent-loop map related SomeClass
vendor/bin/agent-loop map stale
vendor/bin/agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000
```

Run `init tools` first (it caches its result, so this is cheap even when run
at the start of most sessions): it reports whether `rg` is available and
whether an `agent-map` index already exists, so you are not guessing or
re-discovering that from scratch every time. The default
`.agent-map/php-symbols.json` is generated navigation state and must be
ignored by the host repository. The context command reports a missing,
invalid, or budget-omitted map section instead of silently rebuilding it.

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
  --draft recall/<task-id>/recall-log.draft.json \
  --by <actor> \
  --commit <sha>
```

`recall-log.draft.json` is one of the files `recall compile` writes under
`recall/<task-id>/`. Pass the path matching the task whose outcome you
are logging. Do not log outcomes before the work is done. For a governed
`done` close, every selected guidance item needs an explicit truthful outcome.

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
