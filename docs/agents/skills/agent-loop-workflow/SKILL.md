---
name: agent-loop-workflow
description: Use the governed agent-loop workflow for this repository, including planning, approval, bounded context, validation evidence, review, learning decisions, and safe closure.
---

# Agent Loop Workflow

Use this skill when operating or changing `agent-loop` itself: planning work,
compiling recall, preparing bounded context, recording evidence, reviewing blind
spots, closing a task, or changing repo-managed guidance.

For PHP implementation work, apply `agent-loop-php-discipline` as well. It keeps
the implementation minimal and the conversation concise without modifying raw
source, diffs, command output, or verification artifacts.

## Fast Path

1. Search prior local agent history with `ctx` only when earlier decisions may
   materially affect the task.
2. Plan with an explicit goal, scope, non-goals, changed files, behavior anchors,
   and exact validation commands.
3. Present the candidate brief to a named human and approve that revision.
4. Build a semantic map when compact source navigation matters.
5. Render bounded workflow context.
6. Implement the smallest correct change in the owning package.
7. Record passed validation evidence against the current brief revision.
8. Run blind-spot review, record recall outcomes, and make an explicit learning
   decision.
9. Run cross-package verification and inspect the workflow report.
10. Close as `done` only when every gate passes.

If repo-managed agent guidance changed, also run:

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
```

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
vendor/bin/agent-loop workflow context <task-id> \
  --max-lines 120 \
  --max-bytes 12000
```

Use lower-level commands only when direct control is required:

- `session` for decisions and compact checkpoints;
- `session validation record` for revision-bound execution evidence;
- `session learning decide` for the explicit learning outcome required before a
  successful close;
- `recall compile` for debugging briefing inputs or output layout;
- `review blindspots` for the required review artifact;
- `verify` for cross-package consistency;
- `learn` for findings and reviewed guidance proposals;
- `memory review` for human promotion into durable memory;
- `init sync-*` for copying canonical repo-managed assets into client targets.

## Workflow Boundary

- `workflow plan` starts or reuses session memory and records a candidate brief.
- `workflow approve` seals that exact revision and compiles recall from it.
- Re-planning invalidates approval and validation evidence for the old revision.
- `workflow context` is read-only and budgeted. It does not rebuild recall or a
  semantic map.
- `workflow status` and `workflow report` are read-only.
- Pass observed changed paths explicitly to `workflow report`; it does not run
  Git for you.
- `workflow close --status done` requires a current approved brief, evidence for
  its exact validation commands, recall outcomes, blind-spot review, an explicit
  learning decision, and passing verification.
- Recall artifacts are written to disk. They are not silently injected into a
  coding agent.
- Learning artifacts are not durable memory until reviewed and promoted.

The L2 briefing labels claims as `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`,
or `CONTRADICTED`. Treat model explanations, review comments, and foreign-agent
feedback as hypotheses until current repository evidence, focused history, or a
safe runtime observation supports them.

Read `docs/workflow/learning-boundary.md` when the task touches learning or
memory promotion.

## Historical Evidence

Use `ctx` as inspected historical evidence, never as automatic truth:

```bash
ctx status
ctx sources
ctx search "<task / failure / module / command>"
ctx show event <ctx-event-id> --window 5
```

Inspect focused hits before relying on them. Do not paste raw transcripts into
findings, skills, reports, or PR text. When history affects a finding, store only
bounded references: IDs, query, reviewed summary, retrieval time, and
verification status.

## Semantic Map

Generated map output is disposable navigation state:

```bash
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map refresh
vendor/bin/agent-loop map stale
```

The default index is `.agent-map/php-symbols.json`. Keep `.agent-map/` ignored.
Use the index to select source ranges; do not treat it as source code, durable
memory, or prompt material to copy wholesale.

## Evidence Integrity And Human Attention

Run repository commands normally. Do not place a lossy command or output
rewriter between the agent and the evidence it must inspect.

Keep these artifacts complete:

- source files;
- `git diff` and per-file patches;
- test and static-analysis output;
- generated verification files;
- files written by a harness when it truncates or redirects large tool output.

Concise summaries belong around evidence, not instead of it. A summary may tell
the human what matters, but code review still reads the full diff and debugging
still uses the exact decisive output.

When a harness says large output was stored in a file, read that stored artifact
as the source of truth. Do not transform it before reading. If completeness is
material, compare the producing command's expected size, line count, or hash.

For generated files in a bind-mounted repository, use a git-ignored repo-local
scratch path such as `.agent-loop/tmp/` rather than copying the same file into
the container. A real copy remains appropriate when the repository is not
mounted.

## Validation Evidence

After each required command actually runs, record the exact result:

```bash
vendor/bin/agent-loop session validation record <task-id> \
  --brief-revision <current-revision> \
  --command "vendor/bin/phpunit tests/FocusedTest.php" \
  --status passed \
  --exit-code 0 \
  --by <actor>
```

Never infer a pass from missing output, an agent summary, or an earlier brief
revision.

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

A learning decision records an outcome. It does not approve durable guidance.

## Skill Boundary

This skill owns:

- the governed plan/approve/context/status/report/close flow;
- the boundary between workflow evidence and durable guidance;
- correct use of session, recall, review, verify, learning, and memory commands;
- evidence-preserving operation of repo-managed guidance changes.

It does not own:

- client-specific runtime assumptions beyond documented `init` targets;
- pretending recall or history is automatically injected;
- approving durable memory;
- rewriting tool output to make it shorter.

## Validation

```bash
vendor/bin/agent-loop init validate --kind=skills
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
vendor/bin/phpunit --filter 'Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
```
