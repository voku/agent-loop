---
name: agent-loop-workflow
description: Operate the ordinary governed agent-loop path by routing through enter/finish and obeying the lifecycle kernel's structured next step instead of reproducing workflow policy in host prose.
---

# Agent Loop Workflow

Use this skill for the ordinary governed coding path. The lifecycle kernel owns
what is legal next. This skill routes into that kernel and presents its result;
it does **not** keep a second phase machine, gate list, repair table, or owner
artifact checklist.

Persisted owner evidence is authoritative. Conversation prose is not.

## Ordinary Host Contract

Start or resume through the front door:

```bash
vendor/bin/agent-loop enter <task-id> --format=json
```

Read the structured result, especially:

- `mutation_ready` — whether host-native implementation work is currently authorized;
- `next_action_kind` — how to treat the canonical next step;
- `next_action` — the one decisive next step;
- `manifest.references` — supporting owner-backed evidence and reasons.

`next_action_kind` has one treatment contract:

- `command` — execute `next_action` as written;
- `decision_required` — the action is a command template; supply the missing
  model and/or human decision values before executing it. Never fabricate a
  human approval or risk owner;
- `host_work` — perform the described host-native implementation/model work;
  the text is not a shell command;
- `none` — no further lifecycle action is required.

Do not combine sibling fields to invent a different workflow decision. If a
canonical command refuses deterministically without changing the next step,
record that as a lifecycle defect rather than teaching the host a workaround.

When host-native mutation is complete, reconcile deterministic close-out through:

```bash
vendor/bin/agent-loop finish <task-id> --format=json
```

Then obey the returned `next_action_kind` / `next_action` in the same way until
`none` / complete. Repeated `enter` and `finish` calls are intended to reconcile
current owner evidence; hosts should not reproduce their preconditions.

## Planning And Human Authority

A genuinely new task may cause `enter` to return a `decision_required` PLAN
command template. Fill its goal, scope/files, validation and other selected task
intent from the actual request and repository evidence. `agent-loop-task-start`
contains guidance for choosing those Contract inputs.

Approval is authority-bearing. When the canonical next step asks for approval,
obtain the named human decision instead of self-approving. Approval records
Contract authority only; deterministic Run, Session and Recall preparation lives
behind `enter`.

Do **not** pre-emptively build a map, compile Recall, create a Session, select a
repair command, or walk a remembered phase sequence because this skill once
listed one. If discovery or another deterministic prerequisite is required, the
kernel must surface the owner-backed repair as the canonical next step.

## Implementation

When `mutation_ready` is true or `next_action_kind=host_work`, perform the
smallest correct change inside the approved scope. Apply `agent-loop-discipline`
and use repository-native tests/static analysis. Use specialist skills such as
`agent-loop-investigate`, `agent-loop-l2-context`, or `agent-loop-surgical-edit`
only when the task actually needs those capabilities; they are not mandatory
happy-path phases.

Generated maps and other derived artifacts are navigation/evidence, never a
second source of lifecycle authority. Query real source after navigation selects
it.

## Validation, Review, Learning And Close

`finish` owns deterministic close-out choreography and reports the first
currently actionable obligation. Do not restate which validation, review,
Learning, Recall, integrity, or close gates must pass here; that list has changed
before and a prose copy will drift again.

When `finish` requests a decision or specialist action, satisfy exactly that
canonical step and call `finish` again. Examples of values that may still require
judgment include review acknowledgement, Learning disposition, accepted risk, or
re-planning changed intent. The kernel owns when those decisions apply.

`agent-loop verify`, `workflow status`, `workflow report`, and reflection remain
useful diagnostic/read-only surfaces when needed. They are not another mandatory
happy-path sequence beside `enter -> host work -> finish`.

## Re-planning

When goal, scope, policy, or acceptance intent genuinely changes, re-plan rather
than stretching the approved Contract conversationally. Ask the lifecycle kernel
for the current state first and follow its canonical next step. Do not manually
retire Session/Run/Recall state from host prose; deterministic supersession and
reconciliation belong to the lifecycle owners.

## Optional Reflection

Reflection is optional scrutiny, not another gate. Use it only when the current
lifecycle state allows it and extra task/project reasoning is useful:

```bash
vendor/bin/agent-loop workflow reflect <task-id> --scope task
vendor/bin/agent-loop workflow reflect <task-id> --scope project
```

If reflection exposes a concrete defect, feed that evidence back through the
ordinary lifecycle instead of inventing a parallel reflection state machine.

## Evidence Discipline

Keep complete source/diff/test/static-analysis and generated verification
evidence. Summaries help navigation but do not replace evidence. Findings are not
durable guidance until the Learning owner accepts the appropriate promotion
boundary.

Do not ask humans to run reads, edits, tests, or reports the host can run. Human
interaction is reserved for real authority, ambiguity, irreversible actions, and
explicit risk ownership.

## Progress Receipt

After meaningful progress, report only verified state:

```text
RESULT: <verified result>
STATE: <current persisted lifecycle state>
NEXT: <canonical next step or explicit human gate>
```

Derive `STATE` and `NEXT` from the structured lifecycle result, never from a
remembered phase diagram.
