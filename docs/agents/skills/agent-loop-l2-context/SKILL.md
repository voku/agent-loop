---
name: agent-loop-l2-context
description: Compile and use agent-loop recall/L2 meta-prompt artifacts from the current repository without mistaking generated context for executed agent actions.
---

# Agent Loop L2 Context

Use this skill when the task asks for L2 prompts, recall compilation, context
briefing, reusable operational prompt recipes, or agent context from the current
repository.

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

For governed starts, put an intentionally small, Git-tracked
`recall-documents.json` beside the learning root. `workflow approve` forwards
it to recall automatically; use explicit scopes and excerpt limits instead of
making every skill or ADR global context. A manifest entry may also declare
`tags`; it is then selected when its path scope overlaps the task's files
**or** when it shares a tag with `--tag` values passed to `workflow plan`,
independent of directory layout.

## What Recall Compile Does

`recall compile` selects task-scoped context from the files you pass and writes
artifacts under `recall/<task-id>/` (the default when `--output-dir` is not set):

- `system.md` — compiled briefing for an agent or harness
- `validation-plan.md` — validation steps derived from the task scope
- `recall.bundle.json` — canonical task snapshot with source digests and resolved facts
- `facts.json` — compact structured task, board, map, document, and prompt-recipe facts
- `selection-report.json` — deterministic learning/constraint selection explanation
- `recall-log.draft.json` — structured log of what was compiled
- `meta.json` — metadata and output hash used by `agent-loop verify`

Check that `recall/<task-id>/meta.json` exists after compiling:

```bash
ls recall/<task-id>/
```

## Reusable Operational Prompt Recipes

When `recall compile` receives an operating-prompt manifest and a selected
recipe, the recipe is compiled beside the same project context instead of being
used as a generic standalone prompt.

The intended flow is:

```text
L2 recipe + recall context -> project-specific L1 operating prompt -> execution
```

Most reusable engineering recipes should be L2. The shared recipe owns the
method and quality floor; recall owns the project context: exact files, symbols,
callers, tests, project documents, constraints, validation commands, task state,
and known risks.

Example:

```bash
vendor/bin/agent-loop recall compile \
  --root <learning-root-path> \
  --task <task-id> \
  --file src/Parser.php \
  --file tests/ParserTest.php \
  --operating-prompt-manifest <path-to-operating-prompts.json> \
  --operating-prompt '{"id":"coverage-mutation","arguments":{"minimum_percentage_points":10,"mutation_command":"vendor/bin/infection --threads=max"}}'
```

If `system.md` contains `## L2 Operational Prompt Construction`, use a strict
two-pass boundary:

1. **Prompt construction pass:** read the compiled recall context and construct
   one project-specific L1 prompt with exactly `Goal`, `Context`, `Constraints`,
   and `Done When`. Preserve numeric floors and stopping conditions. Replace
   generic placeholders with exact repository facts when available. Do not
   implement the task in this pass.
2. **Execution pass:** execute the resulting L1 prompt as the task contract.
   Validate against `validation-plan.md` and the repository-specific evidence
   named by the generated prompt.

Do not collapse the two passes into "understand the intent and start coding".
That throws away the point of L2: first turn reusable policy plus project facts
into a concrete operating contract, then execute that contract.

A recipe with `level: 1` is already an execution contract and may be applied
directly. Typical L1 recipes are context-independent control rules such as
bounded retries or evidence-report formatting.

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

For L2 prompt recipes this warning is especially important: the compiled L2
section is an instruction to construct a project-specific L1 prompt. The
presence of that section does not prove the L1 prompt was constructed, and the
presence of an L1 prompt does not prove it was executed.

## Compact Map Locations

`map` is a plain lookup tool, not something gated behind `workflow
plan/approve` -- reach for it any time a task needs "where is this
class/method defined" or "what else references it" across more than one or
two files, the same way you'd reach for `rg`. Use it as a local query index
rather than a broad prompt dump or a multi-file `grep` sweep:

```bash
vendor/bin/agent-loop init tools
vendor/bin/agent-loop map build --paths=src,tests   # once; then: map refresh
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

## Direct Edit Routing

For a one-for-one literal replacement inside one exact PHP method, do not
spend model tokens on source rediscovery. Prefer the token-safe `auto` route:

```bash
vendor/bin/agent-loop edit 'App\Service\UserService::save' \
  --runner=auto \
  --replace-old='$legacyUser->regionId' \
  --replace-new='$legacyUser->getCurrentRegionId()' -- \
  'Replace the deprecated region property exactly once.'
```

With both replacement literals, `auto` selects the scoped mechanical runner:
it requires one in-method match, checks the map hash before writing, runs PHP
lint, reverts a lint failure, and records zero model tokens/tool calls. Without
that proof, `auto` records `escalation_required` and does not invoke a model.
Use `edit --focus=... --runner=command` only when the replacement needs PHP
judgment beyond a literal substitution; it supplies a narrow source window and
keeps callers/dependencies out unless you intentionally omit the focus.

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
- For L2 recipes, verify a project-specific L1 prompt was constructed before execution
- Run `vendor/bin/agent-loop verify` to confirm the briefing is not stale

## Skill Boundary

This skill owns:

- compiling and using recall/L2 context from the current repository
- reusing L2 prompt recipes to construct project-specific L1 contracts
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
- "Use the coverage-mutation recipe for this task."
- "Turn this reusable prompt recipe into a project-specific operating prompt."
- "Use the generated validation plan before coding."
- "Review blind spots from the compiled context."
