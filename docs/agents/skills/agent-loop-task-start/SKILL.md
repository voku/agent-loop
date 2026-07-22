---
name: agent-loop-task-start
description: Start a governed agent-loop task in the current repository, create session working memory, approve a sealed work brief, and compile deterministic recall/L2 context.
---

# Agent Loop Task Start

Use this skill when beginning a task in a repository that has `agent-loop`
installed and you need to open a governed workflow, create session working
memory, approve a scoped work brief, and compile a recall briefing from that
sealed input before editing code.

## Fast Path

Prefer the governed work-brief path:

```bash
vendor/bin/agent-loop workflow plan <task-id> \
  --by <actor> \
  --learning-root <learning-root-path> \
  --file <path-to-file-1> \
  --file <path-to-file-2> \
  --goal "Implement the approved task." \
  --non-goal "Do not widen the task without a revised brief." \
  --validation "vendor/bin/phpunit tests/FocusedTest.php"
```

`workflow plan` starts session working memory and creates a candidate work
brief. It intentionally does **not** compile recall yet. A named human must
approve the exact revision before
implementation. Inspect the result immediately:

```bash
vendor/bin/agent-loop workflow approve <task-id> --by <human-actor>
vendor/bin/agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000
vendor/bin/agent-loop workflow status <task-id>
```

## Historical Context Preflight

Before opening a non-trivial, repeated, or failure-driven task, use `ctx` if it
is installed to check whether prior local agent sessions contain relevant
decisions, failed attempts, commands, or review context:

```bash
ctx status
ctx sources
ctx search "<task / module / error / command>"
ctx show event <ctx-event-id> --window 5
```

Use ctx as historical source material only. It does not replace `workflow
start`, recall compile, current repository inspection, or validation. If ctx
material affects a finding, cite it as bounded `agent_history_reference`
evidence with inspected IDs and a summary; do not paste raw transcripts.

## Task ID

Use the ticket or issue id from your board (e.g. `ABC-123`, `PROJ-42`).
If no external id exists, choose a stable local id such as `LOCAL-001` and
keep it for the life of the task — do not generate a new one on each run.
Ask the host workflow or board if you are unsure what id to use.

## Choosing Files

Select files intentionally. Recall compiles context from what you pass; it
does not dump the entire repository into the briefing.

Good candidates:

- the task description file (`tasks/ABC-123.md`)
- the failing test or the test that covers the change
- the implementation file most directly affected
- architecture or decision notes that constrain the change
- the relevant skill or doc if guidance is part of the scope

Pass a small set of relevant files with repeated `--file` options instead of
trying to summarize the whole repository. Do not pass every file.

The initial `--file` values become the approved scope unless explicit
`--scope` values replace them. A later plan revision clears approval, so obtain
a new approval before working outside the current scope.

## Optional Map Preflight

When source navigation would otherwise require broad reads, build the compact
map before rendering workflow context:

```bash
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map stale
```

The default `.agent-map/` output is generated navigation state. Confirm it is
ignored; never force-add the index. `workflow context` reads an existing index
but never builds one itself.

## Validation After Start

```bash
vendor/bin/agent-loop workflow status <task-id>
vendor/bin/agent-loop verify
```

`workflow status` confirms the session, recall, brief, and approval state.
`verify` confirms cross-package consistency from the start.

## Lower-Level Fallback

Use this only when you need direct control over session and recall separately:

```bash
vendor/bin/agent-loop session start --task <task-id> --by <actor> --base-commit "$(git rev-parse HEAD)"
vendor/bin/agent-loop recall compile \
  --root <learning-root-path> \
  --task <task-id> \
  --file <path-to-file-1> \
  --file <path-to-file-2>
```

`session start` prints a date-prefixed session id on its first line.
`recall compile` without `--output-dir` writes to `recall/<task-id>/`
automatically, which is where `agent-loop verify`'s recall-coverage check
expects to find it.

## Recall Output Is Not Auto-Injected

`recall compile` writes files (`system.md`, `validation-plan.md`,
`recall-log.draft.json`, `meta.json`) under `recall/<task-id>/`.
These artifacts are not automatically passed into any coding agent.
After compiling, read or pass them into your workflow manually.

## Skill Boundary

This skill owns:

- the opening step of a governed agent-loop task in a consuming repository
- choosing a task id, actor, learning root, file scope, non-goals, and validation commands
- understanding that `workflow plan` creates a candidate brief and `workflow approve` compiles recall from its approved revision
- obtaining human approval before implementation and inspecting the bounded context
- inspecting initial state with `workflow status` and `verify`

This skill does not own:

- the review and close steps (see `agent-loop-review-close`)
- L2 context recompilation during a task (see `agent-loop-l2-context`)
- developing `agent-loop` itself

## Example Triggers

- "Start an agent-loop task for this change."
- "Compile context for this repo before editing."
- "Use agent-loop for this task."
