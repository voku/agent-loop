# Governed execution authority

`agent-loop` remains the authority for accepting execution transitions. External execution hosts may submit typed runtime observations and a typed `StageResult`, but caller strings are never authority by themselves.

Before a stage result is persisted, Loop verifies the exact current task, Run, Contract revision, execution-plan digest, stage and attempt. Read-only stages must preserve the current candidate identity.

A mutating agent stage may change candidate identity only through this sequence:

1. the executor calculates a candidate from the actual governed Run workspace without asking the model for candidate identity;
2. the executor submits a `StageCandidateObservation` bound to the exact current candidate, Task, Run, Contract revision, execution-plan digest, stage and attempt;
3. Loop verifies that the stage is currently authorized to mutate, that the observation derives from the current governed candidate, and that the resulting identity is `git-tree-v1:<exact-base-commit>:<git-tree-object-id>`;
4. Loop verifies that the referenced Git object exists in the governed repository object store and is exactly a tree object;
5. Loop persists a content-addressed owner evidence record for that exact candidate observation;
6. only a `StageResult` whose changed candidate resolves to that current owner evidence may advance execution.

An existing Git tree object is therefore not sufficient authority by itself. A changed candidate without the exact current owner candidate record fails closed. Candidate evidence is attempt-bound and previous-candidate-bound, so stale evidence cannot be replayed after a retry or review loop. Loop does not learn or parse Runner-private worktree paths; workspace observation remains an execution-plane responsibility and candidate registration remains the owner validation boundary.

Artifact references follow the same ownership rule. External executors may submit a narrow `StageArtifactObservation`; Loop first validates the current execution and candidate binding and then creates the owner evidence reference. Arbitrary model-provided artifact references do not become authoritative merely because they are syntactically valid.

Validation truth is stricter: external executors do not receive an API for minting validation evidence. Deterministic verification records validation evidence inside the Loop-owned Run tree and binds it to the exact current Run, Contract revision, plan, stage, attempt and candidate before submitting the deterministic result. Runner-private logs and model prose remain diagnostics, never validation truth.

Pending human-owned Attention is also fail-closed. The runner-facing `ExecutionGateway::resolveAttention()` can apply a resolution only after an owner-side `AttentionResolutionStore` record exists for the exact current Attention and attempt. An Attention identifier alone is insufficient authority to resume execution.
