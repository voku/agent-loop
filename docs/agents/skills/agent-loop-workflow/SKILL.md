---
name: agent-loop-workflow
description: Use the governed agent-loop workflow for this repository, including when to prefer workflow start/status/close, when to drop to session or recall commands, and how init validation fits repo-managed agent guidance changes.
---

# Agent Loop Workflow

Use this skill when the task is about how to operate `agent-loop` itself:
starting work, compiling recall context, running review and verify, closing a
task safely, or updating the repo-managed agent guidance around that flow.

## Fast Path

For the normal governed loop:

1. For non-trivial or repeated work, search prior local agent history with `ctx` if it is installed.
2. Use `agent-loop workflow plan` with an explicit goal, scope, non-goals, and validation commands.
3. Present the candidate brief to a named human, then run `agent-loop workflow approve`.
4. Build a map first when compact source locations matter, then inspect `agent-loop workflow context`.
5. Do the implementation work and record only the decisions or checkpoints that matter.
6. Record passed validation evidence against the current brief revision.
7. Run `agent-loop review blindspots <task-id>`, record truthful recall outcomes, and make a learning decision.
8. Run `agent-loop verify` and `agent-loop workflow report <task-id>`.
9. Close with `agent-loop workflow close <task-id> --status done` only when every gate passes.

If the task changed repo-managed agent guidance, also run:

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
```

## Skill Boundary

This skill owns:

- how to use `workflow plan`, `workflow approve`, `workflow context`, `workflow status`, `workflow report`, and `workflow close`
- when lower-level `session`, `recall`, `review`, `verify`, `learn`, and `memory` commands are the right seam
- the boundary between workflow evidence and durable guidance
- the current repo-managed guidance validation loop under `docs/agents/skills/`

This skill does not own:

- host-repo client-specific runtime assumptions beyond the documented `init` sync targets
- pretending recall artifacts are auto-injected into an agent
- durable-memory approval or auto-promotion

## Canonical Files

- `README.md`
- `docs/workflow/learning-boundary.md`
- `docs/agents/INFO_Agents.md`
- `docs/agents/skills/agent-guidance-maintenance/SKILL.md`
- `docs/agents/skills/agent-learning/SKILL.md`

## When To Use

Use this skill when the task:

- asks how this repository's workflow is meant to be used
- needs the correct command sequence for a governed task
- changes workflow-facing docs or repo-managed skills
- needs the boundary between workflow, review, learning, and memory stated clearly

Do not use this skill for unrelated library implementation work.

## Workflow

### 1. Prefer the high-level workflow command first

Default entrypoint:

```bash
agent-loop workflow plan <task-id> \
  --by <actor> \
  --learning-root <path> \
  --file <path> \
  --goal "Implement the approved task." \
  --validation "vendor/bin/phpunit tests/FocusedTest.php"

agent-loop workflow approve <task-id> --by <human-actor>
agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000
```

Use lower-level commands only when you need direct control:

- `session` for working-memory records and checkpoints
- `session validation record` for revision-bound execution evidence
- `session learning decide` for the explicit outcome required before a `done` close
- `recall compile` when debugging briefing inputs or output layout
- `review blindspots` for the required review artifact before close
- `verify` for the cross-package consistency gate
- `learn` for findings and reviewed guidance work after the task
- `memory review` for human durable-memory promotion review
- `init sync-*` when the task changes repo-managed agent assets that must be copied into a client target

### 2. Keep the workflow boundary honest

- `workflow plan` starts/reuses session memory and records a candidate work brief; `workflow approve` seals that revision and compiles recall from it.
- `workflow approve` records a named human's approval of that exact revision; a re-plan invalidates it.
- `workflow context` is read-only and budgeted. It never rebuilds recall or a map.
- `workflow start` remains the lower-level session-plus-recall entrypoint for hosts that deliberately do not use work briefs.
- `workflow status` is read-only.
- `workflow report` is read-only; pass observed changed paths explicitly because it does not run Git.
- `workflow close --status done` requires an approved current brief, passed evidence for its exact validation commands, recall metadata and outcomes for selected guidance, a blind-spot review, an explicit learning decision, and a passing `verify`.
- Recall output is written to disk; it is not auto-injected into a coding agent.
- Learning artifacts are not durable memory by default.
- ctx hits are historical raw material, not recall output or durable memory.

Read `docs/workflow/learning-boundary.md` when the task touches learning or
memory promotion.

### 3. Use ctx only as inspected historical evidence

When prior sessions may matter:

```bash
ctx status
ctx sources
ctx search "<task / failure / module / command>"
ctx show event <ctx-event-id> --window 5
```

Inspect focused hits before relying on them. Do not paste raw transcripts into
findings, reports, skills, or PR text. When ctx affects a finding, store only a
bounded `agent_history_reference` with the ctx IDs, query, reviewed summary,
retrieval time, and verification status.

### 4. Treat generated map output as disposable navigation state

When the task needs compact symbol locations, build the index before rendering
workflow context:

```bash
agent-loop map build --paths=src,tests
agent-loop map stale
agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000
```

The default index is `.agent-map/php-symbols.json`. Ensure `.agent-map/` is
ignored before building it. The index guides file/range selection; it is not
source, durable memory, or an artifact to paste into a prompt.

### 5. Use RTK at the shell boundary the agent actually sees

Before documenting or relying on `rg`-first search guidance, verify ripgrep is
installed:

```bash
rg --version
```

When validating this repo or a host repo, prefer RTK-wrapped commands at the
outer shell boundary:

```bash
rtk git status
rtk test vendor/bin/phpunit --filter Init
rtk test vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
```

If a host workflow hides noisy work behind `make`, `docker compose exec`, or a
wrapper script, keep RTK guidance explicit in `AGENTS.md`, `README.md`, and
agent-facing Make targets instead of assuming nested output will be compressed
automatically.

## Validation

- `vendor/bin/agent-loop init validate --kind=skills`
- `vendor/bin/agent-loop init validate --kind=all`
- `vendor/bin/agent-loop init sync-skills --agent=codex --dry-run`
- `vendor/bin/agent-loop init doctor`
- `vendor/bin/phpunit --filter 'Init|DispatcherTest'`
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`

## Example Triggers

- "How do I use the workflow of this project?"
- "Which command sequence should I run before workflow close?"
- "Document the right loop for session, recall, review, and verify."
