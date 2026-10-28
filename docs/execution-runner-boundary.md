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
read-only by default. Independent review stages additionally declare
`context_policy=fresh_required`: the host must execute them under an observable
context identity that differs from their direct agent predecessor(s). This keeps
"read-only" and "independent context" as separate invariants. The deterministic
final `verify` stage remains an `agent-loop` operation rather than an LLM
claim. It runs in the workspace that holds the governed candidate (the execution
consumer passes its Run worktree; the default is the project root). Loop first
recomputes that workspace's `git-tree-v1` identity and refuses it with
`CANDIDATE_MISMATCH` unless it is the accepted candidate (whole-tree identity in
a Run worktree; scoped to the approved Contract in the owner checkout, see
[Verify in a shared checkout](#verify-in-a-shared-checkout)). It then runs the
project/Run consistency check, which emits one owner-bound `consistency` receipt
in `consistency_references` and is never `VALIDATION` evidence, and executes the
approved Contract's validation commands in that workspace (or, in a shared
checkout, on an export of it; see below), recording one
`VALIDATION` evidence per executed command. PASS requires the consistency
receipt, every declared command to exit 0, and the candidate to be unchanged
afterwards (compared as described below in a shared checkout); a failing command opens Attention. A deterministic PASS without the
consistency receipt is rejected. `finish` remains the task-level validation gate
for the work the host adopts into the checkout.

### Verify in a shared checkout

When `verify` runs in the owner checkout (the project root, not a Run
worktree), the governed candidate is the base commit overlaid with only the
approved Contract scope. Scope entries are literal paths or directory prefixes:
no globs, and `.` means the whole tree. The scoped tree must match the accepted
candidate exactly, so uncommitted work of other tasks outside the scope no longer
causes `CANDIDATE_MISMATCH`.

- If the workspace differs from the candidate outside the scope, the declared
  validation commands run against an export of the accepted candidate tree (not a
  Git worktree). The export holds the files of the accepted candidate tree only:
  no ignored or generated files such as `vendor/`, `node_modules` or `.env`, and
  no `.git`, so commands that call `git` fail there. Nothing is symlinked or
  reused from the checkout. A Contract whose validation needs dependencies must
  declare their preparation as validation commands (for example
  `composer install` before `composer ci`); a bare `composer ci` fails there. A
  declared command failing is a recorded `FAILED` stage, not a retryable
  mismatch. A failure to create the export is an error and records no stage
  result; it is an environment problem to fix before running `verify` again.
  The project/Run consistency check always runs once against the project root,
  never inside the export. Its `consistency` receipt is still required for PASS,
  but it describes the checkout, not the exported tree; only the declared
  validation commands describe the exported candidate.
- Otherwise validation runs in place and the whole tree must be unchanged
  afterwards. In both modes the accepted candidate is proven again after
  validation.
- Edits outside the scope, whether the builder's own or another task's, are not
  part of the accepted candidate, and a PASS does not cover them. That is the
  point of the scope boundary, so review the scope when approving the Contract.
- Ignored foreign files are invisible to this detection. A PASS is a statement
  about the accepted candidate, not about the shared checkout with other tasks'
  files in it: an export PASS proves the candidate in a clean tree, and an
  ignored foreign file can still influence an in-place run. The
  task-level `finish` gate is where the adopted work is checked in the checkout.
- Recovery, explicit candidate worktrees and Runner flows keep the exact
  whole-tree identity.

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

## Stage result, context lineage, and exact-once acceptance

A host returns a `StageResult` with a caller-stable `submission_id`, exact
Run/Contract/plan/stage/attempt binding, candidate revision, outcome and evidence
references. Agent stages participating in an independent-review boundary also
carry one bounded opaque `context_id`. It identifies the host execution context;
it is not a transcript, model identity, credential, or authority claim.

For `fresh_required` stages, agent-loop fails closed unless the current
`context_id` differs from each direct agent predecessor. A predecessor whose
result is needed to prove that comparison must therefore also report its context
id. The host/runner still owns *how* it creates a new session/subagent/process;
agent-loop owns only the stage invariant and its lineage check. Projected
subagent files are not evidence that this runtime separation happened.

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
