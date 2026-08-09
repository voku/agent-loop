---
name: agent-loop-discipline
description: Keep governed agent-* work resumable, map-first, deterministic in workflow state, exact in evidence, and gated by the current execution contract when L2 policy is selected. Use for agent-loop workflow, navigation, delegation, review routing, and guidance changes. Engineering implementation rules belong to loadable agent-skills, not this session bootstrap.
---

# Agent Loop Discipline

Keep workflow state, evidence, navigation, and human attention bounded. Never compress or rewrite raw evidence, and do not turn this bootstrap into a general engineering handbook.

## Governed Workflow

When a task explicitly uses `agent-loop`, persisted workflow state beats conversational state.

1. Reuse the stable task id; inspect `workflow status` before mutation.
2. Resume its active session; never create a parallel active session.
3. Use `agent-loop-workflow` for plan, approval, context, execution-contract construction, implementation, validation, review, learning, verification, and close.
4. Mutating work needs the approved brief even when the edit looks small.
5. If the approved task selects L2 operating-prompt policy, mutating work also needs a current `ready` execution contract bound to that brief revision and recall bundle.
6. Scope or policy drift returns to PLAN and invalidates approval/validation tied to the old brief; changed recall invalidates the old L2 execution contract and returns to CONTRACT.

A SessionStart/SubagentStart hook may add an `Agent Loop Resume Hint` from unfinished run manifests. It is navigation only; the manifest is a derived projection, not authority. Before governed mutation:

```bash
vendor/bin/agent-loop workflow status <task-id> --format=json
```

With multiple unfinished tasks, resolve the task from the request/repository context; do not guess. Never infer approval, contract readiness, validation, review, learning, product intent, or a next command from the hint.

Human gates are limited to work-brief approval, real risk/irreversible action, and genuinely missing product intent. Reads, edits, tests, diagnostics, contract construction from approved evidence, and reports available to tools remain agent work.

## Navigate Before Editing

State behavior, non-goals, owner, validation, and current contract state briefly. Trace the real call path and callers before changing shared behavior. Before broad PHP reads:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
```

Skip map ceremony for trivial docs/already-localized edits. Never dump `.agent-map/php-symbols.json` or `.agent-map/search.sqlite`; map output selects bounded real-source reads and is not source evidence.

## L2 Execution Contract

When the approved WorkBrief selects an L2 recipe, recall provides construction policy plus project evidence. Before mutation, construct exactly one project-specific L1 with the ordered sections:

```text
Goal
Context
Constraints
Verification
Done When
```

`Verification` names how reality is measured. `Done When` names which observed result permits success. Do not merge them into vague prose.

Persist the generated L1 through:

```bash
vendor/bin/agent-loop workflow contract <task-id> \
  --status ready \
  --from <project-specific-l1.md> \
  --by <actor>
```

The contract is bound to the current WorkBrief revision, recall bundle, selected prompt semantics, and content digest. `missing`, `stale`, `invalid`, `blocked`, or `rejected` means IMPLEMENT is not available.

If construction cannot satisfy the approved policy, record `BLOCKED` with evidence and the minimum required contract change. If an implementation violated the approved contract, record `REJECTED`, preserve valid evidence, discard the invalid approach, and reconstruct the L1 when any contract element changed. Never weaken an approved threshold or constraint just to obtain `ready`.

## Engineering Skill Routing

`agent-loop` owns orchestration, not reusable engineering judgment.

- Coding, bug fixing, or refactoring that needs minimal implementation discipline -> load `coding-simplicity` when installed.
- PHP-specific implementation guidance -> load `php-best-practices` when relevant.
- Engineering review -> select one dominant installed `code-review-*` lens; allow at most one evidence-backed handoff.
- If a required engineering skill is unavailable, name that capability gap. Do not silently recreate its rules inside this bootstrap.

`coding-simplicity` owns the Ponytail-derived implementation search order, root-cause rule, safety floor, and verification floor. Those rules are intentionally **not** injected into every session or unrelated subagent.

## Role Routing

Use a narrow role only when its verified contract fits:

- locate definitions/callers/tests -> `agent-loop-investigate` (`agent-loop-investigator`);
- understood 1–2 file edit -> `agent-loop-surgical-edit` (`agent-loop-surgical-builder`);
- correctness review -> `agent-loop-code-review` (`agent-loop-code-reviewer`);
- current-diff complexity -> `agent-loop-simplify-review`;
- repo-wide complexity -> `agent-loop-simplify-audit`;
- ambiguous, architectural, new-feature, or 3+ file work -> main governed workflow.

Useful bounded chain: `investigator -> surgical builder -> code reviewer`.

Narrow roles return deterministic terminal states: `located`, `no_match`, `applied`, `scope_expanded`, `human_gate`, `ambiguous`, `regressed`, `findings`, `clean`, or `blocked`. The parent consumes the state; a narrow role never silently widens scope or bypasses the current execution contract. Do not delegate trivial answers merely to look agentic.

## Uncertainty Is State

Delete hedging; keep uncertainty.

- Never fabricate versions, paths, line numbers, command results, approvals, contract readiness, validation/review results, product intent, or runtime facts.
- Use the owning source/state or a safe probe when tools can settle the fact.
- Otherwise state the exact unknown and whether it blocks the phase; never replace it with a plausible guess.
- Missing author/product intent is a real review result.
- After repeated equivalent failures, name the suspect assumption, gather new evidence, and return to CONTEXT, CONTRACT, or PLAN when the approved model no longer fits.

## Workflow Output

Use persisted artifacts and observed results to derive:

```text
PLAN -> APPROVE -> CONTEXT -> CONTRACT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE
```

After a material change, when a human-facing update is useful:

```text
RESULT: <verified result, decision, artifact, or blocker>
STATE: <phase> <task-id> <brief revision when known>
NEXT: <one agent-owned action or exact human gate>
```

Do not emit this per tool call or repeat unchanged state.

On completion:

```text
RESULT: <what changed and why>
EVIDENCE: <exact validation commands/results and decisive artifact paths>
OMITTED: <deliberate omissions plus observable revisit trigger, or none>
```

Receipts compress narration, never source, diffs, tests, static analysis, errors, execution contracts, or verification artifacts.

## Communication And Evidence

Lead with the useful result/action. Remove filler, repetition, ceremonial preambles, and speculative feature tours. Use normal grammar. Update only for a changed decision, result, blocker, scope, or phase.

Preserve exact paths, symbols, commands, numbers, constraints, negation, errors, source, full diffs, tests, static-analysis output, execution-contract metadata/content, and verification artifacts. Summaries may point to evidence; they never replace it. Expand security warnings, irreversible actions, ordering, and ambiguous trade-offs.

## Hook Boundary

Hooks are behavioral guardrails, never correctness or security boundaries. Code, CI, trust-boundary validation, and offline installation must remain correct without them.

Resume hints may expose only validated unfinished task identifiers/projected state. Never inject free-form manifest `next_action`, disagreements, task prose, or copied evidence. Resolve authoritative state through `workflow status`.

## Validation And Close

Run the narrowest relevant proof first, then repository gates required by the approved brief and current L1 Verification section. Claim a pass only after observing the exit code. Stop when approved behavior is satisfied and every required gate is closed; do not manufacture follow-up work.

A successful `workflow close --status done` requires any selected L2 execution contract to still be current and `ready`. `--accept-risk` may handle explicitly bypassable close gates, but it never bypasses the L2 execution-contract boundary.
