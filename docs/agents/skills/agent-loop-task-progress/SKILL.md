---
name: agent-loop-task-progress
description: Record useful working memory during an agent-loop task, including decisions, checkpoints, validation evidence, scope changes, and blocked states without copying or rewriting raw evidence.
---

# Agent Loop Task Progress

Use this skill while implementing a started task and before review or closure.
Record only information another agent or human would otherwise have to
rediscover.

For PHP implementation work, also use `agent-loop-php-discipline`.

## Fast Path

Record an implementation decision:

```bash
vendor/bin/agent-loop session record <task-id> \
  --kind decision \
  --title "Keep change scoped" \
  --body "Only update dispatcher routing; recall compiler behavior is unchanged."
```

Record a checkpoint:

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Validation" \
  --body "vendor/bin/phpunit --filter Init passed with exit code 0."
```

Inspect current state:

```bash
vendor/bin/agent-loop session show <task-id>
vendor/bin/agent-loop workflow status <task-id>
```

## Record

- decisions that affect implementation direction;
- assumptions that future work must verify or preserve;
- validation commands and observed results;
- scope changes;
- blockers and their cause;
- explicit trade-offs;
- risky shortcuts accepted or rejected, with reason;
- handoff information.

Do not record:

- unbounded logs;
- giant diffs;
- complete transcripts;
- copied stack traces when one exact line and an artifact path suffice;
- vague notes such as "fixed stuff";
- secrets, credentials, production data, or secret-shaped values.

## Checkpoint Timing

Checkpoint after:

1. selecting the implementation approach;
2. touching risky code or a public contract;
3. each meaningful validation run;
4. changing scope;
5. reaching review readiness;
6. becoming blocked.

## Scope Changes

Record scope drift immediately:

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Scope change" \
  --body "Task expanded from docs-only to docs plus init help because the executable contract was stale."
```

Then inspect status. Re-plan when the approved brief no longer describes the
work. A re-plan creates a new revision and invalidates old validation evidence
for closure.

## Structured Validation Evidence

A prose checkpoint explains progress but does not satisfy a governed `done`
close. Record every exact command from the current brief after it runs:

```bash
vendor/bin/agent-loop session validation record <task-id> \
  --brief-revision <current-revision> \
  --command "vendor/bin/phpunit tests/FocusedTest.php" \
  --status passed \
  --exit-code 0 \
  --by <actor>
```

Use the exact command string from the brief. Add duration only when measured.
Never claim a pass from an agent summary, missing output, or a previous
revision.

## Noise Control And Evidence Integrity

Keep session memory compact, but do not make raw evidence compact by rewriting
it.

- Summarize the finding and reference the exact command or artifact path.
- Preserve source, full diffs, test output, static-analysis output, and generated
  verification files unchanged.
- When a harness stores large output in a file, reference and read that raw file.
- Add a hash, size, or line count when completeness matters.
- A summary supports human navigation. It is not a substitute for code review or
  diagnostic evidence.

Prefer `rg` for repository search after confirming it exists:

```bash
rg --version
```

## Before Review And Close

Record a final checkpoint:

```bash
vendor/bin/agent-loop session checkpoint <task-id> \
  --title "Ready for review" \
  --body "Implementation complete; full diff reviewed; required validation passed."
```

Then run:

```bash
vendor/bin/agent-loop review blindspots <task-id>
vendor/bin/agent-loop verify
vendor/bin/agent-loop workflow status <task-id>
```

Record the explicit learning outcome:

```bash
vendor/bin/agent-loop session learning decide <task-id> \
  --status no_durable_learning \
  --by <actor> \
  --reason "No reusable finding from this bounded task."
```

This records an outcome; it does not create or approve durable guidance.

## Skill Boundary

This skill owns compact working-memory records, checkpoints, validation notes,
scope changes, blockers, and handoffs.

It does not own task planning, L2 context compilation, final review and closure,
durable-memory promotion, or lossy transformation of evidence.

## Validation

- `session show` contains useful, bounded notes;
- `workflow status` resolves the current task and brief revision;
- exact required validation evidence is recorded;
- a final review-ready checkpoint exists;
- no secrets or raw unbounded output were stored.
