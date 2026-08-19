---
name: agent-loop-task-start
description: Define durable task intent for a governed agent-loop task, then route startup through the lifecycle kernel instead of reproducing preparation or discovery choreography in host guidance.
---

# Agent Loop Task Start

Use this skill when a task needs a durable Contract with explicit intent. This
skill helps choose that intent; it does not own the lifecycle sequence that
follows. The lifecycle kernel decides what is legal next.

## Start Through The Front Door

For a stable task id, start or resume with:

```bash
vendor/bin/agent-loop enter <task-id> --format=json
```

For a genuinely new task the kernel may return a `decision_required` PLAN command
template. Fill the missing Contract inputs from the actual request and current
repository evidence, execute that command, then call `enter` again and obey the
new structured `next_action_kind` / `next_action`.

Do not pre-build a map, manually create a Session, compile Recall, or infer that
approval should run merely because an old startup checklist said so. If discovery
or another deterministic prerequisite is required, the owner-backed lifecycle
result must name that repair.

A named human approval remains authority-bearing. Never fabricate the approving
actor or self-approve. Approval records authority for the exact Contract revision;
Run, Session and Recall preparation happens deterministically behind `enter`.

## Contract Intent

A PLAN should carry enough durable intent that a later agent does not need the
original chat to understand the task:

- stable task id;
- actor/planner identity;
- smallest honest file/scope boundary;
- goal;
- explicit non-goals when they prevent scope drift;
- behavior anchors when runtime behavior matters;
- executable validation commands supported by repository evidence;
- acceptance criteria for required outcomes;
- selected operating-prompt policy only when a real recipe/control applies.

The canonical PLAN template is intentionally incomplete until those values are
chosen. Do not persist unresolved placeholders such as `<goal>` or
`<validation>` as real Contract values.

## Task ID

Reuse the external ticket/issue id when one exists. Otherwise choose one stable
local id such as `LOCAL-001` and keep it for the life of the task. Do not create a
new id on every resume.

If existing durable state may exist, `enter`/`workflow status --format=json`
should discover it before another plan is invented beside it.

## Choosing Scope

Select files intentionally. Prefer the smallest scope that honestly contains the
requested behavior and evidence. Typical inputs include:

- the failing or focused test;
- the implementation owner;
- a task/decision document that constrains behavior;
- architecture or policy files directly governing the change.

Do not pass the whole repository merely because context is available. Initial
`--file` values become approved scope unless explicit `--scope` values replace
them. If intent or scope genuinely changes later, revise the Contract and let the
lifecycle kernel reconcile superseded working state.

## Acceptance, Validation, And Behavior Anchors

Keep these concepts distinct:

- **acceptance criterion** — outcome/condition that must remain true;
- **validation command** — executable observation used to measure current code;
- **behavior anchor** — runtime/request/consumer seam whose behavior matters.

Example:

```text
acceptance: installed guidance exposes the new control
validation: composer ci
anchor: SessionStart -> injected agent-loop-discipline
```

A criterion is intent, not proof. A validation string must be a real
repository-supported command, not prose or an unresolved placeholder.

## Existing Work Preflight

For non-trivial work, inspect bounded relevant current/recent work when the host
can do so cheaply. Classify useful candidates as landed, active, superseded,
abandoned, or independent. Try to falsify the strongest existing candidate
against the current task intent before creating a competing implementation.

Do not turn this into a mandatory global archaeology pass. If history is
unavailable, continue from current repository evidence and state the limitation.

## External Reference Preflight

Only when the task is explicitly defined relative to an upstream implementation,
specification, prior version, or other external authority:

1. identify the exact reference and requested comparison term;
2. inspect a bounded relevant inventory;
3. state included, excluded, and unknown surfaces;
4. distinguish direct port from adaptation;
5. avoid parity claims from partial evidence.

This is evidence for choosing Contract intent, not a new lifecycle state.

## Operating Prompts

When a reusable recipe/control genuinely applies, select it explicitly in the
Contract using the manifest owned by the package that defines its semantics. Do
not copy the recipe rules into this skill or select recipes merely because they
exist.

For example, a behavior-changing task with a meaningful automated test seam may
select Recall's `test-driven-development` recipe; a specific unverified bug may
instead select `reproduce-before-fix`. Choose the one whose constraint actually
matters rather than stacking overlapping ceremony.

## After PLAN

Once the Contract exists, stop using this skill as a workflow engine. Return to:

```bash
vendor/bin/agent-loop enter <task-id> --format=json
```

and obey the lifecycle kernel. In particular:

- discovery repair comes from `next_action`, not a remembered map preflight;
- approval happens only when the kernel asks for that authority;
- deterministic preparation belongs to `enter`;
- implementation is host-native once authorized;
- deterministic close-out belongs to `finish`.

Use `agent-loop-workflow` for the ordinary routing contract and specialist skills
only for specialist work actually requested by the kernel/task.

## Lower-Level Tools Are Not The Happy Path

Direct `session`, `recall`, `map`, and edit commands remain useful diagnostics,
recovery, navigation, or specialist tools. Their existence does not make them
mandatory startup phases. Do not bypass the governed front door merely because a
lower-level command can reproduce part of its work.

## Skill Boundary

This skill owns choosing durable PLAN inputs and preserving task intent. It does
not own approval policy, discovery readiness, Run/Session/Recall preparation,
close gates, recovery choreography, or package-internal artifact paths.

## Example Triggers

- "Start an agent-loop task for this change."
- "Define the governed task scope before editing."
- "Use agent-loop for this task."
