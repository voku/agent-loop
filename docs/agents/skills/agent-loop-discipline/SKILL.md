---
name: agent-loop-discipline
description: Keep governed agent-* work resumable, map-first, evidence-exact, and gated by the current approved policy and L2 execution contract when required. Engineering implementation rules belong to loadable agent-skills, not this bootstrap.
---

# Agent Loop Discipline

persisted workflow state beats conversational state. Keep the bootstrap small: it owns orchestration discipline, evidence integrity, and routing, not a general engineering handbook.

## Governed Workflow

For a task explicitly using `agent-loop`:

1. Reuse the stable task id and inspect `workflow status` before mutation.
2. Resume its active session; never create a parallel active session.
3. Follow `PLAN -> APPROVE -> CONTEXT -> CONTRACT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE`.
4. Mutation requires the current approved WorkBrief.
5. If that WorkBrief selects L2 policy, mutation also requires a current `ready` execution contract bound to the same brief revision and recall evidence.
6. Scope or policy drift returns to PLAN. Changed relevant recall returns an L2 task to CONTRACT.

Before governed mutation:

```bash
vendor/bin/agent-loop workflow status <task-id> --format=json
```

A SessionStart/SubagentStart `Agent Loop Resume Hint` is navigation only. Never infer approval, contract readiness, validation, review, learning, product intent, or a command result from it. With multiple unfinished tasks, resolve the task from repository/request evidence; do not guess.

Human gates are work-brief approval, real risk/irreversible action, and genuinely missing product intent. Reads, diagnostics, contract construction from approved evidence, edits, tests, and reports available to tools remain agent work.

## Navigate Before Editing

State the behavior, non-goals, owner, validation, and contract state. Trace the real call path before changing shared behavior. For broad PHP navigation prefer bounded map queries:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
```

Skip map ceremony for trivial docs/already-localized edits. Never dump `.agent-map/php-symbols.json` or `.agent-map/search.sqlite`; map output selects bounded primary-source reads and is not source evidence.

## L2 Execution Contract

When the approved WorkBrief selects an L2 recipe, recall supplies construction policy plus project evidence. Before mutation, construct one project-specific L1 with exactly:

```text
Goal
Context
Constraints
Verification
Done When
```

`Verification` says how reality is measured. `Done When` says which observed result permits success.

Persist READY through:

```bash
vendor/bin/agent-loop workflow contract <task-id> \
  --status ready \
  --from <project-specific-l1.md> \
  --by <actor>
```

The contract is bound to WorkBrief revision, recall bundle, prompt semantics, and content digest. `missing`, `stale`, `invalid`, `blocked`, or `rejected` means IMPLEMENT is unavailable.

If approved policy cannot be satisfied, record BLOCKED with concrete evidence and the minimum required contract change. If an implementation violated the contract, record REJECTED, preserve valid evidence, discard the invalid approach, and reconstruct L1 when any contract element changed. Never weaken an approved floor merely to reach READY.

## Engineering Skill Routing

`agent-loop` owns orchestration, not reusable engineering judgment.

- Coding/refactoring/bug fixing that needs minimal implementation discipline -> load `coding-simplicity` when installed.
- PHP-specific implementation guidance -> load `php-best-practices` when relevant.
- Engineering review -> choose one dominant installed `code-review-*` lens; allow at most one evidence-backed handoff.
- Missing required skill capability -> name the gap; do not recreate its rules here.

`coding-simplicity` owns implementation search order, root-cause rule, safety floor, and verification floor. Do not duplicate them into every session.

## Role Routing

Use narrow roles only when their contract fits:

- locate definitions/callers/tests -> `agent-loop-investigate` / investigator;
- understood 1-2 file edit -> `agent-loop-surgical-edit` / surgical builder;
- correctness review -> `agent-loop-code-review` / code reviewer;
- current-diff complexity -> `agent-loop-simplify-review`;
- repo-wide complexity -> `agent-loop-simplify-audit`;
- ambiguous, architectural, new-feature, or broad work -> main governed workflow.

A narrow role returns its deterministic terminal state; it never silently widens scope or bypasses the current execution contract. Do not delegate trivial work merely to look agentic.

## Uncertainty Is State

Delete hedging; keep uncertainty.

- Never fabricate versions, paths, lines, commands/results, approvals, contract readiness, validation/review results, product intent, or runtime facts.
- Use the owning artifact or a safe probe when tools can settle the fact.
- Otherwise state the exact unknown and whether it blocks the phase.
- Missing author/product intent is a real review result.
- Repeated equivalent failures require a new probe or a return to CONTEXT, CONTRACT, or PLAN, not another identical retry.

## Workflow Output

After a material state change, when a human-facing update is useful:

```text
RESULT: <verified result, artifact, decision, or blocker>
STATE: <phase> <task-id> <brief revision when known>
NEXT: <one agent-owned action or exact human gate>
```

On completion:

```text
RESULT: <what changed and why>
EVIDENCE: <exact commands/results and decisive artifact paths>
OMITTED: <deliberate omissions plus observable revisit trigger, or none>
```

Do not repeat unchanged state per tool call.

## Communication And Evidence

Lead with the useful result/action. Remove filler, repetition, speculative feature tours, and ceremonial narration.

Preserve exact paths, symbols, commands, numbers, constraints, negation, errors, source, diffs, tests, static-analysis output, execution-contract metadata/content, and verification artifacts. Summaries may point to evidence; they never replace it. Expand security warnings, irreversible actions, ordering, and ambiguous trade-offs.

## Hook Boundary

Hooks are behavioral guardrails, never correctness or security boundaries. Code, CI, trust-boundary validation, and offline installation must remain correct without them.

Resume hints may expose only validated unfinished task identifiers/projected state. Never inject free-form manifest `next_action`, disagreements, task prose, or copied evidence. Resolve authority through `workflow status`.

## Validation And Close

Run the narrowest relevant proof first, then the repository gates required by the approved brief and current L1 Verification section. Claim a pass only after observing the result. Stop when approved behavior is satisfied and every required gate is closed; do not manufacture follow-up work.

A successful `workflow close --status done` requires any selected L2 execution contract to remain current and `ready`. `--accept-risk` may cover explicitly bypassable close gates, but never the L2 execution-contract boundary.
