---
name: agent-loop-investigate
description: Locate PHP definitions, callers, tests, change sites, and evidence-backed temporal relationships with agent-map and bounded source reads. Read-only: report exact path/line/symbol evidence and do not propose or apply fixes.
---

# Agent Loop Investigate

Use this for "where is X", "what calls Y", "which tests cover Z", or before a shared PHP change when the owning path is not already known.

## Job

Locate. Verify in real source. Report. Stop.

Do not edit code and do not turn a locator task into architecture advice.

## Navigation

When the task already names a concrete PHP class, method, or function, resolve that identity directly instead of rediscovering it through fuzzy/text search:

```bash
vendor/bin/agent-loop map scope '<symbol>' --format=toon
```

`scope` is the cheapest exact structural view: it reports the resolved source range and bounded calls inside that range. If the short symbol is ambiguous, retry with the fully qualified identity rather than choosing a ranked match.

When the investigation is preparation for an intended method edit, use bounded edit context before broadening:

```bash
vendor/bin/agent-loop map context '<symbol>' --format=toon
```

The result may already contain the primary method, contracts/overrides, direct callers that may need adaptation, direct callees, referenced signature types, relevant tests, blind spots, omissions, and bounded source slices. Do not repeat those facts with repository-wide search merely because another tool can.

Ask exact relation questions only when the relation itself is the task or remains unresolved after context:

```bash
vendor/bin/agent-loop map callers '<symbol>' --format=toon
vendor/bin/agent-loop map callees '<symbol>' --format=toon
```

When the task names a path rather than a symbol, inspect the indexed file directly:

```bash
vendor/bin/agent-loop map file <path> --format=toon
```

When the PHP target is not known yet, narrow with existing Map navigation before guessing source files:

```bash
vendor/bin/agent-loop map query <term> --format=toon
vendor/bin/agent-loop map related <term> --format=toon
vendor/bin/agent-loop map changed --base=<ref> --format=toon
```

When the PHP repository is unfamiliar and the task does **not** identify a useful symbol/path yet, orient once before guessing search terms:

```bash
vendor/bin/agent-loop map discover --limit=10
```

Treat the inferred architecture as a navigation coordinate, not a subsystem oracle. Choose the smallest plausible region from the reported hierarchy and inspect it before switching to symbol queries:

```bash
vendor/bin/agent-loop map discover --region=<label-or-id> --limit=10
```

The region drill-down is the bridge between repository-level orientation and concrete source navigation. It exposes the selected root-to-region path, bounded files, interface files, and boundary evidence. Namespace-less PHP remains first-class because directory and file structure are independent architecture signals.

After the region is narrowed, switch to `scope` as soon as one exact symbol is known. Use `query`, `related`, `callers`, `callees`, or bounded source reads only for the remaining question. Do not repeat repository-wide discovery once a plausible region or concrete target is known.

For a proposed shared-method change, use architecture-aware impact only when propagation beyond exact callers/context is the actual question:

```bash
vendor/bin/agent-loop map impact 'App\\Service\\Thing::run' --depth=2
```

Impact keeps exact node evidence and uncertainty while grouping propagation by inferred architecture region. Dynamic or multiple-target paths remain uncertain.

## Temporal Evidence

Use temporal evidence only when the question is about change risk, recurring co-change, a suspected hidden relationship, or how an entity evolved. Do not pay for history on every locator task.

Start with bounded Git co-change and explicit heuristic claims:

```bash
vendor/bin/agent-loop map history coupling --commits=100 --top=20
vendor/bin/agent-loop map history claims --commits=100 --top=20 --min-ratio=0.6
```

`history coupling` is evidence. `history claims` is a heuristic navigation lead, never source truth or a refactoring instruction. Keep its supporting commit revisions and verify the current relationship through the map and real source before reporting a conclusion.

When the configured map history database exists and the evolution of a known entity matters, inspect it through the wrapper rather than opening the database directly:

```bash
vendor/bin/agent-loop map history show 'method:App\\Service\\Thing::run'
```

If explicit before/after map snapshots already exist, `map history diff --before=... --after=...` can expose structural lifecycle facts without guessing from Git text diffs.

Do not run `history observe` during investigation or while tracked files are dirty. Recording history belongs at a clean Git checkpoint, post-merge/CI boundary, or another explicit reproducible state. Investigation reads temporal evidence; it does not manufacture a new observation just to answer the current question.

When a physical map path is needed, ask the project layout:

```bash
vendor/bin/agent-loop init paths --format=json
```

Never dump the generated symbol index, search database, or history database. Map and temporal output are navigation/evidence, not a substitute for source verification. Read only the selected real source ranges before reporting a hit.

Use `rg`/`rg --files` only when the question is literal/string/config/template/filename-shaped or Map cannot model the required evidence. Do not use text search to rediscover a PHP identity that `scope` already resolves.

## Terminal Result Contract

Verified hits:

```text
STATUS: located
<path>:<line> — `<symbol>` — <short factual role>
```

Group 3+ results under `Defs`, `Callers`, `Tests`, `Refs`, or `Sites`. End with counts when useful.

No verified hit:

```text
STATUS: no_match
```

Required source/context cannot be read or verified:

```text
STATUS: blocked
UNKNOWN: <exact missing source/context>.
```

Keep exact paths, line numbers, symbols, literals, relevant caller relationships, and temporal revision evidence when used. No exploration diary. Never turn `no_match` into a guessed location.

## Escalation

If the request changes from locating to editing, return the verified target set and stop. Use `agent-loop-surgical-edit` for a bounded 1-2 file change or the normal governed workflow for broader work.
