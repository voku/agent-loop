---
name: agent-loop-simplify-audit
description: Audit a PHP repository for avoidable complexity beyond the current diff. Use agent-map to prioritize symbols and relationships, then report concrete deletion/reuse/stdlib/native/YAGNI opportunities without applying changes.
---

# Agent Loop Simplify Audit

Repo-wide counterpart to `agent-loop-simplify-review`. One-shot, read-only, complexity only.

## Start Bounded

Use generated navigation state to choose where to inspect instead of reading the repository front to back:

```bash
vendor/bin/agent-loop map stats
vendor/bin/agent-loop map query <suspect-symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
```

Use `rg` for structural smells the map does not model: one-implementation interfaces, single-product factories, pass-through wrappers, dead config flags, duplicated helpers, and dependencies used for trivial functionality.

Map data selects candidates. Every finding must be verified against real source and actual callers.

## Findings

Same tags as the diff review:

- `delete:` dead/speculative behavior;
- `reuse:` existing repository code already owns it;
- `stdlib:` PHP standard library replaces it;
- `native:` platform/database/protocol replaces it;
- `yagni:` abstraction/config/layer has no demonstrated second use;
- `shrink:` same behavior with a concrete smaller local implementation;
- `boundary:` behavior sits in the wrong `agent-*` package.

Format:

```text
<tag> <what to remove/simplify>. <replacement>. [<path>:<line>]
```

Rank by maintenance surface removed. Estimate lines/dependencies only when the raw source makes the replacement concrete; otherwise do not invent numbers.

Nothing actionable: `Lean already. Stop.`

## Boundaries

Do not apply fixes. Do not treat required validation, security, accessibility, error handling, package boundaries, or regression tests as bloat. Correctness/security/performance review remains separate.
