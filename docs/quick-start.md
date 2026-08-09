# Your first governed task

This is the shortest supported path from an installed package to one audited
task. It intentionally starts **without** an L2 operating-prompt recipe so the
basic lifecycle stays small. The optional CONTRACT section below shows what
changes when a task does select L2 policy.

## 1. Bootstrap the repository

Run this from the root of an existing Composer project:

```bash
vendor/bin/agent-loop init scaffold
```

The command creates the minimum workflow inputs when they do not already exist,
including `.agent-loop/init.json`, the local board/example task, `session_plan/`,
and the learning-root skeleton. Existing files are left untouched. Use
`--dry-run` to inspect the same plan without writing anything.

`DEMO-1` is an example task, not a magic workflow mode:

```bash
vendor/bin/agent-loop board card show DEMO-1
```

For a new real task, create/use a matching task/card identifier before relying on
the cross-package verifier.

## 2. PLAN

Choose a file you are actually going to change. `composer.json` makes this
example portable across Composer projects; a real source file is usually more
useful.

```bash
vendor/bin/agent-loop workflow plan DEMO-1 \
  --by "$(git config user.name)" \
  --file composer.json \
  --goal "Add a small validated change." \
  --behavior-anchor "composer configuration -> Composer validation result" \
  --validation "composer test"
```

This creates a candidate WorkBrief revision. It does **not** authorize work yet.
The brief owns the task goal, scope, non-goals, validation, tags, behavior
anchors, and optional operating-prompt policy.

## 3. APPROVE

```bash
vendor/bin/agent-loop workflow approve DEMO-1 \
  --by "$(git config user.name)"
```

Approval seals the exact WorkBrief revision and compiles recall from that state.
The scaffolded `infra/doc/agent-learning` root is auto-detected; use
`--learning-root <path>` only when the project keeps it elsewhere.

## 4. CONTEXT

```bash
vendor/bin/agent-loop workflow context DEMO-1
vendor/bin/agent-loop workflow status DEMO-1 --format=json
```

`workflow context` is read-only. It gives the agent a bounded view of approved
policy and current evidence; it does not regenerate recall or invent missing
commands.

For behavioral changes, use behavior anchors to name the request, runtime,
consumer, data, or integration boundary that owns the behavior. Documentation-
only work can deliberately have none.

## Optional: CONTRACT for an L2-selected task

The default example above selected no L2 recipe, so status should report the
execution contract as not required and IMPLEMENT can follow CONTEXT.

When a task **does** select L2 policy during PLAN, provide the exact manifest
path and explicit recipe arguments:

```bash
vendor/bin/agent-loop workflow plan DEMO-1 \
  --by "$(git config user.name)" \
  --file composer.json \
  --goal "Find a concrete regression before declaring the tests strong." \
  --validation "composer test" \
  --operating-prompt-manifest <path-to-operating-prompts.json> \
  --operating-prompt '{"id":"regression-hunt","arguments":{"minimum_findings":1}}'
```

Approve that new WorkBrief revision, inspect the compiled context, then construct
one project-specific L1 document with exactly:

```text
## Goal
## Context
## Constraints
## Verification
## Done When
```

Persist it before mutating work:

```bash
vendor/bin/agent-loop workflow contract DEMO-1 \
  --status ready \
  --from <project-specific-l1.md> \
  --by "$(git config user.name)"
```

A missing, stale, invalid, blocked, or rejected contract keeps governed mutation
out of IMPLEMENT. If the approved recipe cannot be satisfied, record BLOCKED
with concrete evidence instead of weakening the approved floor.

## 5. IMPLEMENT and VALIDATE

Make the smallest change allowed by the approved policy. Then run the actual
validation command and record its observed result. The first WorkBrief revision
is `1`; after any re-plan, use the current revision shown by workflow status.

```bash
composer test

vendor/bin/agent-loop session validation record DEMO-1 \
  --brief-revision 1 \
  --command "composer test" \
  --status passed \
  --exit-code 0 \
  --duration-ms 0 \
  --by "$(git config user.name)"
```

Use the real exit code and duration when available. A non-zero exit code cannot
be recorded as passing evidence.

If you used `agent-loop edit` and its bundle carries a verification plan, verify
that concrete bundle separately:

```bash
vendor/bin/agent-loop edit verify \
  --bundle=.agent-loop/edit/DEMO-1 \
  --run-commands
```

## 6. REVIEW

Generate and inspect the blind-spot review:

```bash
vendor/bin/agent-loop review blindspots DEMO-1
```

When the workflow requires the reviewed checkpoint, record it only **after**
actually reading the report, then rerun the report to verify that evidence is
present:

```bash
vendor/bin/agent-loop session checkpoint DEMO-1 \
  --title "Review" \
  --body "review blindspots DEMO-1 was checked."

vendor/bin/agent-loop review blindspots DEMO-1
```

A second agent agreeing with the implementation is not a replacement for tests,
static analysis, runtime probes, or other repository-native evidence.

## 7. LEARN

Close the learning boundary explicitly:

```bash
vendor/bin/agent-loop session learning decide DEMO-1 \
  --status no_durable_learning \
  --by "$(git config user.name)" \
  --reason "No reusable finding emerged."
```

Do not use `no_durable_learning` to hide a reusable lesson. Record real findings
through the learning workflow and let reviewed promotion decide what becomes
durable guidance.

If the task selected guidance or operating-prompt recipes, finalize the outcome
rows required by the recall draft before CLOSE. Selection is exposure, not proof
that the guidance or recipe was helpful.

## 8. VERIFY

```bash
vendor/bin/agent-loop verify --task-id=DEMO-1
```

This checks cross-package consistency and drift. It is different from per-edit
bundle verification.

## 9. CLOSE

```bash
vendor/bin/agent-loop workflow close DEMO-1 --status done
```

CLOSE enforces the applicable current gates. For an L2-selected task, the
execution contract must still be current and READY; accepted risk does not bypass
that boundary.

When close fails, do not create fake evidence to satisfy the gate. Use:

```bash
vendor/bin/agent-loop workflow status DEMO-1
```

and satisfy the exact missing/stale/blocked artifact it reports.
