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
3. Use semantic navigation before broad PHP reads:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
```

Do not dump `.agent-map/php-symbols.json` or `.agent-map/search.sqlite`. Generated
indexes guide reads; they are not source evidence.

## Minimal Implementation Ladder

Stop at the first rung that fully satisfies the verified requirement:

1. No change needed: explain existing behavior.
2. Existing repository code owns it: reuse that path.
3. PHP standard library owns it: use it.
4. Platform, database, shell, or protocol owns it: use that.
5. Installed dependency owns it: reuse it.
6. One shared root-cause change fixes all callers: change it once.
7. Deterministic edit is sufficient: use `agent-loop edit --runner=auto`.
8. Only then add minimum new code.

Run the ladder after understanding the flow. A small patch in the wrong layer is
a compact defect.

## PHP Defaults

- New files use `declare(strict_types=1);`.
- Prefer `final`, immutable state, constructor injection, and `readonly` where valid.
- Use explicit native types and precise PHPDoc for generics, shapes, ranges,
  non-empty strings, class strings, and conditional returns.
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

## Safety Floor

Minimal never removes trust-boundary validation, security controls, data-loss
prevention, required transaction/concurrency guarantees, accessibility, explicit
requirements, or the smallest meaningful regression check.

## Validation

Run the narrowest proof first, then repository gates:

```bash
vendor/bin/phpunit --filter '<focused test>'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
vendor/bin/php-cs-fixer fix --dry-run --diff
composer ci
```

Use actual repository commands. Claim a pass only after observing its exit code.

## Completion

Report: what changed and why; exact validation; deliberate omissions and the
trigger that would justify them.
