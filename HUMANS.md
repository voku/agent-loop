# Human Guide

`agent-loop` is not meant to remove the human from coding work. It is meant to
move human attention to the decisions that actually need human authority and
leave deterministic workflow mechanics to the tools.

If you only want to understand how to supervise one task, start here.

## The short version

For an ordinary durable task, the intended interaction is:

```text
human states the outcome
  -> agent runs `enter <task>`
  -> agent follows the returned next action
  -> human decides only when the lifecycle explicitly asks for human authority
  -> agent implements and validates
  -> agent runs `finish <task>`
  -> agent follows the returned next action until complete
  -> human receives the evidence and final result
```

The human should not have to remember a hidden sequence of Map, Session, Recall,
review, Learning, or close commands. The lifecycle kernel owns that ordering.

## What the human owns

A human decision is required when the task would change authority-bearing intent,
for example:

- approving the exact task Contract;
- changing the Goal, acceptance criteria, scope, or non-goals after approval;
- accepting a meaningful risk or irreversible action;
- changing a public/product contract when that change is not already authorized;
- making another decision explicitly classified by the lifecycle as human-owned.

When the CLI returns `next_action_kind=decision_required`, the agent should show
the exact decision subject and current evidence. A generic "please confirm" is
not enough.

## What the human should usually not own

The following are normally agent/tool work once the approved intent is clear:

- filling model-owned placeholders in a planning command from repository evidence;
- choosing the smallest implementation strategy inside approved scope;
- navigating source code;
- rebuilding derived Map/Recall state when the lifecycle requests it;
- running declared validation;
- fixing an implementation after validation fails;
- recording ordinary review acknowledgement or Learning disposition when the
  lifecycle classifies those as agent-owned;
- local commits and deterministic close-out work inside the approved Contract.

Human-in-the-loop should mean **human authority at the right boundary**, not
human-as-a-remote-control for every shell command.

## What to ask an agent for

A good task request describes the outcome and relevant constraints, not a private
workflow choreography.

Useful:

```text
Fix the lowest-supported dependency mismatch and prove PHP 8.3 still works.
```

Less useful:

```text
Build Map, create a Session, run Recall, edit these three files, then ask me
before every validation step.
```

The second form hard-codes workflow assumptions that may already be stale.

## What evidence should come back

For non-trivial work, expect concrete evidence rather than confidence theater:

- what changed and why;
- the exact validation that ran and its result;
- current CI/review state when GitHub is part of the task;
- any remaining blocker or human-owned decision;
- what the lifecycle reports as the next action or completion state.

Claims such as "CI is green", "the hook fired", "the PR merged", or "the release
shipped" need current evidence.

## When to intervene

Intervene when the agent reaches a real authority boundary, or when the approved
problem itself is wrong.

A healthy agent may also challenge its own implementation premise when concrete
evidence shows avoidable complexity, repeated repair, or contradictory facts.
That does **not** automatically require a human decision. If the Goal,
acceptance, scope, and authority stay unchanged, the agent may replan the
implementation itself.

Escalation is appropriate when the simpler route would change human-owned intent
or risk authority.

## Useful views

For one task, these are useful diagnostic surfaces:

```bash
vendor/bin/agent-loop workflow status TASK-1 --format=json
vendor/bin/agent-loop workflow report TASK-1 --format=json
vendor/bin/agent-loop verify --task-id=TASK-1
```

They are views and evidence tools, not extra mandatory phases.

## Where to go next

- [README.md](README.md): product overview and installation.
- [WORKFLOWS.md](WORKFLOWS.md): named end-to-end workflows.
- [AI.md](AI.md): compact field guide for coding agents.
- [docs/quick-start.md](docs/quick-start.md): detailed first governed task.
- [docs/compatibility.md](docs/compatibility.md): state/compatibility ownership.
- [AGENTS.md](AGENTS.md): repository-specific implementation and owner rules.

These documents intentionally overlap a little. Repeating the happy path in a
few obvious places is cheaper than requiring every reader to reconstruct it from
architecture documents.