# Workflow prompt primitives

`agent-loop` keeps a small set of context-independent L1 control prompts in
`resources/prompts/operating-prompts.json`. They change how an already approved task is
executed; they do not create a second workflow or replace persisted authority.

## Checkpoint autonomy

Select `checkpoint-autonomy` when the work has explicit anchor points such as
one repository, commit, migration stage, or independently verifiable slice.
Pass the anchor explicitly:

```bash
vendor/bin/agent-loop workflow plan TASK-1 \
  --by agent \
  --file src/Example.php \
  --goal 'Complete the approved change.' \
  --validation 'composer ci' \
  --operating-prompt-manifest vendor/voku/agent-loop/resources/prompts/operating-prompts.json \
  --operating-prompt '{"id":"checkpoint-autonomy","arguments":{"anchor_point":"each independently verifiable repository step"}}'
```

At each anchor the agent checks scope, evidence, validation, blockers, and the
current done condition. A successful agent-owned checkpoint is permission to
continue, **not** a fabricated human approval. Real Contract approval, missing
product intent, irreversible action, explicit risk ownership, and required
re-planning remain human/workflow gates.

## Momentum

Select `momentum` when useful fresh context already exists and restarting
repository discovery would merely repeat work:

```bash
--operating-prompt '{"id":"momentum","arguments":{}}'
```

Momentum reuses still-relevant files, symbols, commands, constraints, decisions,
and evidence. It does not turn conversational context into authority. Re-check
anything whose freshness, repository scope, approval, or assumptions may have
changed.

The practical rule is:

> Reuse understanding aggressively; revalidate authority mechanically.

`checkpoint-autonomy` and `momentum` are both L1 controls and may be selected
together from the bundled manifest. They do not require an L2 execution-contract
construction pass by themselves.

## Execute an existing plan after a blind-spot check

When an authoritative plan already exists and the intended behavior is "do it as
planned, but first challenge the assumptions exposed by the current results",
select Recall's bundled L1 `execute-plan-with-blind-spot-check` recipe from the
installed Recall-owned manifest:

```bash
vendor/bin/agent-loop workflow plan TASK-1 \
  --by agent \
  --file src/Example.php \
  --goal 'Execute the already-approved plan.' \
  --acceptance 'The approved plan is complete and its declared verification passes.' \
  --validation 'composer ci' \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"execute-plan-with-blind-spot-check","arguments":{}}'
```

The recipe remains owned by `agent-recall-compiler`; `agent-loop` does not copy
or reinterpret its text. Recall 0.13.11 or newer provides the contract. Loop only
persists the selected recipe with the task and applies the normal Contract,
authority, execution, review, and verification gates around it.

## First-draft falsification

Use the context-light Recall primitive when the current implementation needs an
adversarial pass without compiling another project-specific execution contract:

```bash
vendor/bin/agent-loop review first-draft
```

It treats the implementation as a first draft, asks for falsification rather
than confirmation, and keeps missing material evidence `UNKNOWN` or `BLOCKED`.
`CLEAN` is valid only after concrete attempts to break the implementation find
no evidence-backed defect. The command has no task/run dependency and accepts no
extra arguments.

For a governed task, generate the artifact-backed review prompt instead:

```bash
vendor/bin/agent-loop review code TASK-1
```

That prompt includes the same first-draft falsification lens plus the task's
compiled Recall artifacts and source context. The installed `code-review-*`
engineering lens still owns the concrete technical review; Recall supplies the
evidence boundary and `agent-loop` owns workflow progression.

When the review method itself must be compiled from project evidence, select
Recall's bundled L2 `adversarial-review` recipe during PLAN using the installed
manifest:

```text
vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json
```

Its `minimum_failure_modes` value is a floor on distinct plausible hypotheses or
attack scenarios that must be investigated, **not** a quota of defects that must
be produced. Disproved hypotheses are useful falsification evidence and `CLEAN`
remains legal after the requested probes find no evidence-backed defect.

## Guidance-gap journal

Use this explicitly when you want a spec-driven implementation session to expose
where the coding agent had to interpret or guess because the expected source of
authority was missing, stale, conflicting, misleading, or too vague:

```bash
vendor/bin/agent-loop prompt guidance-gaps
```

This is an opt-in Recall-owned L2 diagnostic, **not** a default workflow stage.
It generates a project-specific implementation prompt that keeps
`implementation-notes.html` as task-local working evidence while the task runs.
The journal separates ordinary design decisions, deviations, tradeoffs, and open
questions from actual guidance gaps in `SPEC`, `DOC`, `SKILL`, `WORKFLOW`,
`TOOL_CONTRACT`, code, or tests. It is not committed unless the approved task or
harness explicitly requires the artifact.

A material ambiguity is not permission to improvise. If resolving it would
change the approved Goal, acceptance criteria, scope/non-goals, a public
contract, security/safety boundaries, or destructive/irreversible behavior, the
prompt requires `HUMAN_DECISION_REQUIRED` / `BLOCKED` with the missing authority.
The technique does not automatically edit docs or skills, manufacture backlog,
or promote journal entries into durable Learning.

The ownership split is intentional:

```text
agent-loop bundled L1 controls     -> Loop execution/orchestration controls
Recall bundled L1/L2 recipes       -> Recall-owned execution/review prompt semantics
Recall prompt guidance-gaps        -> opt-in implementation guidance-gap diagnostic
Recall review first-draft          -> context-light falsification lens
Recall review code <task-id>       -> task-artifact-backed review prompt
agent-skills                        -> tool-neutral engineering principles/skills
```

A skill or machine-readable recipe whose correctness depends on a tool's CLI,
schema, file layout, generated artifacts, or runtime behavior belongs in that
tool's repository. Consumers may install or reference the owned asset, but must
not keep a second canonical copy. That keeps tool code and coding instructions on
the same review, test, and release cadence.

## Post-task reflection

Reflection is optional and read-only. It is not REVIEW, LEARN, or another
lifecycle phase:

```bash
vendor/bin/agent-loop workflow reflect TASK-1 --scope project
vendor/bin/agent-loop workflow reflect TASK-1 --scope task
```

- `project` asks what future investment became visible through doing the work.
- `task` asks what additional depth may have been missed in the completed task.
  A real correctness/acceptance gap is reported as `RETURN_TO_REVIEW`; optional
  extra depth remains optional.

Inside a governed Run, prefer `workflow reflect` because it enforces the
`ready_to_close` / `complete` state boundary. For a manual context-light session,
the same Recall-owned raw helper is also available without Run state:

```bash
vendor/bin/agent-loop prompt future-work --scope project
vendor/bin/agent-loop prompt future-work --scope task
```

Reflection through `workflow reflect` emits the deterministic context-light
prompt supplied by `voku/agent-recall-compiler`; it does not call a model, mutate
Run/Session/Learning state, create an issue, or manufacture follow-up work.
