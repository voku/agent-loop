---
name: agent-loop-investigate
description: Locate PHP definitions, callers, tests, change sites, and evidence-backed temporal relationships with agent-map and bounded source reads. Read-only: report exact path/line/symbol evidence and do not propose or apply fixes.
---

# Agent Loop Investigate

**Trigger Anchor:** Locating symbols, callers, tests, or impact sites -> query `agent-map` first, verify selected ranges in real source, report exact `path:line` evidence, and stop without editing.

## Navigation Decision Table

| Question / Target | Primary Tool Command | Follow-up Verification |
|---|---|---|
| Named class/method/function | `vendor/bin/agent-loop map scope '<symbol>' --format=toon` | Read exact source range reported |
| Method edit preparation | `vendor/bin/agent-loop map context '<symbol>' --format=toon` | Inspect callers, callees, tests |
| Exact callers / callees | `vendor/bin/agent-loop map callers '<symbol>' --format=toon`<br>`vendor/bin/agent-loop map callees '<symbol>' --format=toon` | Verify call sites in calling files |
| File-scoped symbols | `vendor/bin/agent-loop map file <path> --format=toon` | Inspect classes/methods in file |
| Approximate keyword search | `vendor/bin/agent-loop map query <term> --format=toon`<br>`vendor/bin/agent-loop map related <term> --format=toon` | Switch to `map scope` once identity resolved |
| Diff symbol changes | `vendor/bin/agent-loop map changed --base=<ref> --format=toon` | Inspect modified public symbols |
| Unfamiliar repository | `vendor/bin/agent-loop map discover --limit=10`<br>`vendor/bin/agent-loop map discover --region=<region-id>` | Narrow to region, then symbol `scope` |
| Deep change impact | `vendor/bin/agent-loop map impact '<symbol>' --depth=2` | Inspect uncertain propagation paths |
| Temporal co-change | `vendor/bin/agent-loop map history coupling --commits=100 --top=20`<br>`vendor/bin/agent-loop map history claims --commits=100` | Verify claims in current source |
| Literal/config/template | `rg '<pattern>'` or `rg --files` | Only when Map cannot model the entity |

Never dump generated `.agent-loop/map` databases. Map output is navigation coordinates; verify against real source.
Do not use text search to rediscover a PHP identity that `scope` already resolves.
`map history claims` is a heuristic lead, never source truth. Do not run `history observe` during investigation; record history only at a clean Git checkpoint.
Read-only: locate and report without editing.

### Bad vs Good Navigation

### Bad
```bash
# Grepping repository repeatedly to find class declaration and callers
grep -rn "class UserService" src/
grep -rn "->save(" src/
```

### Good
```bash
# Exact structural resolution via map scope and context
vendor/bin/agent-loop map scope 'App\Service\UserService::save' --format=toon
vendor/bin/agent-loop map context 'App\Service\UserService::save' --format=toon
```

## Terminal Result Contract

Verified matches:
```text
STATUS: located
<path>:<line> — `<symbol>` — <short factual role>
```
*(Group 3+ results under Defs, Callers, Tests, Refs, or Sites)*

No match found:
```text
STATUS: no_match
```

Missing context or unreadable source:
```text
STATUS: blocked
UNKNOWN: <exact missing source file or context>
```

## Escalation

Read-only locator role. If work changes from finding to editing, return the verified target set and escalate to `agent-loop-surgical-edit` (1–2 files) or the main governed workflow (3+ files or architectural change).
