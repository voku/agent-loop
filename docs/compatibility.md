# Pre-1.0 compatibility and durable-state contract

This document defines the compatibility boundary that `voku/agent-loop` intends
to carry through the final pre-1.0 release line. It classifies contracts by
semantic ownership and consumer use, not by PHP visibility or filesystem
location.

It is deliberately narrower than "every public class is stable" and stronger
than "0.x means nothing is supported".

## Consumer-facing compatibility rule

A supported consumer may rely on the documented typed application APIs and
machine-readable schemas listed here. Breaking one of those surfaces requires an
explicit release note and, where persisted durable state is affected, an
owner-controlled migration/read/refusal path.

Concrete CLI command classes, filesystem stores, private artifact filenames,
`ProjectLayout` paths below owner mount roots, repository tools, dogfood helpers,
and orchestration internals are not compatibility contracts merely because PHP
can autoload or call them.

Sibling packages must consume typed owner APIs or explicitly documented
machine-readable boundaries. Human CLI prose is never a sibling-package API.

## Stable-candidate application APIs

The following `agent-loop` surfaces are stable candidates for the 1.0 contract:

- `voku\AgentLoop\Execution\ExecutionGateway` and the immutable/versioned
  execution DTOs it intentionally accepts and returns;
- `voku\AgentLoop\Workflow\WorkflowPromptService` and
  `WorkflowPromptEnvelope`;
- `voku\AgentLoop\Workflow\Transparency\WorkflowTransparencyService` and its
  read-only task projections;
- `voku\AgentLoop\Workflow\WorkflowHumanDecisionService` and its typed current
  decision projection;
- documented repository setup/task-health service projections intended for
  embedding hosts;
- documented machine-readable CLI schemas and status/exit semantics where the
  command explicitly declares itself host-facing.

First-party downstream packages keep their own application contracts. In
particular, `voku\agent-loop-runner` exposes `RunnerControlService`; Runner does
not gain authority over Loop merely because its control API is stable.

A public PHP symbol not named by an owner stability document or demonstrated by
a supported first-party consumer remains internal until deliberately classified.

## State classes

| State | Owner | Compatibility class | Upgrade behavior |
| --- | --- | --- | --- |
| Contract revisions, approvals and supersession lineage | `agent-loop` | durable authority | remain readable/migratable or fail with an explicit unsupported-version error |
| Governed Run identity/binding | `agent-loop` | durable authority | preserve exact legal lineage; never replace silently with a fresh Run |
| Execution plan/stage acceptance, Attention and accepted submission identity | `agent-loop` | durable authority / exactly-once state | preserve or explicitly refuse; an upgrade must not cause an accepted stage to execute twice |
| Review/verification identity that participates in currentness decisions | semantic owner | durable evidence/authority binding | old implementation-bound evidence remains stale after upgrade |
| Session working state | `agent-session` | pruneable, resume-sensitive | may be pruned, but a still-bound Run must rehydrate/reopen the exact supported identity rather than inventing a replacement |
| Findings, Proposals, decisions, outcomes and LearningNotes | `agent-learning` | durable owner truth | Learning owns schema evolution, read compatibility, migration and refusal |
| Compiled Recall output, context explanation and `learning_precedent` facts | `agent-recall-compiler` | derived/regenerable | may be rebuilt from current owner truth; regeneration must be visible and must not become authority |
| Map indexes/search databases | `agent-map` | derived/regenerable | rebuild instead of migrating unless Map explicitly documents otherwise |
| Runner runtime journal needed for an in-flight attempt | `agent-loop-runner` | runner-private recovery state | preserve enough reconciliation identity to prevent duplicate execution/acceptance |
| Host/UI projections | projection owner | derived | regenerate from current owner state |

### LearningNote boundary

A `LearningNote` is durable `agent-learning` state. It has exact Finding lineage,
a versioned owner schema and owner-controlled currentness/retirement semantics.
It is not a Recall cache and Loop must never salvage it by reconstructing
`notes/**` paths.

A Recall `learning_precedent` is the opposite class: it is a derived,
non-authoritative compilation result whose provenance remains bound to the exact
Learning projection used by that compile.

## Unsupported versions

Unsupported durable state must fail closed and be machine-distinguishable from
"state absent". The semantic owner chooses one documented behavior:

1. read compatibility;
2. explicit migration;
3. explicit hard refusal.

Silent reinterpretation, filename-based salvage in Loop, or dropping durable
state and creating replacement identities are not supported migration
strategies.

Derived state may be regenerated only when its owner classifies it as
regenerable. Rebuilding a Map or Recall artifact must never be presented as
preserving a durable authority record.

## Release and consumer proof

The compatibility contract is not proven by repository-local unit tests alone.
The final pre-1.0 line requires both:

1. a clean installed consumer that needs only documented host-facing APIs; and
2. the release-to-release continuation proof tracked by issue #328.

The upgrade proof must create meaningful persisted state with the previous
supported release, upgrade without sibling/path repositories or manual artifact
repair, and then either resume the same authoritative identities or observe an
explicit owner-controlled refusal.

At minimum that proof covers:

- a normal interrupted Run;
- a superseded Contract;
- a pruned Session;
- stale review/verification evidence;
- durable Learning history, including LearningNote state once it is part of the
  released supported set;
- Runner reconciliation once a tagged Runner participates in the supported
  release set.

## Internal surfaces remain removable before 1.0

Pre-1.0 cleanup may still delete or reshape undocumented internals. It must not
hide first-party coupling behind that freedom. If Runner, UI or another
first-party consumer needs an internal file/class, either move that consumer to
an existing owner API or deliberately publish the smallest required typed
boundary before the 1.0 freeze.

Do not add compatibility forwarding classes solely to preserve already-internal
0.x implementation details.
