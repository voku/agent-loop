---
name: agent-loop-discipline
description: Governed agent-* orchestration: resumable state, adaptive navigation, evidence, L2 gates, review routing.
---

# Agent Loop Discipline

Persisted workflow state beats conversation. Keep orchestration, evidence, navigation, and human attention bounded.

## Governed Workflow

The lifecycle kernel decides what happens next; this SessionStart skill adds no independent ordering rules.

```bash
vendor/bin/agent-loop enter <task-id> --format=json
vendor/bin/agent-loop finish <task-id> --format=json
```

Obey `next_action_kind` and `next_action`:

- `command` - run it as written;
- `command_template` - fill model-owned placeholders from request/repository evidence and execute;
- `decision_required` - present the exact human-authority decision; never fabricate it;
- `host_work` - do the described host-native implementation work;
- `none` - no further lifecycle action.

Do not decide mutation legality, gates, contract currency, or superseded scope. The canonical result owns them. If its command refuses without changing the next step, report a workflow defect; do not invent choreography.

SessionStart/SubagentStart hints are navigation only. Never infer approval, validation, review, learning, product intent, or a next command from them.

Human gates: Contract approval, review acknowledgement, Learning disposition, real risk/irreversible action, and missing product intent. Reads, edits, tests, diagnostics, reports, PLAN/contract construction, and checkpoints remain agent work.

## Agent I/O

- Inside PHP call the owner's typed API; never render then parse in-process.
- Request the smallest owner projection; prefer TOON, keeping JSON for durable/hash/replay contracts.
- Projection drops fields, never truncates selected values; a missing key means “not requested”.

## Prompt Controls

When selected by the approved Contract:
- `checkpoint-autonomy`: inspect scope, evidence, validation, blockers, and done condition. If no evidence challenges the framing and no human gate exists, checkpoint and continue. Never persist a synthetic human/self approval.
- On concrete avoidable complexity, repeated repair, or contradictory observations, check the premise before adding machinery: approved outcome; assumption causing complexity; whether evidence still supports it; simpler route preserving Goal, acceptance, scope, and authority.
- Result: `CONTINUE`, `REPLAN`, or `HUMAN_DECISION_REQUIRED`. `CONTINUE` needs materially new evidence before reopening. `REPLAN` is agent-owned when approved intent is unchanged; delete obsolete machinery pre-1.0. `HUMAN_DECISION_REQUIRED` is only for changing product intent, Goal, acceptance, scope, non-goals, public contract, or risk/irreversible authority.
- Trigger by evidence, never timer/count or every checkpoint. A conceivable alternative alone is not evidence.
- `momentum`: reuse still-valid files, symbols, commands, constraints, decisions, and evidence; re-check authority/freshness when they may have changed.

These are L1 controls, not L2 gates.

## Navigate Before Editing

Use the cheapest reliable navigation for the information required. For known files/symbols, literals, config/templates, exception messages, or local tests, prefer `rg`, `rg --files`, and focused source reads; do not build Map merely to satisfy policy.

Escalate to `agent-loop map query`, `related`, `file`, `scope`, `context`, `callers`, or `callees` when PHP work needs structural answers: unknown implementation ownership, callers/callees, cross-file impact, provenance/value flow, refactoring scope, related symbols, or production/test relationships. If a relevant fresh Map already exists, prefer it earlier because its build cost is already paid.

If Map is unavailable, stale, unsupported, or insufficient, record that limitation and fall back to CLI navigation. Never treat failed Map output or literal matches as proof of semantic relationships. Do not mechanically repeat equivalent discovery with both Map and `rg`; verify only remaining facts in real source. `grep`, `find`, and `sed -i` are blocked. Prefer governed Map change plans when useful; mutation stays host-owned. Never dump map databases; Map output selects bounded source reads and is not source evidence.

## L2 Execution Contract

For an approved L2 recipe, construct one project-specific L1 before mutation:

```text
Goal
Context
Constraints
Verification
Done When
```

`Verification` measures reality; `Done When` names the observed success condition.

```bash
vendor/bin/agent-loop workflow contract <task-id> \
  --status ready \
  --from <project-specific-l1.md> \
  --by <actor>
```

Construction is model-owned from approved intent/Recall evidence unless lifecycle reports a human boundary. A command template alone needs no confirmation.

`missing`, `stale`, `invalid`, `blocked`, or `rejected` means IMPLEMENT is unavailable. Record evidence and the minimum repair; never weaken policy to reach `ready`.

## Engineering Skill Routing

`agent-loop` owns orchestration, not reusable engineering judgment. Route simple coding/refactoring to `coding-simplicity`, PHP work to `php-best-practices`, and review to one dominant installed `code-review-*` lens plus at most one evidence-backed handoff. Name missing capabilities; do not recreate their rules. `coding-simplicity` owns implementation search order, root-cause, safety, and verification floors.

## Role Routing

Use verified narrow roles: definitions/callers/tests -> `agent-loop-investigate`; 1–2 file edit -> `agent-loop-surgical-edit`; correctness -> `agent-loop-code-review`; current-diff complexity -> `agent-loop-simplify-review`; repo-wide complexity -> `agent-loop-simplify-audit`. Ambiguous, architectural, new-feature, or 3+ file work stays in main workflow. Narrow roles never widen scope or bypass the contract.

## Uncertainty Is State

- Never fabricate versions, paths, commands/results, approvals, contract/review/validation state, product intent, or runtime facts.
- Use owner state or a safe probe; otherwise state the exact unknown and whether it blocks.
- Repeated equivalent failure means inspect the suspect assumption and return to CONTEXT, CONTRACT, or PLAN.

Preserve exact paths, symbols, commands, constraints, errors, diffs, tests, contracts, and verification artifacts. Summaries point to evidence; never replace it.

## Workflow Output

Update only on result, blocker, scope, decision, or phase change:

```text
RESULT: <verified result, decision, artifact, or blocker>
STATE: <phase> <task-id> <Contract revision when known>
NEXT: <one agent-owned action or exact human gate>
```

On completion:

```text
RESULT: <what changed and why>
EVIDENCE: <exact validation results and decisive artifacts>
OMITTED: <deliberate omissions plus revisit trigger, or none>
```

Receipts compress narration, never evidence.

## Hook Boundary

Hooks are behavioral guardrails, never correctness/security boundaries. Code, CI, trust validation, and offline install remain correct without them. Resume hints are navigation only; authority comes from `workflow status`.

## Validation And Close

Run the narrowest proof first, then Contract/L1 gates. Claim a pass only after observing it. Stop when approved behavior is satisfied and required gates are closed; do not manufacture follow-up work.

At `ready_to_close`, optional task reflection can expose a completion gap:

```bash
vendor/bin/agent-loop workflow reflect <task-id> --scope task
```

`RETURN_TO_REVIEW` routes that gap through REVIEW/IMPLEMENT/PLAN.

After successful close, optional project reflection may identify at most one future investment:

```bash
vendor/bin/agent-loop workflow reflect <task-id> --scope project
```

Reflection is read-only, not a close gate, and creates no follow-up.

`workflow close --status done` requires any selected L2 contract to remain current and `ready`. `--accept-risk` never bypasses that boundary.
