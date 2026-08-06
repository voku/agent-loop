---
name: agent-learning
description: Capture reusable lessons about agent-loop workflow, validation, migration, evidence integrity, and PHP implementation discipline before promoting them into durable guidance.
---

# Agent Learning

Use this skill after implementation or migration work exposes a reusable lesson
for `agent-loop` or another `agent-*` package. Keep the lesson evidence-backed,
bounded, and placed in the guidance surface that owns it.

## Fast Path

1. Check whether the lesson already exists in README, changelog,
   `docs/agents/`, or `docs/workflow/`.
2. Search local history with `ctx` only when prior decisions or failed attempts
   materially affect the conclusion.
3. Sweep the complete validated backlog, not only findings from the current
   session.
4. Cluster findings by owning workflow or package boundary.
5. Promote each lesson to the lowest durable mechanism that solves the verified
   problem: existing doc, existing skill, new focused skill, or executable
   constraint.
6. Validate the affected behavior and name any deliberate residual backlog.

## Learning Discipline

### Sweep the whole backlog

Use the learning registry and backlog gate to enumerate every validated,
unconsolidated item. Completion means the residual is zero or every deferred
item is named with a reason. Handling only the latest findings is not a pass; it
is merely recency bias wearing a checklist.

### Climb the value ladder

A finding may become:

```text
raw finding
  -> reviewed guidance or durable memory
  -> executable constraint when the property is statically verifiable
```

Do not stop at a memory row when a small PHPStan rule can reliably prevent the
same defect. Do not create a static-analysis rule for subjective advice that
cannot be checked without noise.

### Preserve evidence

Source files, diffs, command output, test output, and generated verification
artifacts are evidence. Do not compress or rewrite them before evaluation.

A human-facing summary may be concise, but it must point to complete evidence.
When a harness redirects or truncates output into a file, read the stored file as
the source of truth and verify size, line count, or hash when completeness
matters.

The reusable lesson is not "make every tool output shorter." The useful split
is:

- reduce filler in agent communication so humans can review it;
- reduce unrequested implementation through YAGNI and existing-code reuse;
- keep machine and repository evidence exact.

### Stay on the objective

A learning pass produces guidance, memory decisions, and executable constraints.
Do not drift into unrelated product features. Product changes require their own
brief, evidence, and review.

## Existing Guidance First

Inspect:

- `docs/agents/INFO_Agents.md`;
- the relevant skill under `docs/agents/skills/`;
- `docs/agents/migration/`;
- `docs/workflow/learning-boundary.md`;
- `README.md`;
- `CHANGELOG.md`.

Refine the existing home instead of creating duplicate rules with slightly
different wording, humanity's traditional route to documentation entropy.

## Historical Context

```bash
ctx search "<task / migration / failure / command>"
ctx show event <ctx-event-id> --window 5
```

History helps explain what happened. It does not prove current behavior. Record
only bounded references: event IDs, query, retrieval time, reviewed summary,
and verification status. Never paste raw transcripts or secrets into durable
guidance.

## Promotion Targets

- `docs/agents/skills/...` for repeatable agent behavior;
- `agent-loop-php-discipline` for concise communication, minimal PHP changes,
  package ownership, and raw evidence integrity;
- `docs/agents/INFO_Agents.md` for shared operational guidance;
- `docs/agents/migration/...` for host-specific migration seams;
- `docs/workflow/...` for lifecycle and learning boundaries;
- README for public behavior;
- changelog for released or unreleased changes;
- PHPStan or coding-standard rules for precise executable constraints.

Keep lessons specific. Good guidance names a command, path, consumer, or
failure boundary and states how it was verified. Generic slogans without a
proof seam are decoration.

## Validation

Use the smallest check that proves the claimed behavior, then repository gates:

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init validate --kind=skills
vendor/bin/phpunit --filter 'Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
```

Use `composer ci` and the repository formatter when defined. Do not claim a pass
without observing the command and exit code.

## Skill Boundary

This skill owns reusable learning from agent-loop workflow, init dogfooding,
agent-package boundaries, validation, evidence handling, and host migrations.

It does not own unrelated product logic, invented durable rules, automatic
memory promotion, or lossy transformation of evidence.
