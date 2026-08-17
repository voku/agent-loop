# Run manifest v1

Status: executable integration contract for [agent-loop#19](https://github.com/voku/agent-loop/issues/19)

## Purpose

A governed task is already represented by several authoritative artifacts:

- an optional kanban card and revision;
- a working-memory session;
- a revisioned Contract and approval;
- repository map and optional search-index state;
- a recall compilation and its output hashes;
- an optional edit bundle and verification result;
- a blind-spot review;
- a Run learning decision and durable outcome lineage.

Before this contract, consumers reconstructed the relationship from directory
names and package-specific conventions. The run manifest makes that relationship
explicit without replacing any owning artifact.

The live projection is the lifecycle view. Its persisted `manifest.json` copy is
only derived drift evidence: it can show that owner state changed since the last
workflow-owned refresh, but it carries no authority of its own.

## Command

```bash
# Read-only live projection. Does not create a manifest.
vendor/bin/agent-loop workflow manifest ABC-123

# Stable machine output.
vendor/bin/agent-loop workflow manifest ABC-123 --format=json

# Explicit repair or migration write of the derived snapshot.
vendor/bin/agent-loop workflow manifest ABC-123 --write
```

Status and diagnostic reads do not quietly modify the repository they are meant
to observe. Workflow-owned transitions that already have a governed Run refresh
the persisted projection after owning artifacts change:

- APPROVE writes the approved projection before recall compilation and the
  compiled projection after success;
- CLOSE writes the final projection after the session closes.

PLAN persists only candidate Contract authority. It deliberately creates neither
Session nor Run projection before approval.

The explicit `--write` path remains the derived-snapshot repair and
legacy-migration command.

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
| `run_id` | Current resolved run identity. |
| `mode` | `governed`, `ephemeral`, `planned`, or `legacy_inferred`. |
| `state` | Derived overall state such as `incomplete`, `blocked`, `ready_to_close`, or `complete`. |
| `references` | Package-owned artifact references and observed states. |
| `disagreements` | Deterministically ordered contradictions or invalid authoritative artifacts. |
| `next_action` | One evidence-backed next or recovery command. |

Every reference identifies its owner and observation mode. File-backed evidence
uses project-relative paths and SHA-256 digests. Mutable state is not copied merely
for convenience when the owning package can expose a stable reference.

## Authority

The manifest records what `agent-loop` observed. It does not make those
observations authoritative.

- `agent-kanban` remains authoritative for card state, revisions, claims and board policy.
- `agent-session` remains authoritative for disposable Session state and validation evidence.
- `agent-map` remains authoritative for repository facts and freshness.
- `agent-recall-compiler` remains authoritative for compilation identity, selected guidance, scope and review artifacts.
- `agent-loop` remains authoritative for Contract, Run, orchestration and verification state.
- `agent-learning` remains authoritative for Run learning decisions, durable findings, proposals and outcome lineage.

A later projection is rebuilt from those owners. Persisted manifest state is not
silently pushed back into them and cannot veto a freshly rebuilt owner-backed
status merely because the derived snapshot is unreadable or from an unsupported
schema.

## Approval recovery

Approval and recall compilation are separate state changes. The Contract may be
successfully approved while compilation fails because an input, provider or
repository snapshot is invalid.

`workflow approve` is therefore resumable:

1. when the current Contract revision is still a candidate, approve it;
2. prepare the governed Session and Run;
3. persist the approved-state projection;
4. compile recall;
5. persist the compiled-state projection;
6. when recall compilation fails, keep the approval and Run and rerun the same
   command after repair;
7. when the exact current revision is already approved, skip duplicate approval
   and resume Run/Recall preparation.

The command never invents a new Contract revision merely to recover from a failed
compiler invocation. Requiring a human to approve identical scope twice would
produce more ceremony, not more governance.

## Legacy behavior

A task without a persisted manifest remains inspectable. The projector derives
only the relationships that current artifacts prove. Depending on available
Contract/Run evidence the mode may be `planned`, `governed`, `ephemeral`, or
`legacy_inferred`. It does not fabricate a Session, approval, map snapshot or
learning link.

The implementation still reads some map/search paths directly and reports them
as compatibility references. These remain seams until the focused-package
inspection contracts tracked by the roadmap own those observations.

## Disagreements and blocking review results

Examples of authoritative artifact disagreement include:

- more than one active Session for one task;
- a Run bound to a superseded Contract revision or digest;
- invalid board, recall, verification or review artifacts;
- task identities that disagree across owner references.

Any such disagreement makes the live projection `blocked`. The command exits `2`
and JSON output contains the exact owner, code and evidence message. Missing
normal progress artifacts remain `incomplete`, not contradictory.

A syntactically valid blind-spot review may itself report `fail`. That is not an
artifact disagreement, but it is a blocking workflow result: the projection is
`blocked` and never recommends or reports a successful close until the review is
rerun after the underlying problem is addressed.

The persisted manifest is different. It is derived evidence, not an owner. A
stale, malformed, or unsupported persisted snapshot remains observable in
`storage`, but it cannot change the lifecycle verdict reconstructed from the
current owners.

## Write and failure semantics

Manifest writes are:

- deterministic through recursively canonicalized object keys;
- written to a unique temporary file;
- published by rename;
- read back strictly when a caller explicitly asks to consume the stored schema.

`RunManifestStore::status()` compares persisted canonical bytes with a fresh
projection and normally reports `missing`, `current`, or `stale`. If the stored
snapshot cannot be decoded or uses an unsupported schema, strict `read()` still
rejects it, while `status()` reports the storage as `stale`, leaves
`stored_sha256` unavailable, and includes the exact failure reason. The fresh
owner-backed lifecycle projection remains usable.

A transition writes domain state first because the focused package is the
authority. If the following projection write fails, the command reports that the
state already changed and names `workflow manifest <task-id> --write` or
`workflow status <task-id> --format=json` as recovery. It never rolls domain
state back from a failed derived-cache write.

## Current boundary

This slice intentionally does not:

- refresh the manifest after every non-workflow package mutation;
- make the persisted manifest itself a close or status gate;
- turn malformed authoritative owner artifacts into best-effort green results;
- parse durable learning history outside the owning `agent-learning` contracts;
- replace package-owned readiness/reference APIs planned in the focused repositories.

The useful red line is deliberate: derived drift evidence may remain visible,
but evidence does not silently become authority.
