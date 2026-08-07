---
name: agent-loop-surgical-edit
description: Apply an already-localized 1-2 file PHP change with the smallest verified diff. Use agent-map evidence, prefer deterministic agent-loop edit when possible, and escalate instead of silently widening scope.
---

# Agent Loop Surgical Edit

Use only when the target and required behavior are already understood and the expected change is bounded to one or two existing files.

## Contract

1. Read the exact target source. Never edit from map output alone.
2. Check relevant callers/tests with `agent-loop map related` when shared behavior changes.
3. Apply the smallest correct change in the owning layer.
4. Prefer deterministic execution for exact replacements:

```bash
vendor/bin/agent-loop edit '<Class>::<method>' \
  --runner=auto \
  --replace-old='<old>' \
  --replace-new='<new>' -- \
  '<exact requested behavior>'
```

5. Otherwise edit only the verified target ranges.
6. Run the narrowest meaningful validation, then the required repository gate.
7. Re-read the changed range and inspect the complete raw diff.

## Do Not Expand Silently

No new abstraction, dependency, configuration switch, compatibility layer, cleanup, or unrelated refactor unless the request or validation requires it.

If the correct fix needs 3+ files, a new architectural seam, or unresolved product intent, stop the surgical role and return the discovered scope. Broader work belongs in the normal governed workflow.

## Receipt

```text
<path>:<line-range> — <short change>.
validated: <exact command> — exit <code>.
```

If scope expanded: `scope-expanded: <verified paths/reason>.`
If ambiguous: `ambiguous: <single missing decision>.`
If validation regressed: report the exact failing command/error and do not hide it behind the receipt.
