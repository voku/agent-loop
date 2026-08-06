# Run manifest v1

Status: first executable slice for [agent-loop#19](https://github.com/voku/agent-loop/issues/19)

## Purpose

A governed task is already represented by several authoritative artifacts:

- an optional kanban card and revision;
- a working-memory session;
- a revisioned work brief and approval;
- repository map and optional search-index state;
- a recall compilation and its output hashes;
- an optional edit bundle and verification result;
- a blind-spot review;
- a task-local learning decision and durable outcome lineage.

Before this contract, consumers reconstructed the relationship from directory
names and package-specific conventions. The run manifest makes that relationship
explicit without replacing any owning artifact.

## Command

```bash
# Read-only projection. Does not create a manifest.
vendor/bin/agent-loop workflow manifest ABC-123

# Stable machine output.
vendor/bin/agent-loop workflow manifest ABC-123 --format=json

# Atomically persist the current projection.
vendor/bin/agent-loop workflow manifest ABC-123 --write
```

Persistence is explicit. Status and diagnostic reads do not quietly modify the
repository they are meant to observe.

## Location

```text
.agent-loop/runs/<task-id>/manifest.json
```

The file is an atomically replaced projection. It is neither an append-only event
stream nor another source of mutable domain state.

## Schema

Top-level fields:

| Field | Meaning |
| --- | --- |
| `schema_version` | Run-manifest schema. Version 1 is `1.0`. |
| `task_id` | Product-level task identity supplied to the workflow. |
| `run_id` | Current resolved run identity. Usually the owning session ID. |
| `mode` | `governed`, `ephemeral`, or `legacy_inferred`. |
| `state` | Derived overall state such as `incomplete`, `blocked`, `ready_to_close`, or `complete`. |
| `references` | Package-owned artifact references and observed states. |
| `disagreements` | Deterministically ordered contradictions or invalid artifacts. |
| `next_action` | One evidence-backed next or recovery command. |

Every reference identifies its owner and observation mode. File-backed evidence
uses project-relative paths and SHA-256 digests. Mutable state is not copied merely
for convenience when the owning package can expose a stable reference.

## Authority

The manifest records what `agent-loop` observed. It does not make those
observations authoritative.

- `agent-kanban` remains authoritative for card state, revisions, claims and board policy.
- `agent-session` remains authoritative for sessions, brief revisions, approvals, validation and the task-local learning decision.
- `agent-map` remains authoritative for repository facts and freshness.
- `agent-recall-compiler` remains authoritative for compilation identity, selected guidance, scope and verification artifacts.
- `agent-loop` remains authoritative for orchestration-owned edit, verification and review artifacts.
- `agent-learning` remains authoritative for durable findings, proposals and outcome lineage.

A later projection is rebuilt from those owners. Persisted manifest state is not
silently pushed back into them.

## Legacy behavior

A task without a manifest remains inspectable. The projector derives only the
relationships that current artifacts prove and labels the mode
`legacy_inferred`. It does not fabricate a session, approval, map snapshot or
learning link.

The first implementation still reads some map/search paths directly and reports
them as legacy path references. These are deliberate compatibility seams until
the focused-package inspection contracts tracked by the roadmap are available.

## Disagreements and blocking review results

Examples of artifact disagreement include:

- more than one active session for one task;
- approval bound to a superseded brief revision;
- invalid board, recall, verification or review artifacts;
- task identities that disagree across references.

Any disagreement makes the overall projection `blocked`. The command exits `2`
and JSON output contains the exact owner, code and evidence message. Missing
normal progress artifacts remain `incomplete`, not contradictory.

A syntactically valid blind-spot review may itself report `fail`. That is not an
artifact disagreement, but it is a blocking workflow result: the projection is
`blocked` and never recommends or reports a successful close until the review is
rerun after the underlying problem is addressed.

## Write semantics

Manifest writes are:

- deterministic through recursively canonicalized object keys;
- written to a unique temporary file;
- published by rename;
- read back only when the supported schema is understood.

`RunManifestStore::status()` compares the persisted canonical bytes with a fresh
projection and reports `missing`, `current`, or `stale`. A persisted manifest
with an unsupported `schema_version` is not downgraded to one of those states:
`read()` refuses it, the command emits the schema error and exits with code `1`.

## Current boundary

This slice intentionally does not:

- update the manifest automatically during every workflow transition;
- make the manifest a close gate;
- parse durable learning history before `agent-learning` exposes its run-linked inspection contract;
- replace package-owned readiness/reference APIs planned in the focused repositories;
- hide missing, stale or unsupported state behind a best-effort green result.

Those are subsequent slices. Starting with a truthful projection gives them a
contract to integrate with instead of another bundle of coincidentally matching
paths.
