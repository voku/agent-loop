---
name: agent-loop-investigate
description: Locate PHP definitions, callers, tests, and change sites with agent-map and bounded source reads. Read-only: report exact path/line/symbol evidence and do not propose or apply fixes.
---

# Agent Loop Investigate

Use this for "where is X", "what calls Y", "which tests cover Z", or before a shared PHP change when the owning path is not already known.

## Job

Locate. Verify in real source. Report. Stop.

Do not edit code and do not turn a locator task into architecture advice.

## Navigation

When the task names a concrete symbol or path, start with the smallest relevant map operation:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
```

When the PHP repository is unfamiliar and the task does **not** identify a useful symbol/path yet, orient once before guessing search terms:

```bash
vendor/bin/agent-loop map discover --limit=10
```

Treat `discover` as evidence-backed orientation, not a subsystem oracle. It reports entrypoint candidates, hubs, orchestrators, type hubs, namespace coupling, directory coupling, file coupling, and uncertainty from the existing map. Namespace-less PHP is still navigable through directory/file coupling.

After discovery, narrow immediately with `query`, `related`, `callers`, `callees`, or bounded source reads. For a proposed shared-method change, use impact before widening the read set:

```bash
vendor/bin/agent-loop map impact 'App\\Service\\Thing::run' --depth=2
```

Dynamic or multiple-target paths remain uncertain. Do not repeat discovery after a concrete target is known merely to collect more context.

Use `rg` only when the map cannot answer a literal/string/config/template question. Never dump `.agent-map/php-symbols.json` or `.agent-map/search.sqlite`.

Map output is navigation, not evidence. Read only the selected real source ranges before reporting a hit.

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

Keep exact paths, line numbers, symbols, literals, and relevant caller relationships. No exploration diary. Never turn `no_match` into a guessed location.

## Escalation

If the request changes from locating to editing, return the verified target set and stop. Use `agent-loop-surgical-edit` for a bounded 1-2 file change or the normal governed workflow for broader work.
