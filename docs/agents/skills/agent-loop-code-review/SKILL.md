---
name: agent-loop-code-review
description: Review a complete PHP diff for correctness with terse, actionable findings. Preserve full evidence, use agent-map for caller/context lookup, and keep complexity review separate.
---

# Agent Loop Code Review

Review the complete raw diff. Findings only; no praise, throat-clearing, or feature tour.

Use `agent-loop map changed --base=<ref>` to orient changed symbols and `map related <symbol>` when a finding depends on callers or shared behavior. Read the relevant real source before asserting a problem.

## Finding Format

```text
<path>:<line>: <severity> <problem>. <concrete fix>.
```

Severity:

- `bug:` wrong result, crash, data loss, security failure;
- `risk:` edge case, race, leak, missing guard, contract mismatch;
- `nit:` naming/style only when explicitly requested;
- `question:` author intent is required before judging.

Zero findings: `No correctness findings.`

## Rules

- Preserve exact symbols, values, paths, error text, and contract names.
- Do not restate the diff.
- Do not invent a refactor when a local fix exists.
- Need more context: inspect it or mark the finding as a question; never guess.
- Security findings get enough explanation to make impact and trust boundary clear. Brevity never hides risk.
- Do not apply fixes during review.

Run `agent-loop-simplify-review` separately when the goal is unnecessary complexity. Correctness and simplification are different passes.
