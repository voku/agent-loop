---
name: agent-loop-workflow
description: Use the governed agent-loop state machine for planning, approval, bounded context, implementation evidence, review, learning decisions, verification, and safe closure.
---

# Agent Loop Workflow

Use this skill when operating or changing a task under the governed `agent-loop`
workflow. Apply `agent-loop-discipline` throughout implementation and
`agent-loop-simplify-review` as a separate complexity-only pass when the diff may
contain speculative code.

Persisted workflow artifacts are the execution state. Conversation prose is not.
Start by resolving the existing task/session state instead of inventing another
plan beside it.

## Deterministic Phase Model

```text
PLAN -> APPROVE -> CONTEXT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE
```

| Phase | Required evidence before leaving | Route |
|---|---|---|
| `PLAN` | candidate brief with goal, scope, non-goals, behavior anchors, validation | `agent-loop-task-start` |
| `APPROVE` | named human approval of that exact brief revision | human gate |
| `CONTEXT` | bounded approved L2 context plus verified real-source locations | `agent-loop-l2-context`, then `agent-loop-investigate` when location is unknown |
| `IMPLEMENT` | smallest correct diff inside approved scope | `agent-loop-surgical-edit` for verified 1-2 file scope; otherwise main workflow |
| `VALIDATE` | exact required commands recorded against current brief revision | `agent-loop-task-progress` |
| `REVIEW` | blind-spot artifact plus complete raw-diff correctness review; complexity pass when relevant | `agent-loop-code-review`, `agent-loop-simplify-review` |
| `LEARN` | truthful recall outcomes plus explicit learning decision | `agent-loop-learning-boundary` |
| `VERIFY` | cross-package verification and workflow report pass | `agent-loop-review-close` |
| `CLOSE` | close gate accepts current evidence | `agent-loop-review-close` |

Transitions are evidence-driven, not optimistic:

- scope exceeds the approved brief -> `PLAN` and obtain approval again;
- validation fails because implementation is wrong -> `IMPLEMENT`;
- validation exposes missing scope or product intent -> `PLAN`;
- correctness review finds a defect -> `IMPLEMENT`, then repeat validation/review;
- a reusable finding exists -> remain in `LEARN` until it is recorded truthfully;
- a proposal is never self-approved by an agent;
- failed verification -> repair the missing gate, do not jump to `CLOSE`;
- accepted risk is an explicit named human override, never an implicit transition.

## Fast Path

1. Inspect prior history only when earlier decisions materially affect the task.
2. Resolve existing task/session state and reuse the stable task id.
3. Plan explicit goal, scope, non-goals, behavior anchors, and exact validation.
4. Approve that revision through a named human actor.
5. Use `agent-map` to select bounded source reads.
6. Implement the smallest correct change in the owning package.
7. Record validation against the current brief revision.
8. Review blind spots, the complete raw diff, and complexity separately when needed.
9. Record recall outcomes and an explicit learning decision.
10. Run cross-package verification, inspect the workflow report, and close only when every required gate passes.

Do not ask the human to run reads, edits, tests, or reports that the available
tools can run. Human interaction is reserved for approval, genuine ambiguity,
irreversible actions, and explicit risk ownership.

## Canonical Flow

```bash
vendor/bin/agent-loop workflow plan <task-id> \
  --by <actor> \
  --learning-root <path> \
  --file <path> \
  --goal "Implement the approved task." \
  --behavior-anchor "request -> service -> persisted state" \
  --validation "vendor/bin/phpunit tests/FocusedTest.php"

vendor/bin/agent-loop workflow approve <task-id> --by <human-actor>

vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000
```

Use lower-level commands only for direct control:

- `session` for decisions and checkpoints;
- `session validation record` for revision-bound evidence;
- `session learning decide` for the required learning outcome;
- `recall compile` for briefing diagnostics;
- `review blindspots` and `review code` for deterministic review artifacts;
- `verify` for cross-package consistency;
- `learn` and `memory review` for reviewed durable knowledge;
- `init install-assets` for package-owned agent behavior;
- `init sync-*` for host-owned canonical assets.

## Workflow Boundary

- Planning records a candidate brief; approval seals its exact revision.
- Re-planning invalidates approval and validation evidence for the old revision.
- `workflow context`, `status`, and `report` are read-only.
- `workflow context` never rebuilds recall or a map.
- `workflow report` does not run Git; pass observed changed paths explicitly.
- `workflow close --status done` requires the current approval, exact validation
  evidence, recall outcomes, blind-spot review, learning decision, and passing
  verification.
- Recall files are not silently injected into an agent.
- Findings are not durable memory until reviewed and promoted.
- One task has one active session; resume it rather than creating parallel state.

The L2 briefing labels claims `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, or
`CONTRADICTED`. Model explanations and review comments remain hypotheses until
current repository evidence, focused history, or a safe runtime observation
supports them.

## Progress Receipt

After a meaningful phase transition, result, or blocker, report the compact
contract from `agent-loop-discipline`:

```text
RESULT: <verified result>
STATE: <phase> <task-id> <brief revision when known>
NEXT: <one agent-owned action or exact human gate>
```

Do not narrate every tool call. Do not repeat unchanged state. Derive `STATE`
from persisted artifacts or observed command results, never from intention.

## Navigation And Evidence

Generated `.agent-map` files are disposable navigation state. Query them through
the CLI, then inspect the selected real source. Do not dump map databases into a
prompt.

Keep complete and unchanged:

- source files;
- full diffs and per-file patches;
- test and static-analysis output;
- generated verification artifacts;
- redirected harness files and decisive errors.

Concise summaries help humans navigate evidence; they never replace it. Run
repository commands normally. Do not add a command or output rewriter merely to
make evidence shorter.

## Historical Evidence

```bash
ctx search "<task / failure / module / command>"
ctx show event <ctx-event-id> --window 5
```

Inspect focused hits before using them. Persist only bounded IDs, query, reviewed
summary, retrieval time, and verification status. Never promote raw transcripts
or unverified history.

## Validation Evidence

```bash
vendor/bin/agent-loop session validation record <task-id> \
  --brief-revision <current-revision> \
  --command "vendor/bin/phpunit tests/FocusedTest.php" \
  --status passed \
  --exit-code 0 \
  --by <actor>
```

Never infer a pass from missing output, an agent summary, or an earlier brief.

## Review And Close

```bash
vendor/bin/agent-loop review blindspots <task-id>
vendor/bin/agent-loop verify
vendor/bin/agent-loop workflow report <task-id>

vendor/bin/agent-loop session learning decide <task-id> \
  --status no_durable_learning \
  --by <actor> \
  --reason "No reusable finding from this bounded task."

vendor/bin/agent-loop workflow close <task-id> --status done
```

A learning decision records an outcome; it does not approve durable guidance.

## Guidance Changes

When package-owned agent behavior changes, run:

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init doctor
composer dogfood:discipline
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer ci
```

Claim only checks whose exit status was observed.
