# Supported `agent-*` release set

Status: `0.14.0` coordinated release candidate; public support starts only after
the clean-consumer installed release-set gate passes against published package
versions.  
Recorded: 2026-08-06  
Updated: 2026-08-10

## Purpose

Composer constraints describe which versions may resolve. They do not prove that
a concrete installed package set works together through the complete lifecycle.

This document records both the candidate set being qualified for `agent-loop`
0.14.0 and the rule for when that candidate becomes a supported published set.

## 0.14 candidate set

| Package | Intended release | `agent-loop` constraint | Required capability |
| --- | ---: | --- | --- |
| `voku/agent-loop` | `0.14.0` | root package | governed CONTRACT phase, bound L1 mutation/close gates, joined run/status projection |
| `voku/agent-kanban` | `0.2.1` | `0.2.*` | typed board API, safe card mutations, deterministic verification/JSON |
| `voku/agent-session` | `0.4.0` | `0.4.*` | revisioned briefs/approvals plus approved operating-prompt policy and validation evidence |
| `voku/agent-map` | `0.5.0` | `^0.5.0` | semantic map, incremental refresh, hybrid search, evidence-backed discovery/ranking/impact, namespace/directory/file coupling |
| `voku/agent-recall-compiler` | `0.10.0` | `^0.10.0` | target-aware recall, five-section L2 -> L1 construction, project capabilities, recipe outcomes, verification artifacts, query-less architecture discovery fallback |
| `voku/agent-learning` | `0.9.0` | `0.9.*` | evidence-backed learning plus physical canonical-target proof for APPLIED Memory/Skill guidance |

The coordinated candidate CI pins the owning package commits exactly. `agent-map`
`0.5.0` is already a published release; the other owner-package commits remain
candidate evidence until their corresponding tags are published.

```text
agent-map             0.5.0 (published)
agent-learning        221a9cc893ddf6b350cd87ba615ad003443662d9
agent-session         8b8700522c28e2650b8c6cd11b3317127fbb8649
agent-recall-compiler candidate for 0.10.0 (includes agent-map 0.5 discovery integration)
agent-skills          c7e9d8bdda59d957600bca8dc9f787f03286b277
```

Those commit pins prove a coordinated candidate. They do **not** make the
unpublished versions public or supported. The installed release-set gate must
still resolve the intended package versions through normal Composer resolution.

## Current Composer boundary

The 0.14 root package requires:

```json
{
  "voku/agent-kanban": "0.2.*",
  "voku/agent-learning": "0.9.*",
  "voku/agent-map": "^0.5.0",
  "voku/agent-recall-compiler": "^0.10.0",
  "voku/agent-session": "0.4.*"
}
```

The compatibility gate records the actual resolved lock file. It must not report
only the declared constraints.

## Candidate versus supported

The release process intentionally answers two different questions.

### Coordinated candidate set

Before tags exist, CI may install exact owning-package commits through non-
symlinked path repositories and synthetic candidate versions. This proves the new
cross-package contracts together and catches integration drift before publishing
anything irreversible.

For 0.14 that gate covers the PHP matrix, the governed execution-contract dogfood,
`Agent-loop shapes itself`, and the new `agent-map` discovery path through the
unified binary.

### Published release set

The installed release-set dogfood must then create a clean consumer with the
normal `agent-loop` Composer constraints. No path/VCS fallback may be introduced
for the focused packages merely to turn the gate green.

The 0.14 candidate becomes **supported** only after:

1. `agent-map` `0.5.0`, `agent-learning` `0.9.0`, `agent-session` `0.4.0`, and
   `agent-recall-compiler` `0.10.0` are published through the normal repository /
   Composer release path;
2. a clean consumer resolves those release lines;
3. the installed release-set lifecycle passes; and
4. `agent-loop` `0.14.0` is published from the reviewed integration commit.

A failed clean-consumer `composer update` with no resolved release set is a
publication failure, not evidence that constraints should be weakened.

## Capability boundaries

The release-set proof is capability-oriented rather than only version-oriented:

| Capability | Owning package | Why it matters |
| --- | --- | --- |
| board typed API/reference schema | `agent-kanban` | joined board/card state without parsing Markdown |
| WorkBrief/approval schema | `agent-session` | bind the run to exact approved task + prompt policy |
| ephemeral session semantics | `agent-session` | keep experiments outside governed gates |
| map/readiness/context schema | `agent-map` | bind repository evidence and recovery state |
| map architecture discovery semantics | `agent-map` | orient unfamiliar PHP tasks with evidence-backed entrypoints, hubs, impact paths, and physical coupling without inventing subsystem certainty |
| map-search candidate semantics | `agent-recall-compiler` + `agent-map` | preserve inferred/verified authority boundary |
| recall architecture-discovery fact | `agent-recall-compiler` + `agent-map` | provide bounded query-less orientation only when a task has no explicit files/targets, and refuse stale maps |
| recall compilation + prompt facts | `agent-recall-compiler` | bind selected guidance/recipes and output hashes |
| project-capability evidence | `agent-recall-compiler` | expose supported repository facts without inventing commands |
| verification-plan/key schema | `agent-recall-compiler` | preserve public/private answer boundary |
| recipe outcome schema | `agent-recall-compiler` | separate recipe exposure/usefulness from normal guidance outcomes |
| execution-contract schema/gate | `agent-loop` | bind project-specific L1 to approved policy and current recall |
| edit verification result schema | `agent-loop` | bind execution to an independently checkable verdict |
| learning event-lineage schema | `agent-learning` | trace selection/outcomes/findings to one run |
| APPLIED canonical-target proof | `agent-learning` | prevent proposal state from outrunning physical repository reality |
| run-manifest schema | `agent-loop` | project the complete lifecycle without duplicating owners |
| managed guidance/asset schema | `agent-loop` + `agent-skills` | detect runtime/guidance drift and preserve explicit skill provenance |

Exact package versions remain important release inputs and diagnostics, but the
clean-consumer scenarios are the proof that the required capabilities compose.

## Required clean-consumer environment

The gate runs with:

- PHP 8.3 or newer;
- Composer 2;
- Git repository initialized with a deterministic fixture commit;
- no sibling `agent-*` checkout on the consumer autoload path;
- no nested package `vendor/` used by installed binaries;
- no required hosted model/API;
- SQLite/FTS capability reported explicitly;
- optional semantic-search extensions reported without making retrieval depend on
  them.

## Compatibility result

The release-set report records at least:

```json
{
  "schema_version": "1.0",
  "resolved_packages": {
    "voku/agent-loop": {
      "version": "0.14.0-or-candidate",
      "source": "dist|vcs|path",
      "reference": "commit-or-dist-reference"
    }
  },
  "platform": {
    "php": "8.x.y",
    "composer": "2.x.y",
    "os": "..."
  },
  "capabilities": [],
  "scenarios": [],
  "result": "passed|failed"
}
```

Volatile duration/timestamp/environment values are evidence rather than replay
identity and must not be mistaken for semantic drift.

## Supported versus candidate versus untested

Use these terms precisely:

- **candidate-proven**: exact coordinated commits passed candidate integration
  gates but one or more intended package releases are not yet published;
- **supported**: normal package constraints resolve the published set and the
  clean-consumer release-set gate passed;
- **compatible by contract**: declared capability/schema ranges indicate likely
  compatibility, but the exact set has not been exercised;
- **untested**: neither an executed gate nor a declared capability contract proves
  the set.

“Composer installed it” is still not the same statement as “the product
lifecycle works.” The dependency solver remains stubbornly uninterested in our
epistemology.

## Update rule

Update this baseline when:

- `agent-loop` changes a dependency constraint;
- an owning package changes an integration artifact/capability schema;
- the release-set gate resolves a new supported baseline;
- a previously supported combination fails for a real package reason rather than
  a fixture defect.

Every supported-set update must point at the machine-readable release-set evidence
or the release issue explaining why the candidate has not crossed the publication
gate yet.
