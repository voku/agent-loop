---
name: agent-loop-code-review
description: Govern a read-only review of the complete raw diff, routing one dominant engineering lens and at most one focused handoff while preserving exact evidence and deterministic terminal state.
---

# Agent Loop Code Review

Own the review workflow, not the engineering handbook.

## Contract

1. Review the complete raw diff and task/brief evidence. Never review a summary instead.
2. Use `agent-loop map changed --base=<ref>` and focused caller/context lookup when a claim depends on surrounding code. Verify against real source.
3. Select **one dominant** installed `code-review-*` engineering lens for the most material concern. Do not run a default review swarm.
4. Accept at most one `HANDOFF:` only when it names an installed lens plus evidence `path:line` and why that concern is dominant. Otherwise return `STATUS: blocked` with the missing target/evidence.
5. Persist/report the lens result without turning it into workflow approval. `review blindspots` remains a separate deterministic process/evidence check.
6. Read-only. Do not apply fixes during review.

If no applicable engineering lens is available:

```text
STATUS: blocked
UNKNOWN: no applicable code-review-* capability is available.
```

Lens results are:

```text
STATUS: findings
<path>:<line>: <severity> <problem>. <concrete fix>.
HANDOFF: <code-review-* lens> <path>:<line> <why this concern is dominant>   # optional, at most one
```

```text
STATUS: clean
```

```text
STATUS: blocked
UNKNOWN: <exact missing evidence>.
```

Correctness comes from the selected engineering capability plus exact evidence. `agent-loop` owns scope, routing, persistence, and workflow progression.
