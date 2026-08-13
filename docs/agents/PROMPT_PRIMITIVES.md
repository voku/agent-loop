# Workflow prompt primitives

`agent-loop` keeps a small set of context-independent L1 control prompts in
`resources/operating-prompts.json`. They change how an already approved task is
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
  --operating-prompt-manifest vendor/voku/agent-loop/resources/operating-prompts.json \
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

Reflection is available only when the governed run is `ready_to_close` or
`complete`. It emits the deterministic context-light prompt supplied by
`voku/agent-recall-compiler`; it does not call a model, mutate Run/Session/
Learning state, create an issue, or manufacture follow-up work.
