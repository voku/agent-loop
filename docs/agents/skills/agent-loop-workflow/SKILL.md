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
2. Start with `agent-loop workflow start <task-id> --by <actor> --learning-root <path> --file <path>`.
3. Do the implementation work and record only the decisions or checkpoints that matter.
4. Run `agent-loop review blindspots <task-id>` before closing.
5. Run `agent-loop verify`.
6. Inspect with `agent-loop workflow status <task-id>`.
7. Close with `agent-loop workflow close <task-id> --status done`.

If the task changed repo-managed agent guidance, also run:

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
```

## Skill Boundary

This skill owns:

- how to use `workflow start`, `workflow status`, and `workflow close`
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
agent-loop workflow start <task-id> --by <actor> --learning-root <path> --file <path>
```

Use lower-level commands only when you need direct control:

- `session` for working-memory records and checkpoints
- `recall compile` when debugging briefing inputs or output layout
- `review blindspots` for the required review artifact before close
- `verify` for the cross-package consistency gate
- `learn` for findings and reviewed guidance work after the task
- `memory review` for human durable-memory promotion review
- `init sync-*` when the task changes repo-managed agent assets that must be copied into a client target

### 2. Keep the workflow boundary honest

- `workflow start` wraps session start plus recall compile.
- `workflow status` is read-only.
- `workflow close --status done` is gated by recall metadata, blind-spot review, and a passing `verify`.
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

### 4. Use RTK at the shell boundary the agent actually sees

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
