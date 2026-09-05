# Human authority

`agent-loop` keeps humans at authority boundaries without turning them into shell-command remote controls.

The executable lifecycle result is authoritative. This document describes the supervision boundary; it does not decide when a gate is satisfied or what action comes next.

## Human-owned decisions

When the lifecycle returns `next_action_kind=decision_required`, the agent must present the exact current decision subject and supporting evidence.

Typical human-owned subjects include:

- approval of the exact durable task intent;
- a material change to Goal, scope, acceptance criteria, or non-goals;
- acceptance of meaningful risk;
- destructive or irreversible choices;
- another authority-bearing decision explicitly surfaced by the lifecycle.

The examples are not a second gate list. The current lifecycle result decides whether human authority is required.

## Agent-owned work inside approved authority

Once current authority permits the work, the agent should normally handle model and implementation mechanics itself: repository navigation, filling model-owned command templates from current evidence, implementation choices inside scope, validation repair, and other delegated actions returned by the lifecycle.

Do not ask a human to approve a shell command merely because the agent can execute it, and do not fabricate approval merely because continuing would be convenient.

## What evidence should return to the human

For non-trivial work, report concrete evidence rather than confidence:

- what changed and why;
- the validation or other decisive evidence actually observed;
- any current blocker or human-owned decision;
- the current lifecycle outcome, including the next action when work is not complete.

Claims such as CI being green, a pull request being merged, or a release being shipped require current evidence.

## Presenting a human decision

A useful decision request makes the authority boundary visible without asking the human to reconstruct repository state or operate the agent's shell.

Present, in this order:

1. the exact decision subject surfaced by the current lifecycle result;
2. the smallest evidence that materially bears on that decision;
3. the consequences of the available choices when they are not obvious;
4. the decision the human actually owns.

Do not replace the subject with a generic question such as "continue?" or "approve?". Do not include routine agent-owned commands as choices merely because they happen next mechanically.

### Example: approve durable task intent

If the current decision is approval of a new or revised Contract, show the proposed authority-bearing intent rather than a shell command:

```text
Decision required: approve Contract revision 3 for TASK-123.

Goal:
Expose the current owner-backed status in the UI.

Scope:
- src/Feature/Status/
- tests/Feature/Status/

Acceptance:
- status comes from the typed owner API;
- existing unsupported-owner state remains neutral.

Changed since the approved revision:
- scope adds tests/Feature/Status/ for regression coverage;
- Goal and acceptance are unchanged.

Human decision:
approve or reject this Contract revision.
```

The agent may explain why the revision is proposed, but it must not approve the revision itself.

### Example: accept meaningful residual risk

If validation exposes a real residual risk and the lifecycle asks the human to accept or reject it, present the observed evidence and the bounded consequence:

```text
Decision required: accept the current compatibility risk for TASK-456.

Observed evidence:
- PHP 8.3 and 8.4 validation passed;
- the PHP 8.5 lane cannot run because the required external service is unavailable;
- no evidence proves PHP 8.5 behavior either way.

Consequence:
accepting the risk allows work to proceed without PHP 8.5 evidence; rejecting it keeps the task blocked until equivalent evidence exists.

Human decision:
accept or reject that residual risk.
```

Do not rewrite missing evidence into a success claim. The lifecycle remains responsible for whether risk acceptance is a legal current action.

### Counterexample: agent-owned mechanics are not a decision

If the current result is `host_work`, `command`, or a model-owned `command_template`, the agent should normally execute or perform that work itself inside existing authority:

```text
Current action: run the repository's declared validation command.
```

Do not turn that into:

```text
May I run the validation command?
```

A human decision is required only when the lifecycle says authority is required, not whenever the next mechanical step is visible.

## Ordinary task shape

Humans should be able to think in terms of:

```text
state the intended outcome
  -> agent enters the durable task
  -> agent works inside approved authority
  -> agent finishes through the lifecycle
  -> receive evidence or an explicit authority decision
```

The human does not need to memorize Map, Session, Recall, validation, review, Learning, or close-out ordering. That ordering belongs to the lifecycle kernel.

See [lifecycle.md](lifecycle.md) for ownership and structured lifecycle semantics, and [../quick-start.md](../quick-start.md) for the concrete first-task walkthrough.
