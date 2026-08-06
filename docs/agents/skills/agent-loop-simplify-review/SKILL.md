---
name: agent-loop-simplify-review
description: Review a complete raw diff for unnecessary complexity in agent-* PHP code without replacing correctness, security, or performance review.
---

# Agent Loop Simplify Review

Review the complete raw diff, not a summary. For changed PHP, use `agent-loop map
changed`, `map file`, and `map related` to understand package ownership and
callers before suggesting deletion.

## Findings

One line per actionable finding:

`<file>:L<line>: <tag> <what to remove or simplify>. <replacement>.`

Tags:

- `delete:` dead code, unused flexibility, or speculative behavior;
- `reuse:` existing repository code already owns the behavior;
- `stdlib:` PHP standard library replaces custom code;
- `native:` platform, database, shell, or protocol replaces code or dependency;
- `yagni:` abstraction has one real implementation or caller;
- `shrink:` same verified behavior with a smaller local expression;
- `boundary:` behavior belongs in another focused `agent-*` package.

Rank findings by removed maintenance surface, not cleverness. Estimate removable
lines only when the replacement is concrete from the raw diff. Otherwise write
`net: not estimated` rather than inventing precision.

## Boundaries

This pass does not replace correctness, security, performance, or accessibility
review. Do not flag required validation, contextual exceptions, security checks,
or a focused regression test as bloat. Apply no changes automatically.

No findings: `Lean already. Continue with correctness review.`
