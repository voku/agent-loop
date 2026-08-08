---
name: agent-loop-discipline
description: Keep agent-* PHP work concise for humans, minimal in implementation, map-first in navigation, deterministic in workflow state, and exact in evidence. Use for coding, debugging, refactoring, review, and guidance changes.
---

# Agent Loop Discipline

Keep three budgets separate: human attention, implementation complexity, and context. Never compress or rewrite raw evidence.

## Governed Workflow

When a task explicitly uses `agent-loop`, persisted workflow state beats conversational state.

1. Reuse the stable task id; inspect `workflow status` before mutation.
2. Resume its active session; never create a parallel active session.
3. Use `agent-loop-workflow` for plan, approval, context, implementation, validation, review, learning, verification, and close.
4. Mutating work needs the approved brief even when the edit looks small.
5. Scope drift returns to PLAN and invalidates approval/validation tied to the old brief.

A SessionStart/SubagentStart hook may add an `Agent Loop Resume Hint` from unfinished run manifests. It is navigation only; the manifest is a derived projection, not authority. Before governed mutation:

```bash
vendor/bin/agent-loop workflow status <task-id> --format=json
```

With multiple unfinished tasks, resolve the task from the request/repository context; do not guess. Never infer approval, validation, review, learning, product intent, or a next command from the hint.

Human gates are limited to work-brief approval, real risk/irreversible action, and genuinely missing product intent. Reads, edits, tests, diagnostics, and reports available to tools remain agent work.

## Navigate Before Editing

State behavior, non-goals, owner, and validation briefly. Trace the real call path and callers before changing shared behavior. Before broad PHP reads:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
```

Skip map ceremony for trivial docs/already-localized edits. Never dump `.agent-map/php-symbols.json` or `.agent-map/search.sqlite`; map output selects bounded real-source reads and is not source evidence.

## Role Routing

Use a narrow role only when its verified contract fits:

- locate definitions/callers/tests -> `agent-loop-investigate` (`agent-loop-investigator`);
- understood 1–2 file edit -> `agent-loop-surgical-edit` (`agent-loop-surgical-builder`);
- correctness review -> `agent-loop-code-review` (`agent-loop-code-reviewer`);
- current-diff complexity -> `agent-loop-simplify-review`;
- repo-wide complexity -> `agent-loop-simplify-audit`;
- ambiguous, architectural, new-feature, or 3+ file work -> main governed workflow.

Useful bounded chain: `investigator -> surgical builder -> code reviewer`.

Narrow roles return deterministic terminal states: `located`, `no_match`, `applied`, `scope_expanded`, `human_gate`, `ambiguous`, `regressed`, `findings`, `clean`, or `blocked`. The parent consumes the state; a narrow role never silently widens scope. Do not delegate trivial answers merely to look agentic.

## Minimal Implementation Ladder

Stop at the first rung that satisfies the verified requirement:

1. no change;
2. reuse repository code;
3. PHP standard library;
4. native platform/database/shell/protocol;
5. installed dependency;
6. one verified root-cause fix for all callers;
7. deterministic `agent-loop edit --runner=auto`;
8. minimum new code.

Then stop. No adjacent cleanup, abstraction, config, compatibility, dependency, or policy unless requested or validation requires it. A small patch in the wrong layer is still wrong.

Non-trivial changed logic leaves the smallest meaningful runnable proof that would fail if the behavior breaks. Do not invent a test framework for a trivial edit.

## Uncertainty Is State

Delete hedging; keep uncertainty.

- Never fabricate versions, paths, line numbers, command results, approvals, validation/review results, product intent, or runtime facts.
- Use the owning source/state or a safe probe when tools can settle the fact.
- Otherwise state the exact unknown and whether it blocks the phase; never replace it with a plausible guess.
- Missing author/product intent is a real review result.
- After repeated equivalent failures, name the suspect assumption, gather new evidence, and return to CONTEXT or PLAN when the approved model no longer fits.

## Workflow Output

Use persisted artifacts and observed results to derive:

```text
PLAN -> APPROVE -> CONTEXT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE
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

Receipts compress narration, never source, diffs, tests, static analysis, errors, or verification artifacts.

## PHP Defaults

- New files use `declare(strict_types=1);`.
- Prefer `final`, immutable state, constructor injection, and `readonly` where valid.
- Use explicit native types and precise PHPDoc; contain unavoidable dynamic input at one validated boundary.
- Avoid `mixed`, suppression, silent fallback, and context-free exceptions.
- No one-implementation interface, speculative factory, generic manager, future-only switch, or dependency for a few stable lines.
- Preserve focused package ownership; the umbrella package orchestrates.

## Communication And Evidence

Lead with the useful result/action. Remove filler, repetition, ceremonial preambles, and speculative feature tours. Use normal grammar. Update only for a changed decision, result, blocker, scope, or phase.

Preserve exact paths, symbols, commands, numbers, constraints, negation, errors, source, full diffs, tests, static-analysis output, and verification artifacts. Summaries may point to evidence; they never replace it. Expand security warnings, irreversible actions, ordering, and ambiguous trade-offs.

## Hook Boundary

Hooks are behavioral guardrails, never correctness or security boundaries. Code, CI, trust-boundary validation, and offline installation must remain correct without them.

Resume hints may expose only validated unfinished task identifiers/projected state. Never inject free-form manifest `next_action`, disagreements, task prose, or copied evidence. Resolve authoritative state through `workflow status`.

## Safety, Validation, Completion

Minimal never removes trust-boundary validation, security controls, data-loss prevention, required transaction/concurrency guarantees, accessibility, explicit requirements, or the smallest meaningful regression check.

Run the narrowest proof first, then repository gates. Claim a pass only after observing the exit code. Stop when approved behavior is satisfied and every required gate is closed; do not manufacture follow-up work.
