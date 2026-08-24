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

The host-neutral flow remains:

```text
projection(task)
    -> prepareStage(task, stage)
    -> execute candidate work outside agent-loop
    -> submitStageResult(result)
    -> accepted transition | Attention | rejection
```

When the final stage prompt genuinely benefits from current host/runtime facts,
the runner may use a bounded two-step flow instead:

```text
projection(task)
    -> prepareStage(task, stage)              # governed host-neutral bundle
    -> observe the selected current host
    -> prepareStageForEnvironment(task, stage, observation)
    -> execute the finalized current prompt
    -> submitStageResult(result)
```

`StageExecutionBundle` contains the exact Run/Contract/plan/stage binding,
mutation permission, allowed scope, required validation, candidate revision,
Contract and Recall evidence references, previous accepted handoff, legal
outcomes, and a bounded prompt prepared by `agent-loop`.

Recall remains derived context. Map remains navigation. Neither becomes approval
or validation authority merely because it appears in a bundle.

## Bounded execution-environment observation

`ExecutionEnvironmentObservation` is optional runtime evidence, not a second
workflow contract. It is bound to the exact task, Run, Contract revision,
execution-plan digest, stage, attempt, and candidate revision. A stale binding is
rejected before a prompt is finalized.

The observation shape is deliberately narrow:

- one bounded host id;
- at most 16 typed tool availability records;
- an optional single-line bounded tool version only when that tool is available;
- optional network availability;
- optional remote-write availability.

It has no field for arbitrary environment variables, binary paths, credentials,
tokens, free-form host metadata, task scope, acceptance policy, or workflow
decisions. The observation is not persisted as durable task memory by this API.
Its digest may be exposed on the finalized `StageExecutionBundle` for lineage.

`prepareStage()` remains the normal host-neutral path and adds no environment
ceremony. `prepareStageForEnvironment()` is only for agent stages where a caller
has a current bounded observation. Deterministic stages remain host-independent
and reject an environment observation.

Current environment facts may inform execution wording, but they cannot widen
scope, approve a Contract, resolve Attention, change accepted outcomes, select a
workflow stage, or bypass validation. Missing facts remain unknown. The caller
must not invent capabilities or convert host configuration into task policy.

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
Environment observations are transient dispatch evidence and do not move those
runner-owned details into Loop persistence.

## Non-goals

This boundary does not add process launching, provider/model selection, tmux,
parallel mutation, Git push/merge, a dashboard, another Session store, another
Kanban, transcript-derived workflow state, a general-purpose DAG engine, or an
environment snapshot store to `agent-loop`.

The guiding invariant is simple: an external runner may execute work and report
bounded current host facts, but only `agent-loop` can compile those facts into
its governed stage prompt and accept resulting work as a governed transition.
