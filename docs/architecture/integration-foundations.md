# Integration foundations for the `agent-*` product

Status: proposed architecture and execution plan  
Parent roadmap: [agent-loop#18](https://github.com/voku/agent-loop/issues/18)

## Purpose

The `agent-*` packages already provide useful individual capabilities. The next
problem is not another retrieval feature. It is making the complete installed
package set behave as one inspectable product without moving every domain rule
into `agent-loop`.

This document defines the integration boundary that must be stable before the
public experience is simplified further.

## Product lifecycle

The product lifecycle is:

```text
DISCOVER
→ PLAN
→ APPROVE
→ PREPARE
→ EXECUTE
→ VERIFY
→ REVIEW
→ LEARN
→ CLOSE
```

A coding agent should enter through `agent-loop` and receive one next action. A
human must still be able to trace every decision to the package and artifact
that owns it.

## Package ownership

| Package | Owns | Must not own |
| --- | --- | --- |
| `agent-kanban` | durable card state, board policy, claims and transitions | sessions, recall, learning or workflow close rules |
| `agent-session` | task-local working state, brief revisions, approvals and validation records | durable project memory or repository analysis |
| `agent-map` | repository facts, map/search freshness and source-backed context | workflow approval, agent-client setup or durable learning |
| `agent-recall-compiler` | deterministic briefing composition, scope derivation and verification contracts | edit execution, grading itself or workflow transitions |
| `agent-loop` | cross-package transitions, run projection, recovery guidance and product CLI | duplicated map, board, session, recall or learning domain logic |
| `agent-learning` | evidence-backed findings, proposals, outcomes, maintenance and forgetting | session working memory or automatic approval |

Only `agent-loop` may know the complete lifecycle. Focused packages expose
versioned inspection and artifact contracts for their own state.

## Current run identity

A governed run is currently reconstructed from:

```text
task/card id
+ session id
+ work-brief revision and approval
+ map/search snapshot
+ recall compilation
+ edit bundle
+ verification result
+ review output
+ learning decision and outcome lineage
```

These identities already exist, but their relationship is implicit in paths and
package-specific files. The first integration deliverable is an explicit,
task-scoped run projection.

## Run manifest principles

The run manifest is a projection, not a new source of truth.

It must:

- store stable references, schema/capability versions and content digests;
- derive status from the owning artifacts;
- be atomically replaced and deterministically serialized;
- expose disagreement instead of repairing it silently;
- support completed, incomplete and legacy pre-manifest runs;
- keep ephemeral experiments outside governed close gates;
- avoid forcing focused packages to know the complete lifecycle.

It must not:

- duplicate mutable board, session, map, recall or learning state;
- become a database or event store;
- treat an inferred search candidate as approved scope;
- invent missing links for legacy runs.

## Required owning-package references

### Board reference

Optional unless the task mode requires a board card:

- board/config schema identity;
- card ID, source path and revision/content digest;
- lane, status and claim state;
- board verification state.

Owner: `agent-kanban`.

### Session reference

- session ID and task ID;
- governed or ephemeral kind;
- work-brief revision, status and digest;
- approval identity and approved revision;
- validation-evidence identities;
- learning-decision identity.

Owner: `agent-session`.

### Repository context reference

- map schema and analysis fingerprint/digest;
- source freshness state;
- optional search-index snapshot and capability state;
- context request mode;
- verified facts versus inferred navigation leads;
- omissions, blind spots and budget accounting.

Owner: `agent-map`.

### Recall reference

- compilation ID and task/brief linkage;
- explicit and derived scope;
- provider/source digests;
- selected guidance and constraint IDs;
- output artifact hashes;
- verification-plan and verifier-key hashes.

Owner: `agent-recall-compiler`.

### Edit, verification and review reference

- edit bundle identity and request digest;
- execution state;
- verification plan/result binding and verdict;
- review artifact identity.

Owner: `agent-loop`.

### Learning reference

- recall-selection event identities;
- explicit per-guidance outcomes;
- session learning decision;
- findings and proposals produced from the run;
- no-durable-learning or follow-up conclusion.

Owner: `agent-learning`, with the task-local decision originating in
`agent-session`.

## Status projection

The joined task status must cover:

1. board/card state, when present;
2. session and current brief revision;
3. approval validity;
4. map/search readiness and snapshot identity;
5. recall compilation and artifact integrity;
6. edit and verification state;
7. review state;
8. learning decision and outcome completeness;
9. runtime/guidance compatibility.

Text output gives one next action. JSON output exposes every subsystem state,
reference and blocking reason without requiring prose parsing.

An overall green state is forbidden when referenced artifacts disagree.

## Compatibility model

Package-local CI remains necessary but is not sufficient.

The supported package set must also pass a clean consumer installation that:

- installs all packages through Composer;
- does not use sibling checkouts or nested package autoloaders;
- exercises CLI/autoload, ephemeral work and one governed task through close;
- covers exact, natural-language, non-English and no-answer discovery cases;
- proves one stale/mismatched-state recovery;
- writes a machine-readable dogfood report.

This release-set smoke is the executable proof of cross-package compatibility.

## Guidance/runtime compatibility

Managed agent guidance must carry:

- stable source asset/rule ID;
- content digest;
- source package;
- guidance schema version;
- required capability IDs and supported capability-schema ranges;
- target/client and managed/unmanaged boundary.

Capability identifiers are preferred over exact patch-version pins. Synchronism
is not inferred from similar-looking prose, because software has already tried
that approach under the name “documentation”.

## Dogfood as architecture discovery

Dogfood starts before implementation is considered complete.

Every integration-affecting release must exercise:

- exact structural discovery;
- natural-language behavior discovery;
- non-English discovery;
- a no-answer case;
- an ephemeral experiment;
- one governed run through close;
- one stale or mismatched artifact recovery.

Every failure becomes one of:

- a regression test in the owning repository;
- an evidence-backed finding/follow-up issue;
- an explicit deferred item with reason.

## Execution order

### Phase 1: executable system contract

1. Inventory all existing IDs, schemas, artifact paths and digests.
2. Define the run-manifest and disagreement semantics.
3. Add owning-package inspection/reference contracts.
4. Complete the joined lifecycle status.
5. Add the installed release-set gate.
6. Add guidance capability metadata and drift detection.
7. Correct known cross-package documentation drift.

Tracking issues:

- [agent-loop#19](https://github.com/voku/agent-loop/issues/19)
- [agent-loop#20](https://github.com/voku/agent-loop/issues/20)
- [agent-loop#21](https://github.com/voku/agent-loop/issues/21)
- [agent-map#2](https://github.com/voku/agent-map/issues/2)
- [agent-recall-compiler#7](https://github.com/voku/agent-recall-compiler/issues/7)
- [agent-session#1](https://github.com/voku/agent-session/issues/1)
- [agent-learning#10](https://github.com/voku/agent-learning/issues/10)
- [agent-kanban#2](https://github.com/voku/agent-kanban/issues/2)

### Phase 2: one complete vertical context workflow

Only after Phase 1 passes the release-set gate:

- expose one task-facing repository-context contract;
- productize measured structural-first/search-first routing;
- integrate readiness and context into workflow preparation;
- bind recall, edit and verification to the run manifest;
- expose `agent-loop context` over the same domain path;
- remove generic downstream orchestration that the vertical slice replaces.

### Phase 3: adoption and measured retrieval improvement

Only after the vertical slice works:

- make bare `agent-loop init` the idempotent paved setup;
- synchronize versioned guidance;
- prepare context automatically during governed work;
- benchmark complete task outcomes;
- evaluate trained embeddings or broader semantic expansion only when measured
  misses justify the added operational cost.

## Blocking design questions

1. Is the run manifest one atomically replaced projection or an index of
   immutable artifact references?
2. Which transition first creates it: planning, approval or preparation?
3. How are ad hoc tasks represented when no board card exists?
4. Which capability/schema IDs are sufficient to detect guidance drift without
   exact package-version pinning?
5. Which current downstream Make/skill behavior is generic enough to move
   upstream, and which must remain project-specific?

These questions are answered through completed/incomplete-run replay and the
installed release-set fixture, not by naming more classes.

## Definition of Done for the integration foundation

The foundation is complete when:

- one run is traceable from task/card to learning outcome through explicit IDs
  and digests;
- every focused package remains authoritative for its domain state;
- joined status cannot report green while artifacts disagree;
- the installed release-set smoke passes in a clean consumer fixture;
- stale guidance/runtime combinations are detectable;
- every remaining downstream integration layer is classified as upstream,
  configuration or intentionally project-specific;
- dogfood failures are preserved as tests, findings or explicit deferrals;
- no semantic candidate masquerades as a repository fact or approved scope.
