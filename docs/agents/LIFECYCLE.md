# The governed lifecycle across the agent-* packages

This document describes the current ownership model and the ordinary host path.
It deliberately avoids reproducing every gate or prerequisite: those decisions
belong to executable owners and are projected through the lifecycle kernel.

Only `agent-loop` owns cross-package lifecycle policy. The focused packages keep
their own state and semantics.

| Package | Owns |
| --- | --- |
| `agent-kanban` | durable board/work-item state |
| `agent-session` | task-local mutable working state and validation evidence |
| `agent-map` | repository/navigation facts and search |
| `agent-recall-compiler` | governed briefing, Recall artifacts and Recall-owned review semantics |
| `agent-learning` | findings, proposals, evidence and durable Learning decisions |
| `agent-loop` | Contract/Run lifecycle, cross-owner policy, routing and host projection |

## Ordinary Host Path

The stable host-facing contract is intentionally small:

```text
human/task intent
  -> agent-loop enter <task-id> --format=json
  -> obey next_action_kind / next_action
  -> host-native implementation when authorized
  -> agent-loop finish <task-id> --format=json
  -> obey next_action_kind / next_action
  -> complete
```

The host does not need to know package storage paths, reconstruct gate ordering,
or remember a map/Recall/Session preparation sequence.

The structured lifecycle result exposes the facts needed for routing:

- `mutation_ready` — whether implementation work is currently authorized;
- `next_action_kind` — how the host must treat the next step;
- `next_action` — the canonical next step;
- `manifest.references` — owner-backed states/reasons supporting that decision.

Current `next_action_kind` values are:

- `command` — execute the command as written;
- `decision_required` — the command template still needs model and/or human
  judgment/input before it is executable;
- `host_work` — perform the described host-native implementation/model work;
- `none` — no further lifecycle action is required.

A canonical command should make progress when obeyed. If it deterministically
refuses and returns the same state/action, that is a lifecycle defect, not a host
instruction to invent a workaround.

## Contract And Approval

PLAN persists durable task intent: task id, goal, scope, validation and other
selected policy. It creates neither a governed Run nor working Session.

Approval is an authority boundary. A named human approves the exact Contract
revision. Approval itself does **not** allocate the governed Run, Session or
Recall output.

`enter` owns deterministic post-approval preparation/reconciliation. It creates
or reuses the Run-bound Session, prepares the governed Run, compiles current
Recall through the Recall owner and returns bounded context. Repeated `enter` is
safe for the current binding and reconciles superseded state through owner APIs.

Discovery prerequisites are also surfaced by the lifecycle kernel. For example,
when existing PHP scope requires map evidence and no usable map exists, the
canonical next step names the owner-produced map repair before approval instead
of letting the host discover that rule from a failing command's prose.

## Implementation

Once mutation is authorized, implementation is host-native. The host edits real
source with repository-native tools and the smallest correct scope.

`agent-map` remains useful navigation and evidence, but generated map state is
not lifecycle authority. `agent-loop edit` remains a specialist deterministic
edit surface; ordinary work does not require an edit bundle merely because the
command exists.

When selected Recall policy requires an L2 execution contract, the lifecycle
result surfaces that requirement and the current Recall-owned construction
instructions define its semantics. Hosts must not keep a parallel copy of those
rules.

## Finish And Close-Out

`finish` is the deterministic close-out front door. It reconciles current
implementation-bound evidence and reports the first decisive next step. The host
must not maintain its own list of validation, review, Recall, Learning, integrity
or close gates.

Examples of the kinds of next step `finish` may expose include:

- an executable lifecycle/specialist command;
- a command template that still requires a decision or acknowledgement;
- host-native implementation work when observed validation failed;
- no action when the governed Run is complete.

The exact gate set and ordering are executable policy and may evolve. Keeping
that list in prose previously drifted, so this document intentionally does not
enumerate it.

## Validation Evidence

Validation evidence is owned by Session and bound to the current Contract
revision and implementation identity. A recorded command/result is evidence;
conversation claims are not.

When current validation has **failed**, the lifecycle can return `host_work`
because the next useful action is to repair implementation rather than re-run
`finish` forever. When validation evidence is merely missing, the owner may
instead expose an executable deterministic action. The host should not infer
that distinction by parsing error text.

## Review And Learning

Blind-spot/process review remains separate from ordinary engineering correctness
review. Review artifacts are evidence, not approval merely because files exist.

Learning decisions stay explicit and evidence-backed. Recall selection is not
proof that guidance was useful; unused/irrelevant outcomes remain truthful
signals. Findings/proposals do not become durable project guidance without the
Learning/review boundary that owns that promotion.

Hosts consume the canonical lifecycle request for review/Learning work rather
than deciding from a copied close checklist when those gates apply.

## Read-Only And Diagnostic Surfaces

These remain useful when additional inspection is needed:

```bash
vendor/bin/agent-loop workflow status <task-id> --format=json
vendor/bin/agent-loop workflow context <task-id> --format=json
vendor/bin/agent-loop workflow report <task-id> --format=json
vendor/bin/agent-loop verify --task-id=<task-id>
```

They are not a second mandatory happy path beside `enter -> host work -> finish`.
Derived snapshots may provide drift/diagnostic evidence but cannot override fresh
owner authority.

## Specialist And Recovery Surfaces

Direct `map`, `session`, `recall`, `learn`, `edit`, review and recovery commands
remain available for diagnostics, specialist work and explicit repair. Their
existence does not make them ordinary lifecycle phases.

The specialist/recovery rule is:

```text
diagnose the owner-backed failure
  -> perform one explicit repair/specialist action
  -> return to the ordinary lifecycle
```

Do not build a parallel recovery state machine beside the lifecycle kernel.
Phase F of the roadmap evaluates these specialist/recovery surfaces separately;
the ordinary-path ownership contract above is the Slice E boundary.

## Supersession And Resumability

Durable Contract and Run evidence outlive chat sessions. Session working memory
is pruneable. When durable intent changes, revise the Contract and obtain the
required authority for that revision; let `enter` reconcile old Run/Session/
Recall working state rather than manipulating those stores from host prose.

Resume from persisted lifecycle state, not from an agent's remembered phase.

## Core Invariants

- one semantic owner per decision;
- host guidance routes and presents, it does not re-derive lifecycle legality;
- `next_action` has one authority and `next_action_kind` states how to treat it;
- commands advertised as canonical must advance the state they name or reach
  an explicit decision/host-work boundary (constraint
  `workflow.recovery.next-action-must-advance`);
- evidence is not authority;
- generated artifacts are not runtime-consumption proof;
- dependent unavailable capabilities do not multiply one missing prerequisite
  into several immediate blockers;
- the earliest actionable deterministic gap is surfaced before later judgment
  gaps;
- human approval/risk authority is never fabricated;
- falsify a phase's completion claim before declaring it done.
