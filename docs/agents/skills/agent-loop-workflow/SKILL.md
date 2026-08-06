---
name: agent-loop-workflow
description: Use the governed agent-loop workflow for planning, approval, bounded context, implementation evidence, review, learning decisions, and safe closure.
---

# Agent Loop Workflow

Use this skill when operating or changing `agent-loop`. Apply
`agent-loop-discipline` during implementation and
`agent-loop-simplify-review` as an additional complexity-only review when the
diff may contain speculative code.

## Fast Path

1. Inspect prior history only when earlier decisions materially affect the task.
2. Plan explicit goal, scope, non-goals, behavior anchors, and exact validation.
3. Approve that revision through a named human actor.
4. Use `agent-map` to select bounded source reads.
5. Implement the smallest correct change in the owning package.
6. Record validation against the current brief revision.
7. Review blind spots and the complete raw diff.
8. Record recall outcomes and an explicit learning decision.
9. Run cross-package verification and inspect the workflow report.
10. Close as `done` only when every required gate passes.

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

The L2 briefing labels claims `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, or
`CONTRADICTED`. Model explanations and review comments remain hypotheses until
current repository evidence, focused history, or a safe runtime observation
supports them.

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
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
composer dogfood:discipline
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer ci
```

Claim only checks whose exit status was observed.
