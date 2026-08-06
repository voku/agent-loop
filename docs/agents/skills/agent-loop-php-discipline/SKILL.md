---
name: agent-loop-php-discipline
description: Keep agent-* PHP changes minimal and verifiable while making agent communication concise without rewriting source, diffs, command output, or evidence artifacts.
---

# Agent Loop PHP Discipline

Use this skill for PHP changes in `agent-loop` and the focused `agent-*`
packages. It combines two separate controls:

1. concise communication for the human reviewer;
2. minimal correct implementation for the repository.

Do not merge those controls into an output-rewriting proxy. Human-facing prose
may be compressed. Evidence must remain complete.

## Fast Path

1. Read the requested behavior and the code path it actually touches.
2. Inspect existing implementations, callers, tests, and package ownership.
3. Choose the first minimal solution that satisfies the verified requirement.
4. Keep progress and final messages short, but preserve exact technical facts.
5. Review the raw diff and run the narrowest meaningful checks.
6. Run the repository's full static-analysis and formatting gates before delivery.

## Communication Discipline

Keep conversational output compact:

- remove filler, repetition, ceremonial preambles, and speculative feature tours;
- state findings, decisions, failures, and next actions directly;
- preserve exact paths, symbols, commands, numbers, errors, and constraints;
- use normal professional prose in persisted documentation, issues, PRs, commit
  messages, and user-facing copy;
- expand when brevity would make ordering, risk, or causality ambiguous.

Concise output exists to reduce human review time. Token savings are a side
effect, not the correctness criterion.

## Evidence Integrity

The following are evidence and must not be lossily rewritten:

- source files;
- `git diff` and per-file patches;
- test and static-analysis output;
- generated verification artifacts;
- harness-managed files containing truncated or redirected tool output;
- exact error messages needed for diagnosis.

Summaries may point to evidence. They must not replace it.

When a harness stores large output in a file, read that stored artifact as the
source of truth. Do not run it through a compressor or command-rewriting layer.
When completeness matters, compare expected file size, line count, or hash with
the producing step.

## Minimal Implementation Ladder

Stop at the first rung that fully satisfies the task:

1. Does this change need to exist? Skip speculative work.
2. Does the repository already implement it? Reuse the established path.
3. Does PHP's standard library solve it correctly? Use it.
4. Does the platform, database, shell, or existing protocol provide it? Use that.
5. Does an already-installed dependency own it? Reuse it.
6. Can one local change solve the root cause for every caller? Fix it there.
7. Only then add the minimum new code required.

The ladder runs after understanding the flow. A tiny patch in the wrong layer is
not minimal; it is merely a compact defect.

## PHP Defaults

- Use `declare(strict_types=1);` in new PHP files.
- Prefer `final`, immutable state, constructor injection, and `readonly` where
  the lifecycle permits it.
- Use explicit native types and precise PHPDoc for generics, array shapes,
  integer ranges, non-empty strings, class strings, and conditional returns.
- Avoid `mixed`; contain unavoidable dynamic input at one validated boundary.
- Throw contextual exceptions instead of silently falling back or suppressing
  errors.
- Prefer boring control flow and named domain concepts over clever helper chains.
- Do not add a one-implementation interface, speculative factory, generic
  manager, configuration switch, or abstraction for a hypothetical future.
- Keep package boundaries intact: the umbrella package orchestrates; focused
  packages own their domain behavior.

## Safety Floor

Minimal must never mean incomplete. Do not remove or weaken:

- trust-boundary validation;
- authentication, authorization, escaping, or other security controls;
- error handling that prevents data loss or corrupt state;
- concurrency or transaction guarantees required by the current behavior;
- accessibility requirements;
- behavior explicitly requested by the user;
- tests required to prove non-trivial logic.

Fix root causes, not only the reported symptom. Inspect callers before changing a
shared method or public contract.

## Agent-* Workflow

For non-trivial work:

```bash
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000
```

Use the semantic map for navigation, not as source evidence. Keep recall bounded
to the task, and record only decisions or validation results another agent would
otherwise have to rediscover.

## Validation

Use the smallest focused check first, then the repository gates:

```bash
vendor/bin/phpunit --filter '<focused test>'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
vendor/bin/php-cs-fixer fix --dry-run --diff
composer ci
```

Use the actual commands defined by the repository when they differ. Never claim
a check passed unless it ran and its exit status was observed.
