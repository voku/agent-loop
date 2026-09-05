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
