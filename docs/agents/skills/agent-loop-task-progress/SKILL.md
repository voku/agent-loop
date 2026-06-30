---
name: agent-loop-task-progress
description: Record useful working memory during an agent-loop task, including decisions, checkpoints, validation results, scope changes, and blocked states.
---

# Agent Loop Task Progress

Use this skill while implementing a task after it has been started with
`agent-loop-task-start` and before it is closed with `agent-loop-review-close`.

## Fast Path

Record important decisions:

```bash
vendor/bin/agent-loop session record <task-id> \
  --kind decision \
  --title "Keep change scoped" \
  --body "Only update the dispatcher routing; do not change recall compiler behavior."
```

Record progress checkpoints:

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Validation" \
  --body "vendor/bin/agent-loop init validate --kind=skills passed."
```

Inspect current task memory:

```bash
vendor/bin/agent-loop session show <task-id>
vendor/bin/agent-loop workflow status <task-id>
```

## What To Record

Record:

- decisions that affect implementation direction
- assumptions that future agents must not rediscover
- validation results
- scope changes
- blockers and their cause
- explicit tradeoffs
- commands that materially changed confidence
- why a risky shortcut was not taken
- why a risky shortcut was taken, if it later becomes accepted risk

Do not record:

- raw unbounded logs
- giant diffs
- copied stack traces unless the exact line matters
- noisy command output
- vague notes like "fixed stuff"
- private secrets, tokens, cookies, credentials, or production data

## Record Kinds

Use `--kind decision` when the note changes or explains the direction of the work.

Use checkpoints for:

- validation
- implementation milestones
- review-readiness
- blocked state
- handoff to another agent or human

If the exact supported `--kind` values differ in a host repo version, prefer
the existing command help and keep the note type conservative.

## When To Checkpoint

Checkpoint:

1. after selecting the implementation approach
2. after touching risky code
3. after each meaningful validation run
4. before switching files or scope
5. before review/close
6. when blocked

## Scope Changes

If the task changes scope, record it immediately:

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Scope change" \
  --body "The task expanded from docs-only to docs plus init validate examples because the existing README list was stale."
```

Then run:

```bash
vendor/bin/agent-loop workflow status <task-id>
```

If the scope changed enough that recall context is stale, use
`agent-loop-l2-context` and recompile recall.

## Validation Notes

Good validation checkpoint:

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Validation" \
  --body "vendor/bin/phpunit --filter Init passed; vendor/bin/agent-loop init validate --kind=all passed."
```

Bad validation checkpoint:

```
tests ok
```

Be specific enough that the next agent knows what was actually run.

## Noise Control

Keep session memory compact. If command output is large, summarize the finding
and point to the command instead of pasting the full output.

Prefer RTK-wrapped commands in host repos when output is noisy:

```bash
rtk test vendor/bin/phpunit --filter Init
rtk err vendor/bin/phpstan analyse --memory-limit=1G
```

Do not assume RTK compresses nested Make or Docker output unless the host repo
documents that boundary.

## Before Review And Close

Before using `agent-loop-review-close`, record a final checkpoint:

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Ready for review" \
  --body "Implementation complete; recall was recompiled after docs changes; validation passed."
```

Then continue with:

```bash
vendor/bin/agent-loop review blindspots <task-id>
vendor/bin/agent-loop verify
vendor/bin/agent-loop workflow status <task-id>
```

## Skill Boundary

This skill owns:

- useful session records during implementation
- compact checkpoints
- validation notes
- scope-change notes
- blocked-state notes
- handoff notes

This skill does not own:

- starting the task (see `agent-loop-task-start`)
- compiling L2 context (see `agent-loop-l2-context`)
- review/verify/close (see `agent-loop-review-close`)
- durable learning promotion
- developing `agent-loop` itself

## Validation

- `vendor/bin/agent-loop session show <task-id>` shows useful notes
- `vendor/bin/agent-loop workflow status <task-id>` still resolves the task
- final checkpoint exists before review/close
- no secrets or giant logs were stored

## Example Triggers

- "Record this decision in the agent-loop session."
- "Checkpoint the validation result."
- "We changed scope; update the task memory."
- "Show the current session notes."
- "Prepare this task for review."
