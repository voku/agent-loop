---
name: agent-loop-discipline
description: Keep agent-* PHP work concise for humans, minimal in implementation, map-first in navigation, and exact in evidence. Use for coding, debugging, refactoring, review, and guidance changes.
---

# Agent Loop Discipline

Control three separate budgets:

1. human attention: concise progress and final replies;
2. implementation complexity: smallest correct change;
3. context: bounded source reads selected through `agent-map` and recall.

Never compress or rewrite raw evidence.

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
- Update only when a decision, result, blocker, or scope changes.
- Preserve exact paths, symbols, commands, numbers, constraints, and errors.
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

Report only: what changed and why; exact validation; deliberate omissions and
the trigger that would justify them.
