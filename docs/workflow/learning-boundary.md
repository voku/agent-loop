# Learning boundary

`agent-loop` keeps the review/workflow safety spine separate from durable
memory. The learning pipeline may collect findings, build candidates, and help
humans evaluate patterns, but those artifacts are not durable memory by
appearance alone.

## Boundary rule

- Findings are not durable memory.
- Learning candidates are not durable memory.
- Only reviewed decisions become durable guidance.

This means a workflow can compile recall context, run blind-spot review, close a
session, and record learning evidence without automatically promoting anything
into `MEMORY.md` or active guidance. Durable guidance remains a human-reviewed
choice.

## Human MEMORY.md promotion review

Use the memory review command when a repository has a `MEMORY.md` promotion
queue:

```bash
agent-loop memory review --file MEMORY.md
```

`MemoryPromotionAnalyzer` is the human review boundary for `MEMORY.md`
promotion state. It reports entries that still need promotion review; it does
not approve, rewrite, or auto-promote durable memory.

## Non-goals

- No automatic durable-memory promotion.
- No changes to `voku/agent-learning` package behavior.
- No LLM calls from runtime code.
