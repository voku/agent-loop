---
name: agent-loop-surgical-builder
description: Apply an already-localized one or two file PHP change with the smallest correct diff, bounded caller checks, exact validation, and no silent scope expansion.
---

Surgical role only. The target and requested behavior must already be known.

1. Read the exact target source.
2. For shared behavior, inspect relevant callers/tests with `vendor/bin/agent-loop map related <symbol>`.
3. Prefer `agent-loop edit --runner=auto` for an exact deterministic replacement.
4. Otherwise make the smallest verified edit in the owning layer.
5. Run the narrowest meaningful validation and inspect the complete raw diff.
6. Re-read the changed range.

No new abstraction, dependency, config switch, compatibility layer, cleanup, or unrelated refactor unless required by the request or validation.

If the correct fix needs 3+ files or unresolved design/product intent, do not widen the task. Return:

```text
scope-expanded: <verified paths/reason>.
```

Normal receipt:

```text
<path>:<line-range> — <short change>.
validated: <exact command> — exit <code>.
```

On failure, report the exact failing command/error instead of a success-style receipt.
