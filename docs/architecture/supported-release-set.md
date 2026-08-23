# Supported `agent-*` release set

Status: current clean-consumer compatibility evidence  
Recorded: 2026-08-06  
Updated: 2026-08-23 (`agent-loop` 0.17.1 release line)

## Purpose

Composer constraints describe which versions may resolve. They do not prove that
a concrete installed package set works together through the complete lifecycle.

This document records the current declared boundary and one exact exercised
release set. The machine-readable clean-consumer gate is authoritative for that
run; this page is its human projection.

## Current declared boundary

`agent-loop` 0.17.1 declares:

```json
{
  "voku/agent-kanban": "^0.3.2",
  "voku/agent-learning": "^0.13.2",
  "voku/agent-map": "^0.8.8",
  "voku/agent-recall-compiler": "^0.13.11",
  "voku/agent-session": "^0.6.2",
  "voku/itp-context": "^0.3.0"
}
```

The root development alias is `0.17.x-dev`.

## 0.17.1 exercised release set

The exact PR #273 candidate crossed the installed release-set dogfood on
2026-08-23 before 0.17.1 was published. Composer resolved these released
first-party dependencies through normal package resolution:

| Package | Exercised release | Declared constraint | Status |
| --- | ---: | --- | --- |
| `voku/agent-kanban` | `0.3.2` | `^0.3.2` | supported |
| `voku/agent-learning` | `0.13.3` | `^0.13.2` | supported |
| `voku/agent-map` | `0.8.8` | `^0.8.8` | supported |
| `voku/agent-recall-compiler` | `0.13.11` | `^0.13.11` | supported |
| `voku/agent-session` | `0.6.2` | `^0.6.2` | supported |
| `voku/itp-context` | `0.3.0` | `^0.3.0` | supported |

The release-set report used schema `2.0` and recorded `result=passed`. The only
candidate package was `agent-loop` itself from the PR checkout; the dependencies
above were released packages, not sibling/path candidates. The same candidate
also passed PHP 8.3/8.4/8.5 CI, diagnostics, governed execution-contract and
self-shape dogfood, prompt-primitives dogfood, and the installed rename/removal
lifecycles.

Release evidence:

- PR #273 source candidate: `83cf35a6041f5bbd7567a3c2c789ca15a55d7577`;
- merged owner commit: `fefe858055059251f4ceb891d50096af41958eda`;
- release content target: `c4f9d28cc1518310e80cd87be5cfae2a5c9f0cf6`;
- release marker commit: `5c3d34f2bdeef6f95f1efabd8c481d92a430a4b9`;
- release tag: `0.17.1`;
- CI workflow run: `32663193607`;
- release-set artifact: `9499313173`.

Compatible dependency patches may appear later. They do not silently replace
this pinned evidence; project documentation moves only when a replacement run is
recorded explicitly.

## Installation modes

### Released baseline

Install the supported released versions through normal Composer resolution. This
is the public compatibility proof.

### Candidate package under test

For an integration-affecting change, replace only the candidate package with an
explicit path/VCS candidate while keeping the remaining packages on released
versions. Record the exact candidate commit and every resolved dependency.

The fixture must prevent a candidate checkout's nested `vendor/` from winning
over the consumer project's autoloader.

### Coordinated candidate set

Use only when a contract change intentionally requires coordinated updates in
more than one package. Record every candidate source and commit SHA. This mode
must not become the default escape hatch when independently released versions
fail.

## Capability ownership

| Capability category | Owning package | Required boundary |
| --- | --- | --- |
| board typed API/reference schema | `agent-kanban` | joined board/card status without parsing Markdown |
| Session/approval/validation evidence | `agent-session` | bind working memory to the authoritative Run/Contract identity |
| semantic map and refactoring/removal plans | `agent-map` | read-only typed evidence; mutation remains outside Map |
| Recall compilation and verification-plan evidence | `agent-recall-compiler` | bounded selected guidance with deterministic provenance |
| Learning events/findings/proposals | `agent-learning` | durable evidence-backed learning owned outside Session |
| governed lifecycle, edit application and verification | `agent-loop` | only Loop accepts governed transitions and owns mutation/verification |
| optional external process execution | `agent-loop-runner` | downstream consumer of released typed `agent-loop` execution APIs only |

`agent-loop-runner` is not part of this supported release set yet. Its draft PR
#2 consumes the released `agent-loop ^0.17.0` execution API, which includes
0.17.1, but Runner joins the supported set only after its own installed-consumer
dogfood and Definition of Done are complete.

## Required clean-consumer environment

The compatibility gate must use:

- PHP 8.3 or newer;
- Composer 2;
- a deterministic Git fixture;
- no sibling `agent-*` checkout on the dependency autoload path, except an
  explicitly declared candidate package under test;
- no nested package `vendor/` winning over the consumer autoloader;
- local-only lifecycle execution with no required external model/API;
- exact resolved package identities recorded in the report.

Optional retrieval capabilities such as sqlite-vec must be reported explicitly
and must not silently become correctness prerequisites.

## Supported versus untested

Use these terms precisely:

- **supported**: package constraints allow the set and the clean-consumer
  release-set gate passed for that concrete set;
- **compatible by contract**: capability/schema ranges indicate compatibility,
  but the exact set has not been exercised;
- **untested**: neither an executed gate nor a declared compatible capability
  range proves the set.

“Composer installed it” does not itself mean “the lifecycle works.” Dependency
solvers remain tragically uninterested in epistemology.

## Historical evidence

The 2026-08-06 0.12-era baseline established the first clean-consumer proof. The
0.17.0 release candidate then proved the external execution protocol and the
expanded fixed-contract refactor/removal consumers. Those runs remain historical
evidence, while the 0.17.1 run above is the current supported projection.

## Update rule

Update this page when:

- `agent-loop` changes a first-party dependency constraint;
- an owning package changes an integration artifact or capability schema;
- the release-set gate establishes replacement pinned evidence worth projecting;
- a previously supported combination fails for a non-fixture reason;
- a new first-party package, such as `agent-loop-runner`, graduates into the
  released clean-consumer set.

Every claimed supported set must remain traceable to machine-readable gate
evidence and exact Git/release identities.
