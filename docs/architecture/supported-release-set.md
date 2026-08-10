# Supported `agent-*` release set

Status: initial compatibility baseline for
[agent-loop#20](https://github.com/voku/agent-loop/issues/20)  
Recorded: 2026-08-06  
Updated: 2026-08-10 (`agent-map` baseline raised to `0.5.0` for evidence-backed architecture discovery)

## Purpose

Composer constraints describe which versions may resolve. They do not prove that
a concrete installed package set works together through the complete lifecycle.

This document records the initial release set that the clean-consumer dogfood
gate must install and exercise.

## Baseline set

| Package | Baseline release | `agent-loop` constraint | Required baseline capability |
| --- | ---: | --- | --- |
| `voku/agent-loop` | `0.12.0` | root package | joined workflow status, run manifest v1 projection, ephemeral experiments, package-owned agent assets, current lifecycle contract |
| `voku/agent-kanban` | `0.2.1` | `0.2.*` | typed board API, safe card mutations, deterministic verification/JSON |
| `voku/agent-session` | `0.3.0` | `0.3.*` | revisioned briefs/approvals, validation evidence, explicit ephemeral sessions |
| `voku/agent-map` | `0.5.0` | `^0.5.0` | semantic map, incremental refresh, hybrid search, evidence-backed discovery/ranking/impact, namespace/directory/file coupling |
| `voku/agent-recall-compiler` | `0.9.2` | `^0.9.0` | target-aware recall, map-search candidates, deterministic verification artifacts |
| `voku/agent-learning` | `0.8.12` | `0.8.*` | evidence-backed learning, outcome histories, deterministic maintenance/projections |

The baseline is a test input, not a promise to freeze patch versions. A changed
resolved version updates the recorded release-set result and must still satisfy
the required capabilities.

## Current Composer boundary

The root package currently requires:

```json
{
  "voku/agent-kanban": "0.2.*",
  "voku/agent-learning": "0.8.*",
  "voku/agent-map": "^0.5.0",
  "voku/agent-recall-compiler": "^0.9.0",
  "voku/agent-session": "0.3.*"
}
```

The compatibility gate must test the actual resolved lock file and record every
resolved version. It must not report only the declared constraints.

## Installation modes

### Released baseline

Install the currently supported released versions from normal Composer
resolution. This is the minimum public compatibility proof.

### Candidate package under test

For an integration-affecting change, replace only the candidate package with an
explicit path/VCS candidate while keeping the remaining packages on the released
baseline.

The fixture must prevent a candidate checkout's nested `vendor/` from winning
over the consumer project's autoloader.

### Coordinated candidate set

Use only when a contract change intentionally requires coordinated updates in
more than one package. Record every candidate source and commit SHA. This mode
must not become the default escape hatch whenever independent versions fail.

## Capability identities to introduce

Exact capability names remain part of
[agent-loop#21](https://github.com/voku/agent-loop/issues/21), but the initial
inventory needs at least these categories:

| Capability category | Owning package | Why it matters |
| --- | --- | --- |
| board typed API/reference schema | `agent-kanban` | joined board/card status without parsing Markdown |
| session brief/approval reference schema | `agent-session` | bind the run to the exact approved revision |
| ephemeral session semantics | `agent-session` | keep experiments outside governed gates |
| map/readiness/context reference schema | `agent-map` | bind repository evidence and recovery state |
| map architecture discovery semantics | `agent-map` | provide evidence-backed query-less orientation, impact paths, and physical coupling without inventing subsystem certainty |
| map-search candidate semantics | `agent-recall-compiler` + `agent-map` | preserve inferred/verified authority boundary |
| recall compilation reference schema | `agent-recall-compiler` | bind selected guidance and output hashes |
| verification-plan/key schema | `agent-recall-compiler` | preserve public/private answer boundary |
| edit verification result schema | `agent-loop` | bind execution to independent verdict |
| learning event-lineage schema | `agent-learning` | trace selection, outcomes and findings to one run |
| run-manifest schema | `agent-loop` | project the complete lifecycle without duplicating owners |
| managed guidance schema | `agent-loop` | detect runtime/guidance drift |

Capability checks should use schema-compatible ranges where possible. Exact
package versions remain diagnostics and Composer inputs, not the only proof of
compatibility.

## Required clean-consumer environment

The first gate should run on the repository's supported PHP matrix, with at
least one canonical environment for full lifecycle evidence:

- PHP 8.3 or newer;
- Composer 2;
- Git repository initialized with a deterministic fixture commit;
- no sibling `agent-*` checkout on the autoload path;
- no nested package `vendor/` used by the binaries;
- local-only execution with no required external model/API;
- SQLite/FTS capability reported explicitly;
- sqlite-vec availability reported, but semantic retrieval remains optional.

## Compatibility result

The release-set report must contain:

```json
{
  "schema_version": "1.0",
  "resolved_packages": {
    "voku/agent-loop": {
      "version": "0.11.0",
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

Volatile duration/timestamp fields, when included, must be marked as volatile and
excluded from byte-stability claims.

## Supported versus untested

The report uses three distinct terms:

- **supported**: package constraints allow the set and the release-set gate
  passed;
- **compatible by contract**: capability/schema ranges indicate compatibility,
  but the exact set has not been exercised;
- **untested**: neither an executed gate nor a declared compatible capability
  range proves the set.

“Composer installed it” does not by itself mean “the product lifecycle works.”
The dependency solver has many talents, but end-to-end epistemology is not among
them.

## Update rule

Update this baseline when:

- `agent-loop` changes a dependency constraint;
- an owning package changes an integration artifact or capability schema;
- the release-set gate resolves a new supported baseline;
- a previously supported combination fails and the failure is not a fixture
  defect.

Every update must link the machine-readable release-set report or the issue/PR
that establishes why no such report exists yet.
