# Governed execution authority

`agent-loop` remains the authority for accepting execution transitions. External execution hosts may submit a typed `StageResult`, but the submitted strings are not authority by themselves.

Before a stage result is persisted, Loop verifies the exact current task, Run, Contract revision, execution-plan digest, stage and attempt. Read-only stages must preserve the current candidate identity. A mutating stage may publish a `git-worktree-v1:<base-commit>:sha256:<digest>` candidate only when it is anchored to the exact governed base commit.

Artifact and validation references are accepted only when they resolve to Loop-owned execution-evidence records bound to the same Run, Contract revision, plan, stage, attempt and candidate. Missing, stale or mismatched evidence fails closed. Runner-private logs remain diagnostic and are not made authoritative merely by naming them in a `StageResult`.

Deterministic verification records its validation evidence inside the Loop-owned Run tree before submitting the result. The external runner does not receive a generic API for minting authoritative evidence records.

Pending human-owned Attention is also fail-closed. The runner-facing `ExecutionGateway::resolveAttention()` can observe and apply a resolution only after an owner-side `AttentionResolutionStore` record exists for the exact current Attention and attempt. An Attention identifier alone is insufficient authority to resume execution.
