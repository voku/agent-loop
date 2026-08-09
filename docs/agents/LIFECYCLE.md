# The governed lifecycle across the agent-* packages

This document describes the current cross-package workflow and its owning
artifacts. It is deliberately stricter than a conversational agent loop: persisted
state and observed evidence decide which phase is available.

Only `agent-loop` knows the whole lifecycle. Every other package owns one focused
concern and remains usable independently.

| Package | Owns |
| --- | --- |
| `agent-kanban` | durable work-item state |
| `agent-session` | task working memory, revisioned WorkBrief policy, approval, validation evidence |
| `agent-map` | repository navigation facts and derived search index |
| `agent-learning` | findings, proposals, outcome history, canonical application proof |
| `agent-recall-compiler` | deterministic recall, L2 construction policy, project capabilities, verification contracts |
| `agent-skills` | reusable engineering skills and operating-prompt recipes |
| `agent-loop` | orchestration, execution-contract gate, run projection, verification and close gates |

## Canonical workflow

```text
PLAN
  -> APPROVE
  -> CONTEXT
  -> CONTRACT
  -> IMPLEMENT
  -> VALIDATE
  -> REVIEW
  -> LEARN
  -> VERIFY
  -> CLOSE
```

`DISCOVER` is a read-only activity available before and during the governed
workflow. It is not itself a durable transition.

`CONTRACT` is required only when the approved WorkBrief selected L2 operating
prompt policy. Tasks without L2 policy do not gain ceremonial contract work.

| Phase | Owner | Input | Durable output / gate |
| --- | --- | --- | --- |
| PLAN | `agent-loop` + `agent-session` | task id, goal, scope, validation, optional prompt policy | session + candidate WorkBrief revision |
| APPROVE | `agent-session` + `agent-loop` | exact candidate revision | approval bound to that revision |
| CONTEXT | `agent-recall-compiler` + `agent-map` | approved policy + current repository evidence | canonical recall bundle and verification artifacts |
| CONTRACT | `agent-loop` | selected L2 policy + recall evidence | bound `execution-contract.md/json`, or explicit BLOCKED/REJECTED result |
| IMPLEMENT | `agent-loop` | approved policy + current ready contract when required | bounded edit/execution evidence |
| VALIDATE | `agent-session` + edit verifier | WorkBrief validation + L1 Verification | revision-bound validation evidence / bundle verification |
| REVIEW | `agent-loop` + review skills | current diff and task evidence | deterministic review artifacts and reviewed checkpoint |
| LEARN | `agent-session` + `agent-learning` + recall outcomes | observed task/review evidence | explicit learning decision, outcomes, optional findings/proposals |
| VERIFY | `agent-loop` | owning package artifacts | cross-package consistency result |
| CLOSE | `agent-loop` | all required current gates | closed session + final run projection |

## DISCOVER — read-only navigation

For PHP repository navigation:

```bash
vendor/bin/agent-loop map query SomeClass
vendor/bin/agent-loop map related SomeClass
vendor/bin/agent-loop map file src/SomeClass.php
vendor/bin/agent-loop map changed --base=main
```

Discovery does not authorize mutation and does not create task policy. A stale
map reports staleness instead of silently answering from an old snapshot.

## PLAN — approve the policy, not just a prose goal

```bash
vendor/bin/agent-loop workflow plan TASK-123 \
  --by <actor> \
  --file src/Parser.php \
  --file tests/ParserTest.php \
  --goal "Harden parser regression coverage" \
  --scope src/Parser.php \
  --scope tests/ParserTest.php \
  --validation "vendor/bin/phpunit tests/ParserTest.php"
```

The WorkBrief revision owns task policy: goal, scope, non-goals, validation,
tags, behavior anchors, and optional operating-prompt policy.

An L2-selected plan names its manifest and recipe explicitly:

```bash
vendor/bin/agent-loop workflow plan TASK-123 \
  --by <actor> \
  --file src/Parser.php \
  --file tests/ParserTest.php \
  --goal "Harden parser regression coverage" \
  --scope src/Parser.php \
  --scope tests/ParserTest.php \
  --validation "vendor/bin/phpunit tests/ParserTest.php" \
  --operating-prompt-manifest <path-to-operating-prompts.json> \
  --operating-prompt '{"id":"regression-hunt","arguments":{"minimum_findings":1}}'
```

The workflow never guesses a prompt manifest, recipe, threshold, horizon, retry
limit, or validation command.

Changing any approved policy creates a new candidate revision and invalidates the
old approval. A second active session for the same task is refused.

## APPROVE — seal one exact revision

```bash
vendor/bin/agent-loop workflow approve TASK-123 --by <human-actor>
```

Approval is bound to the current WorkBrief revision. After approval, `agent-loop`
compiles recall from that sealed policy.

If compilation fails after the approval record was written, the approval remains
valid. Fix the compiler input and rerun the same command; it resumes compilation
instead of creating a second approval for identical policy.

Approving a newer revision supersedes the previous canonical recall directory so
old context/reviews cannot masquerade as evidence for new policy.

## CONTEXT — deterministic project evidence

Approval normally produces the canonical task recall directory containing:

```text
system.md
validation-plan.md
recall.bundle.json
facts.json
selection-report.json
recall-log.draft.json
```

When an eligible map target resolves, recall also emits the public verification
plan and verifier-owned key.

The compiler can use bounded project evidence such as exact WorkBrief policy,
map-resolved files/symbols/callers/tests, explicitly registered project
documents, constraints, prior guidance/outcomes, Composer scripts/tool metadata,
and CI workflow anchors.

A package being installed proves presence, not an invocation command. Unknown
commands remain `UNKNOWN` until repository evidence resolves them.

Read the current bounded context through:

```bash
vendor/bin/agent-loop workflow context TASK-123
vendor/bin/agent-loop workflow status TASK-123 --format=json
```

## CONTRACT — turn reusable L2 method into project-specific L1

This phase is `not_required` when the approved WorkBrief has no L2 recipe.

When L2 policy is selected, use the compiled recipe instructions and current
project evidence to construct exactly one L1 document with these ordered H2
sections:

```text
## Goal
## Context
## Constraints
## Verification
## Done When
```

`Verification` defines the exact measurement procedure. `Done When` defines the
observable result that permits success. Do not collapse them into one vague
acceptance paragraph.

Persist a ready contract:

```bash
vendor/bin/agent-loop workflow contract TASK-123 \
  --status ready \
  --from <project-specific-l1.md> \
  --by <actor>
```

The contract metadata binds the L1 to:

- task id;
- WorkBrief revision;
- recall bundle digest;
- selected prompt semantics and arguments;
- L1 content digest;
- actor and time.

A changed WorkBrief revision or relevant recall evidence therefore makes the old
contract stale rather than silently reusable.

If the approved policy cannot currently be satisfied, record the stop:

```bash
vendor/bin/agent-loop workflow contract TASK-123 \
  --status blocked \
  --reason "<why the approved policy cannot be satisfied>" \
  --evidence "<observed evidence>" \
  --minimum-change "<smallest explicit policy/context change needed>" \
  --by <actor>
```

Use `rejected` when an attempted implementation violated the approved contract.
BLOCKED/REJECTED preserve evidence and require an explicit restart/change; they
never silently weaken a floor or constraint to reach READY.

## IMPLEMENT — mutation is gated

```bash
vendor/bin/agent-loop edit <Class::method> --task TASK-123 ... -- "instruction"
```

For an active governed L2 task, mutating `command`, `mechanical`, and `auto`
runners are denied unless the current execution contract is READY. Read-only
stdout/dry-run work and investigation remain available while constructing the
contract.

An omitted task id is not a supported escape hatch from an active governed task.

Many tasks never use `agent-loop edit`; direct repository edits can still be part
of a governed workflow, but the task policy/evidence gates remain authoritative.

## VALIDATE — record observed results

Run the commands required by the approved WorkBrief and the current L1
`Verification` section. Validation evidence is bound to the exact WorkBrief
revision; evidence from a superseded revision is stale rather than proof for the
new task policy.

A validation record cannot claim `passed` with a non-zero exit code.

For an `agent-loop edit` bundle with repository-knowledge verification:

```bash
vendor/bin/agent-loop edit verify --bundle=.agent-loop/edit/TASK-123 --run-commands
```

The verifier rejects mismatched plan/key/result bindings and required gates that
were not actually run. It observes the repository using bundle-local post-edit
map evidence instead of mutating the shared map as a side effect of verification.

## REVIEW — inspect the result, not the agent's confidence

```bash
vendor/bin/agent-loop review blindspots TASK-123
```

Review artifacts live under the task recall tree. First-party `code-review-*`
skills are targeted lenses, not a mandatory review swarm: start with the dominant
concern and allow at most one evidence-backed handoff when another concern truly
becomes primary.

A review result is evidence about the current change. Another agent agreeing is
not mechanical validation.

## LEARN — durable knowledge is an explicit decision

Record whether the task produced reusable learning:

```bash
vendor/bin/agent-loop session learning decide TASK-123 \
  --status findings_recorded|no_durable_learning|follow_up_required \
  --by <actor>
```

Finalize recall outcomes through the recall compiler's outcome path. Selected
guidance and selected prompt recipes are exposure, not proof of usefulness.
Useful/harmful classifications require observable evidence.

`agent-learning` owns durable findings/proposals. In the 0.9 release line,
`APPLIED` Memory/Skill guidance must also prove that the reviewed mutation exists
in its real canonical repository target. Proposal state alone is not application
proof.

## VERIFY — cross-package consistency

```bash
vendor/bin/agent-loop verify --task-id=TASK-123
```

This is separate from `edit verify`. It checks the relationships between the
owning package artifacts and reports drift. A check skips itself when its own
inputs are absent instead of requiring fake artifacts merely to turn green.

## CLOSE — no ornamental green state

```bash
vendor/bin/agent-loop workflow close TASK-123 --status done
```

A successful close requires the applicable current gates, including:

1. the current WorkBrief revision is approved;
2. a required L2 execution contract is current and READY;
3. required revision-bound validation evidence exists and passes;
4. existing edit bundles satisfy their verification contract;
5. required review evidence exists;
6. the learning boundary is explicitly closed;
7. selected guidance/recipe outcomes required by the run are recorded;
8. cross-package verification passes.

`--accept-risk "<reason>" --accept-risk-by "<name>"` records an explicit override
for gates that permit risk acceptance. It does **not** bypass the required L2
execution-contract boundary.

A failed/dropped task can still be closed honestly without manufacturing a READY
contract that never existed.

## Run identity and projection

A governed run is the relationship between owning artifacts:

```text
task/card id
+ session id
+ WorkBrief revision and approval
+ map/search snapshot
+ recall compilation
+ execution contract when required
+ edit/validation/verification evidence
+ review
+ learning decision and outcome lineage
```

`agent-loop workflow manifest TASK-123` builds this as a read-only projection.
`--write` persists/repairs the projection atomically under
`.agent-loop/runs/TASK-123/manifest.json`.

The manifest is **not** another workflow authority. It stores references,
digests, and derived state; task/session/recall/learning packages remain owners of
their domain artifacts.

`workflow status` consumes the same projector and reports one next action. A
missing/stale/invalid execution contract routes back to CONTRACT rather than
allowing implementation to continue.

## Remaining boundaries

The current design intentionally keeps these boundaries visible:

- package commands invoked outside the orchestrated workflow can make a stored
  run projection stale; status detects that drift rather than intercepting every
  focused-package mutation;
- deterministic packages do not invoke an LLM and do not guess product intent,
  repository commands, recipe selection, or missing evidence;
- installed first-party skill/catalog content is a separate versioned input from
  the Composer package set; release CI pins coordinated candidates explicitly and
  the installed release-set gate verifies actual published packages;
- durable guidance promotion remains human reviewed; recipe/guidance outcome
  statistics are evidence for review, not authority to rewrite themselves.
