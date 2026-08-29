---
name: agent-loop-review-close
description: Review and close a governed agent-loop task through the canonical finish front door while preserving explicit review and Learning inputs.
---

# Agent Loop Review Close

Use this skill after implementation when a governed Run needs correctness review, blind-spot review, explicit Learning disposition, verification, and close-out.

The lifecycle kernel owns deterministic close-out choreography. This skill must not copy validation, verification, or close sequences that can drift from `agent-loop finish`.

## Fast Path

Resolve current state first:

```bash
vendor/bin/agent-loop workflow status <task-id> --format=json
```

Run the review work that still requires agent judgment:

```bash
vendor/bin/agent-loop review code <task-id>
vendor/bin/agent-loop review blindspots <task-id>
```

Then call the canonical close-out front door with the explicit review/Learning inputs it requests:

```bash
vendor/bin/agent-loop finish <task-id> [finish inputs from next_action]
```

Treat the returned `next_action` and `next_action_kind` as authoritative for the next iteration. Do not replace them with a memorized sequence of `session validation record`, `workflow learn`, `verify`, `workflow close`, or `workflow status` mutations.

Completion is reached only when `finish` reports the Run complete and the resulting lifecycle projection has `next_action == "none"`.

## Review Boundary

`review code` is the primary correctness review for a governed task and must run before `review blindspots`. The blind-spot review is separate process/evidence analysis; neither review grants approval or substitutes for observed validation.

When there is no governed task/artifact set, use the context-light `review first-draft` flow instead of pretending a governed review exists.

The exact current review report identity must be acknowledged through the `finish` input exposed by the lifecycle kernel. Do not infer or hand-copy a stale digest.

## Learning Boundary

Choose exactly one evidence-backed Run Learning conclusion when `finish` asks for it:

- `findings_recorded`: reusable evidence-backed findings exist; reference the validated Finding ids requested by the front door;
- `no_durable_learning`: the evidence is task-local or already covered by authoritative guidance;
- `follow_up_required`: a concrete unresolved follow-up remains; provide the required follow-up reference.

Do not invent a Finding, a passing validation result, or `no_durable_learning` merely to make the Run complete.

The detailed Finding and durable-guidance lifecycle remains owned by `agent-learning`; this skill supplies explicit close-out judgment only.

## Recall Outcome

When the current workflow explicitly requires a Recall outcome, record the truthful outcome using the Recall-owned surface and then return to `finish`. Do not make Recall logging part of an unconditional close checklist.

## Optional Reflection

Reflection is not one of the workflow lifecycle phases. It is a read-only prompt primitive that may add scrutiny without becoming alternate close-out choreography.

When the task is ready to close, optional task reflection is:

```bash
vendor/bin/agent-loop workflow reflect <task-id> --scope task
```

If it returns `RETURN_TO_REVIEW`, resolve that concrete review gap and return to `finish`. Do not translate reflection output directly into lifecycle state.

After successful completion, optional project reflection can surface future investment:

```bash
vendor/bin/agent-loop workflow reflect <task-id> --scope project
```

Any resulting follow-up still requires its own normal governance and approval. Reflection never supplies validation, review acknowledgement, Learning disposition, or close authority.

## Accepted Risk

Accepted risk is a named waiver only for bypassable evidence gates. Use it only when the lifecycle kernel exposes an accepted-risk action. It cannot change task authority, and it must not become a generic "make it green" switch.

## When Finish Refuses

Use the structured refusal from `finish` itself. `error`, `blockers`, `next_action`, and `next_action_kind` identify the current owner and required repair.

Diagnostic commands such as `workflow status`, `workflow report`, and `verify` may be used to inspect evidence, but they are not alternate close-out choreography.

Repair the named owner boundary, then rerun `finish`.

## Invariant

Normal governed post-implementation flow is:

```text
review judgment
-> explicit review/Learning inputs
-> agent-loop finish
-> complete | one canonical next action
```

If this skill starts enumerating deterministic validation, verification, Learning persistence, or close gates again, it has duplicated lifecycle semantics and should be reduced rather than extended.
