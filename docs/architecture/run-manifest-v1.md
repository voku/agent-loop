# Run manifest v1

Status: executable integration contract for [agent-loop#19](https://github.com/voku/agent-loop/issues/19)

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

# Explicit repair or migration write.
vendor/bin/agent-loop workflow manifest ABC-123 --write
```

Status and diagnostic reads do not quietly modify the repository they are meant
to observe. Successful workflow-owned transitions refresh the projection after
the owning artifacts change:

- PLAN writes the candidate brief projection;
- APPROVE writes the approved projection before recall compilation and the
  compiled projection after success;
- CLOSE writes the final projection after the session closes.

The explicit `--write` path remains the recovery and legacy-migration command.

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

## Approval recovery

Approval and recall compilation are separate state changes. The brief may be
successfully approved while compilation fails because an input, provider or
repository snapshot is invalid.

`workflow approve` is therefore resumable:

1. when the current brief revision is still a candidate, approve it;
2. persist the approved-state projection;
3. compile recall;
4. persist the compiled-state projection;
5. when step 3 fails, keep the approval and rerun the same command after repair;
6. when the exact current revision is already approved, skip duplicate approval
   and resume compilation.

The command never invents a new brief revision merely to recover from a failed
compiler invocation. Requiring a human to approve identical scope twice would
produce more ceremony, not more governance.

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

## Write and failure semantics

Manifest writes are:

- deterministic through recursively canonicalized object keys;
- written to a unique temporary file;
- published by rename;
- read back only when the supported schema is understood.

`RunManifestStore::status()` compares the persisted canonical bytes with a fresh
projection and reports `missing`, `current`, or `stale`. A persisted manifest
with an unsupported `schema_version` is not downgraded to one of those states:
`read()` refuses it, the command emits the schema error and exits with code `1`.

A transition writes domain state first because the focused package is the
authority. If the following projection write fails, the command reports that the
state already changed and names `workflow manifest <task-id> --write` or
`workflow status <task-id> --format=json` as recovery. It never rolls domain
state back from a failed derived-cache write.

## Current boundary

This slice intentionally does not:

- refresh the manifest after every non-workflow package mutation;
- make the manifest itself a close gate;
- parse durable learning history before `agent-learning` exposes its run-linked inspection contract;
- replace package-owned readiness/reference APIs planned in the focused repositories;
- hide missing, stale or unsupported state behind a best-effort green result.

Those are subsequent slices. Starting with a truthful projection gives them a
contract to integrate with instead of another bundle of coincidentally matching
paths.
