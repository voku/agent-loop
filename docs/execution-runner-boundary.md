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

The public flow is:

```text
projection(task)
    -> prepareStage(task, stage)
    -> execute candidate work outside agent-loop
    -> submitStageResult(result)
    -> accepted transition | Attention | rejection
```

`StageExecutionBundle` contains the exact Run/Contract/plan/stage binding,
mutation permission, allowed scope, required validation, candidate revision,
Contract and Recall evidence references, previous accepted handoff, legal
outcomes, and a bounded prompt prepared by `agent-loop`.

Recall remains derived context. Map remains navigation. Neither becomes approval
or validation authority merely because it appears in a bundle.

## Stage result and exact-once acceptance

A host returns a `StageResult` with a caller-stable `submission_id`, exact
Run/Contract/plan/stage/attempt binding, candidate revision, outcome and evidence
references.

`agent-loop` validates the result against current owner state before any
transition is accepted. An exit code or model statement is not a passed gate.

The `submission_id` is the idempotency key across the critical restart window:
if `agent-loop` accepted a result but the external runner crashed before updating
its own runtime record, submitting the exact same result again returns the
already-accepted projection. Reusing the id with different content is rejected.

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
its current candidate. Resolving Attention starts a new attempt of that stage.

An external runner may surface or request Attention, but it cannot resolve a
human-owned decision by guessing.

## State ownership

Only `ProjectLayout` spells repository-local state paths. The durable profile
selection lives with Contract-owned state; the resolved execution plan and
accepted execution state live under the current governed Run.

Runner-private process state, logs, worktrees and provider configuration do not
belong in `agent-loop` and must stay in the runner package/project namespace.

## Non-goals

This boundary does not add process launching, provider/model selection, tmux,
parallel mutation, Git push/merge, a dashboard, another Session store, another
Kanban, transcript-derived workflow state, or a general-purpose DAG engine to
`agent-loop`.

The guiding invariant is simple: an external runner may execute work, but only
`agent-loop` can accept that work as a governed transition.
