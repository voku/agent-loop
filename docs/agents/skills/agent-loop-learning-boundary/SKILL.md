---
name: agent-loop-learning-boundary
description: Handle reusable knowledge that surfaces during a task — capture findings, move them through the proposal pipeline, and respect the boundary between workflow evidence and durable guidance.
---

# Agent Loop Learning Boundary

Use this skill after closing a task when the work surfaced something reusable:
a pattern that should not be rediscovered, a constraint that blocked progress
and will block again, or a rule that should survive past this session.

The boundary is simple: **findings are not durable memory.** This skill exists
to prevent an agent from crossing that boundary silently.

`ctx` may provide historical evidence for a finding, but it is not another
memory system. Treat ctx hits as raw local session material until inspected and
checked against current repository evidence.

## Fast Path

After closing a task that surfaced reusable knowledge:

Log the outcome against the learning root:

```bash
vendor/bin/agent-loop recall log-outcome \
  --root <learning-root> \
  --draft recall/<task-id>/recall-log.draft.json \
  --by <actor> \
  --commit <sha>
```

`recall-log.draft.json` is produced by `recall compile` (or `workflow start`)
under `recall/<task-id>/`. Use the same `<task-id>` you passed to `workflow start`.

Validate the learning root to confirm no drift:

```bash
vendor/bin/agent-loop learn validate --root <learning-root>
```

If the repository maintains a `MEMORY.md` promotion queue, run the human
review command:

```bash
vendor/bin/agent-loop memory review --file=MEMORY.md
```

The memory review command reports entries that need promotion review. It does
not edit `MEMORY.md` itself. Promotion remains a manual human edit.

## The Boundary Rule

```
finding → proposal → reviewed decision → durable guidance
```

Each arrow is a gate:

- A **finding** is an observation from the task. It is not a rule.
- A **proposal** is a structured candidate derived from a finding. It is still
  not a rule.
- A **reviewed decision** is a proposal approved by a named actor with
  `learn proposal-approve --by <actor>`. It becomes durable guidance only
  after that gate.
- Nothing in the `agent-loop` CLI auto-promotes findings or proposals to
  durable guidance.

Closing a task with `workflow close --status done` does not promote anything.
Running `review blindspots` does not promote anything. Recording session
checkpoints does not promote anything.

## When a Finding Exists

If the task produced a finding worth carrying forward, log it then validate
the root:

```bash
vendor/bin/agent-loop recall log-outcome \
  --root <learning-root> \
  --draft recall/<task-id>/recall-log.draft.json \
  --by <actor> \
  --commit <sha>

vendor/bin/agent-loop learn validate --root <learning-root>
```

`recall-log.draft.json` is the file `recall compile` (or `workflow start`) writes
under `recall/<task-id>/`. If you ran `workflow start <task-id>`, the draft
is already at that path.

If the host repo uses the proposal pipeline, validate the candidate:

```bash
vendor/bin/agent-loop learn proposal-validate \
  --proposal proposals/candidate/proposal.001.json
```

Do not approve proposals yourself. Approval requires a named human actor:

```bash
vendor/bin/agent-loop learn proposal-approve \
  --by <human-actor> \
  --root <learning-root> \
  proposal.001
```

The proposal argument is the bare filename without path or `.json`. A file at
`<learning-root>/proposals/candidate/proposal.001.json` has ID `proposal.001`.

`--by` must name a person, not an agent. An agent recording its own approval
is not a reviewed gate.

## ctx Evidence Handoff

When a ctx result materially supports, challenges, or explains a finding,
record it as bounded `agent_history_reference` evidence when the host learning
root supports that evidence type:

```json
{
  "type": "agent_history_reference",
  "source": "ctx",
  "ctx_session_id": "ses_or_uuid",
  "ctx_event_id": "evt_or_uuid",
  "query": "task module error command",
  "retrieved_at": "2026-07-05T12:00:00+02:00",
  "summary": "Bounded reviewed summary of what the inspected event showed.",
  "verification_status": "inspected"
}
```

Use `verification_status=inspected` only after opening the event or session.
Use `found`, `rejected`, or `stale` for search bookkeeping that should not
count as validation-heavy support. Never copy raw transcripts or secret-shaped
strings into findings.

## When Not To Capture A Finding

Not every task produces a finding worth carrying forward. Skip the learning
step when:

- the fix was purely local and will not recur
- the pattern already exists in `docs/agents/`, `README.md`, or the learning
  root
- the observation is only valid for this repo's specific state
- the task was a one-off that should not influence future agent behavior

Check existing guidance before adding a new entry:

```bash
vendor/bin/agent-loop learn guidance-evaluate --root <learning-root>
```

If the lesson is already there, skip the capture step entirely.

## MEMORY.md Promotion

If the repository maintains a `MEMORY.md` queue:

```bash
vendor/bin/agent-loop memory review --file=MEMORY.md
```

This command reports rows that appear ready for promotion. It does not
approve, rewrite, or auto-promote them. A human reads the report and edits
`MEMORY.md` directly.

Do not add raw task output, session logs, or unreviewed proposals to
`MEMORY.md`.

## Guidance Evaluation

To confirm existing learning root guidance is still coherent after the task:

```bash
vendor/bin/agent-loop learn guidance-evaluate --root <learning-root>
```

This is a read-only check. It does not write findings, modify proposals, or
change durable guidance.

## What These Commands Do Not Do

- None of the `learn`, `recall`, `memory`, or `review` commands call an LLM.
- None of them auto-approve durable guidance.
- None of them replace human review of findings.
- `review blindspots` writes reports; it does not promote learning.
- `recall log-outcome` records evidence; it does not create durable rules.
- `memory review` reads the queue; it does not edit `MEMORY.md`.

## Skill Boundary

This skill owns:

- the step after `agent-loop-review-close` when reusable knowledge surfaces
- the rule that findings are not durable memory
- the `recall log-outcome` → `learn validate` → optional proposal pipeline
- the human promotion gate for `MEMORY.md`
- when to skip the learning step entirely

This skill does not own:

- starting a task (see `agent-loop-task-start`)
- recording progress during a task (see `agent-loop-task-progress`)
- review and close (see `agent-loop-review-close`)
- developing `agent-loop` itself (see `agent-learning` in this repo)

## Validation

- `vendor/bin/agent-loop learn validate --root <learning-root>` exits without
  error
- `vendor/bin/agent-loop learn guidance-evaluate --root <learning-root>` runs
  cleanly
- no proposals were self-approved by the agent
- `MEMORY.md` (if it exists) was not edited by the agent without explicit
  instruction

## Example Triggers

- "This should become portable guidance."
- "Capture what we learned from this task."
- "Is this finding ready to promote?"
- "Log the outcome after closing."
- "Should this go into MEMORY.md?"
