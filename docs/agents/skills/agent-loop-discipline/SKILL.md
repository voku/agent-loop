---
name: agent-loop-discipline
description: Keep agent-* PHP work concise for humans, minimal in implementation, map-first in navigation, deterministic in workflow state, and exact in evidence. Use for coding, debugging, refactoring, review, and guidance changes.
---

# Agent Loop Discipline

Control three separate budgets:

1. human attention: concise progress and final replies;
2. implementation complexity: smallest correct change;
3. context: bounded source reads selected through `agent-map` and recall.

Never compress or rewrite raw evidence.

## Governed Workflow Activation

When the current task is explicitly using `agent-loop`, treat persisted workflow
state as the source of truth rather than inventing a parallel conversational
workflow.

1. Reuse the stable task id and inspect `workflow status` before mutating code.
2. Resume the active session for that task. Do not create a second active session.
3. Use `agent-loop-workflow` for plan, approval, context, implementation,
   validation, review, learning, verification, and close transitions.
4. A mutating governed task does not bypass its approved brief because the edit
   looks small. A locator or already-bounded read-only question may use a narrow
   role directly when no governed mutation is being performed.
5. If verified scope no longer matches the approved brief, re-plan. Do not
   silently widen the task and keep old approval or validation evidence.

Human gates stay human: approval of a work-brief revision, acceptance of real
risk or irreversible action, and genuinely missing product intent. Everything
else the available tools can inspect, edit, validate, or report remains agent
work; do not hand executable work back to the human as instructions.

## Before Editing

1. State requested behavior, non-goals, owning package, and validation briefly.
2. Trace the real call path and inspect callers before changing shared behavior.
3. Before broad PHP reads, navigate with `agent-map`:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
```

Skip map ceremony for trivial documentation or already-localized edits. Do not
dump `.agent-map/php-symbols.json` or `.agent-map/search.sqlite`; generated indexes
guide bounded source reads and are not source evidence.

## Role Routing

Use a narrow role only when its contract matches the verified task. Where the
client supports the bundled dedicated subagent, delegate to the name in
parentheses:

- locate definitions/callers/tests only -> `agent-loop-investigate`
  (`agent-loop-investigator`);
- already-understood one/two-file implementation -> `agent-loop-surgical-edit`
  (`agent-loop-surgical-builder`);
- correctness review of a diff/file -> `agent-loop-code-review`
  (`agent-loop-code-reviewer`);
- unnecessary complexity in the current diff -> `agent-loop-simplify-review`;
- repo-wide complexity audit -> `agent-loop-simplify-audit`;
- ambiguous, architectural, new-feature, or 3+ file work -> main governed workflow.

Useful bounded chain:

```text
investigator -> surgical builder -> code reviewer
```

Do not delegate a one-line answer merely to look agentic. Do not force broad work
through a small role to preserve a pretty workflow diagram.

## Minimal Implementation Ladder

Stop at the first rung that fully satisfies the verified requirement:

1. no change needed: explain existing behavior;
2. existing repository code owns it: reuse it;
3. PHP standard library owns it: use it;
4. platform, database, shell, or protocol owns it: use that;
5. installed dependency owns it: reuse it;
6. one root-cause change fixes all callers: change it once;
7. deterministic edit is sufficient: use `agent-loop edit --runner=auto`;
8. only then add minimum new code.

After the requirement is satisfied, stop. Do not add adjacent cleanup,
configuration, abstractions, compatibility, or policy unless the task requires
it or validation proves it necessary. A small patch in the wrong layer is still
a compact defect.

## Workflow State And Output

For a governed task, derive the current phase from persisted artifacts and
observed command results. Never advance because the work merely looks complete.
Use these phase names consistently:

```text
PLAN -> APPROVE -> CONTEXT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE
```

After a material transition, result, or blocker, use one compact receipt when a
human-facing progress update is useful:

```text
RESULT: <one verified fact, decision, changed artifact, or blocker>
STATE: <phase> <task-id> <brief revision when known>
NEXT: <one concrete action owned by the agent, or the exact human gate>
```

Do not emit a receipt for every tool call and do not repeat unchanged state. A
human gate is not a generic invitation to continue; name the exact approval,
risk decision, irreversible action, or missing intent that blocks progress.

On completion, report only:

```text
RESULT: <what changed and why>
EVIDENCE: <exact validation commands/results and decisive artifact paths>
OMITTED: <deliberate omissions plus observable revisit trigger, or none>
```

The receipt compresses narration, not evidence. Raw diffs, test output, static
analysis, source, errors, and verification artifacts remain complete where they
are stored or inspected.

## PHP Defaults

- New files use `declare(strict_types=1);`.
- Prefer `final`, immutable state, constructor injection, and `readonly` where valid.
- Use explicit native types and precise PHPDoc where PHP cannot express the type.
- Avoid `mixed`; contain dynamic input at one validated boundary.
- Throw contextual exceptions. No suppression or silent fallback.
- No one-implementation interface, speculative factory, generic manager,
  future-only switch, or dependency for a few stable lines.
- Preserve focused package ownership; the umbrella package orchestrates.

## Communication

- Remove filler, repetition, ceremonial preambles, and speculative feature tours.
- Use clear full sentences; do not break grammar to save tokens.
- Lead with the useful result or action, then state only context needed for the
  next decision.
- Update only when a decision, result, blocker, scope, or governed phase changes.
- Preserve exact paths, symbols, commands, numbers, constraints, negation, and errors.
- Persisted docs, commits, issues, PRs, and user-facing text use normal prose.
- Expand security warnings, irreversible actions, ordering, and ambiguous trade-offs.

## Evidence Integrity

Keep complete and unchanged: source, full diffs, tests, static-analysis output,
verification artifacts, redirected harness files, and decisive errors. Summaries
may point to evidence; they never replace it. Never rewrite a command or output
merely to make it shorter.

## Hook Boundary

Hooks are behavioral guardrails, never a correctness or security boundary. Code,
CI, trust-boundary validation, and the offline `install-assets` contract must stay
correct when a host does not dispatch a hook. Do not grow hook blacklists to
simulate a security sandbox.

## Safety Floor

Minimal never removes trust-boundary validation, security controls, data-loss
prevention, required transaction/concurrency guarantees, accessibility, explicit
requirements, or the smallest meaningful regression check.

## Validation

Run the narrowest proof first, then repository gates. Use actual repository
commands and claim a pass only after observing its exit code.

## Completion

Stop when the approved behavior is satisfied and every required gate is closed.
Do not manufacture a follow-up task merely to keep the agent busy.
