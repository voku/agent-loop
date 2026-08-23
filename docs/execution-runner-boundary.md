# Governed external execution boundary

`agent-loop` owns workflow truth. An optional external process host such as
`voku/agent-loop-runner` may execute already-authorized stages, but it never
becomes an authority for approval, validation, review, Learning, or close.

The dependency direction is deliberately one-way:

```text
agent-loop-runner
      |
      v
  agent-loop
```

`agent-loop` does not require or invoke the runner. Existing installations keep
`manual` execution as the default profile.

## Execution profiles

An approved Contract may select `manual`, `surgical`, `standard`, or `hardened`
before its governed Run exists. No selection means `manual`.

When the Run is prepared, `agent-loop` resolves the selected first-party profile
into a versioned `ExecutionPlan` bound to the exact Run id, Contract revision and
Contract source digest. The resolved plan is persisted with the Run and is never
regenerated from a later package version. A stale profile selection or plan
binding fails closed.

The first-party profiles are intentionally small:

```text
manual
  no external stages

surgical
  investigate -> build -> review -> verify

standard
  investigate -> build -> correctness-review -> blindspot-review -> verify

hardened
  investigate -> build -> correctness-review -> architecture-review
              -> hardening -> independent-verification -> blindspot-review
              -> verify
```

Review outcomes may route back to an explicit mutation stage. Reviewer roles are
read-only by default. The deterministic final `verify` stage remains an
`agent-loop` operation rather than an LLM claim.

## Typed consumer API

External process hosts consume `voku\AgentLoop\Execution\ExecutionGateway`.
They do not read `.agent-loop/**` directly and do not parse human CLI output.

The public agent-stage flow is:

```text
projection(task)
    -> prepareStage(task, stage)
    -> create/acquire isolated workspace
    -> bindStageWorkspace(task, stage, attempt, workspace)
    -> execute candidate work outside agent-loop
    -> construct StageResult candidate
    -> observeStageResult(result, workspace)
    -> submitStageResult(result, workspace)
    -> accepted transition | Attention | rejection
```

`bindStageWorkspace()` is an owner-side precondition check. `agent-loop` verifies
that the supplied path is a worktree of the expected repository, remains at the
exact governed base commit, and contains the candidate revision projected for
the current Run/Contract/plan/stage/attempt. The durable binding stores only an
opaque workspace identity, not a Runner-private path convention.

`observeStageResult()` does not trust the candidate/evidence strings supplied by
the external process. `agent-loop` independently recalculates the candidate with
the same versioned Git-worktree digest contract, requires read-only stages to
preserve the bound candidate, resolves referenced artifacts inside the bound
workspace, and executes referenced Contract validation obligations itself. A
mutating `COMPLETED` stage must prove every validation obligation in the current
Contract.

`submitStageResult()` rechecks the candidate and artifact evidence under the
acceptance lock before advancing state. Missing, mismatched, superseded, or stale
owner evidence fails closed. A caller-supplied reference is never authoritative
merely because it is a syntactically valid string.

`StageExecutionBundle` contains the exact Run/Contract/plan/stage binding,
mutation permission, allowed scope, required validation, candidate revision,
Contract and Recall evidence references, previous accepted handoff, legal
outcomes, and a bounded prompt prepared by `agent-loop`.

Recall remains derived context. Map remains navigation. Neither becomes approval
or validation authority merely because it appears in a bundle.

## Stage result and exact-once acceptance

A host returns a `StageResult` with a caller-stable `submission_id`, exact
Run/Contract/plan/stage/attempt binding, candidate revision, outcome and evidence
references. Those fields are claims until owner evidence has been observed and
persisted for that exact submission.

`ExecutionStateStore::accept()` refuses a new transition without matching
owner-side `StageResultEvidence`. Agent stages additionally require the currently
bound workspace so freshness can be rechecked at acceptance. Deterministic stages
record their own evidence internally before acceptance and cannot receive that
authority from an external runner.

The `submission_id` remains the idempotency key across the critical restart
window: if `agent-loop` accepted a result but the external runner crashed before
updating its own runtime record, submitting the exact same accepted result again
returns the already-accepted projection. Reusing the id with different content
is rejected.

## Handoffs

An accepted non-terminal transition produces a versioned `HandoffEnvelope`
bound to task, Run, Contract revision, execution-plan digest, source stage,
target stage, candidate revision, artifact references and validation references.

This is distinct from `workflow handoff`:

- `workflow handoff` compiles bounded candidate context for a later human/agent
  session and requires re-grounding;
- `HandoffEnvelope` records an accepted governed stage-to-stage transition.

## Attention

`BLOCKED`, `NEEDS_CLARIFICATION`, or `FAILED` stage results do not advance the
plan. They create typed pending Attention and leave the current stage bound to
its owner-verified candidate. Resolving Attention starts a new attempt of that
stage.

The external `ExecutionGateway` cannot resolve human-owned Attention. The owner
path is:

```text
agent-loop workflow attention TASK --resolve ATTENTION_ID --by ACTOR
```

That command persists a Run/Contract/plan-bound `AttentionResolution` record
before clearing the pending Attention. A restart may replay the same exact
actor-bound resolution record, but an external runner cannot manufacture one by
calling the execution gateway.

## State ownership

Only `ProjectLayout` spells repository-local state paths. The durable profile
selection lives with Contract-owned state; the resolved execution plan, workspace
bindings, owner evidence, Attention resolutions, and accepted execution state
live under the current governed Run.

Runner-private process state, logs, worktrees and provider configuration do not
belong in `agent-loop` and must stay in the runner package/project namespace.
The owner receives a workspace path only as an invocation input and persists an
opaque identity derived from the verified Git worktree.

## Non-goals

This boundary does not add process launching, provider/model selection, tmux,
parallel mutation, Git push/merge, a dashboard, another Session store, another
Kanban, transcript-derived workflow state, or a general-purpose DAG engine to
`agent-loop`.

The guiding invariant is simple: an external runner may execute work, but only
`agent-loop` can accept that work as a governed transition.
