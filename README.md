# Agent Loop

A governed coding-agent workflow for PHP repositories.

`voku/agent-loop` coordinates focused `agent-*` packages into one explicit,
replayable lifecycle. The goal is not more hidden autonomy. The goal is to make
**task policy, project context, implementation, validation, review, learning,
and completion independently inspectable**.

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

For tasks that select an L2 operational recipe, `CONTRACT` is a real gate rather
than advice: the project-specific L1 execution contract must be persisted and
bound to the current approved WorkBrief + recall evidence before governed
mutation can start.

## Installation

```bash
composer require --dev voku/agent-loop
```

Requirements:

- PHP 8.3 or newer
- Composer

The package exposes:

```bash
vendor/bin/agent-loop
```

## Package ownership

`agent-loop` is the orchestrator, not the owner of every concern.

| Package | Owns |
| --- | --- |
| `voku/agent-kanban` | durable local task/card state |
| `voku/agent-session` | per-task working memory, revisioned WorkBrief policy, approval, validation evidence |
| `voku/agent-map` | deterministic PHP symbol/navigation map and derived search index |
| `voku/agent-learning` | findings, proposals, guidance lifecycle, outcome history, canonical application proof |
| `voku/agent-recall-compiler` | deterministic task recall, L2 recipe rendering, project capabilities, validation/verification plans, recipe outcomes |
| `voku/agent-skills` | reusable engineering semantics and the operating-prompt recipe catalog |
| `voku/agent-loop` | cross-package orchestration, execution-contract persistence/gates, run projection, installed assets, verification |

The 0.14 release line is coordinated with:

```text
agent-learning       0.9.*
agent-session        0.4.*
agent-recall-compiler ^0.10.0
agent-map            ^0.4.0
agent-kanban         0.2.*
```

## Start a repository

Create the minimum local workflow structure:

```bash
vendor/bin/agent-loop init scaffold
```

See [docs/quick-start.md](docs/quick-start.md) for the longer first-task walk-through.

## Governed task flow

### 1. PLAN

Create/resume the task session and a candidate WorkBrief:

```bash
vendor/bin/agent-loop workflow plan TASK-123 \
  --by lars \
  --file src/Parser.php \
  --file tests/ParserTest.php \
  --goal "Harden parser regression coverage" \
  --scope src/Parser.php \
  --scope tests/ParserTest.php \
  --validation "vendor/bin/phpunit tests/ParserTest.php"
```

A WorkBrief owns the durable task policy for this attempt: goal, scope,
non-goals, validation, tags, behavior anchors, and optional operating-prompt
selection.

For an L2 recipe, select the manifest and recipe explicitly:

```bash
vendor/bin/agent-loop workflow plan TASK-123 \
  --by lars \
  --file src/Parser.php \
  --file tests/ParserTest.php \
  --goal "Harden parser regression coverage" \
  --scope src/Parser.php \
  --scope tests/ParserTest.php \
  --validation "vendor/bin/phpunit tests/ParserTest.php" \
  --operating-prompt-manifest <path-to-operating-prompts.json> \
  --operating-prompt '{"id":"regression-hunt","arguments":{"minimum_findings":1}}'
```

Use the exact manifest path installed or projected by the host repository. The
workflow does not search for or guess an operating-prompt manifest.

There is no hidden recipe selection and no hidden threshold default. If policy
changes, the WorkBrief revision changes and the old approval no longer applies.

### 2. APPROVE

```bash
vendor/bin/agent-loop workflow approve TASK-123 --by lars
```

Approval seals the current WorkBrief revision and compiles recall from that
approved state. Re-running approval after a compile failure resumes compilation
instead of creating a second approval.

### 3. CONTEXT

Inspect the bounded current task context:

```bash
vendor/bin/agent-loop workflow context TASK-123
vendor/bin/agent-loop workflow status TASK-123 --format=json
```

Recall can include exact task files, resolved symbols/callers/tests, project
documents, active constraints, prior guidance, deterministic tool/config
capabilities, and selected L2 recipe semantics. Missing evidence stays missing;
package presence alone does not invent an executable command.

### 4. CONTRACT

If the approved WorkBrief selected L2 policy, use the compiled L2 instructions +
project evidence to construct one project-specific L1 document with exactly:

```text
## Goal
## Context
## Constraints
## Verification
## Done When
```

`Verification` answers **how reality is measured**. `Done When` answers **which
observed result permits success**.

Persist a ready contract:

```bash
vendor/bin/agent-loop workflow contract TASK-123 \
  --status ready \
  --from .agent-loop/tmp/TASK-123-l1.md \
  --by agent
```

Or persist an evidence-backed stop instead of weakening the approved policy:

```bash
vendor/bin/agent-loop workflow contract TASK-123 \
  --status blocked \
  --reason "Mutation command is not supported by repository evidence" \
  --evidence "composer.json has no mutation script and no Infection package" \
  --minimum-change "Revise the WorkBrief recipe arguments or add the required project capability" \
  --by agent
```

`rejected` uses the same evidence/minimum-change model when an attempted
implementation violated the current contract.

The persisted contract is bound to:

- task id;
- WorkBrief revision;
- recall bundle digest;
- selected prompt semantics/arguments;
- contract content digest;
- actor/time.

A re-plan or materially changed recall therefore makes the previous contract
stale automatically.

### 5. IMPLEMENT

Governed mutating `agent-loop edit` runners (`command`, `mechanical`, `auto`) are
blocked for an active L2-selected task until its current execution contract is
`ready`.

Read-only investigation, map queries, `workflow context`, stdout prompt
construction, and dry-run work remain available before the mutation gate.

This is deliberate. L2 is the pass that builds the project-specific contract;
it is not permission to skip straight from generic recipe to code.

### 6. VALIDATE

Run the repository-native validation required by the WorkBrief and the L1
`Verification` section. Record observed evidence against the current WorkBrief
revision. A non-zero exit code cannot be recorded as a passing validation.

For edit bundles with repository-knowledge verification:

```bash
vendor/bin/agent-loop edit verify --bundle=.agent-loop/edit/TASK-123 --run-commands
```

This is different from repository-wide `agent-loop verify`.

### 7. REVIEW

Use deterministic review artifacts and the smallest relevant engineering lens.
The first-party `code-review-*` skills are targeted lenses, not a mandatory
review swarm. One dominant review may hand off to at most one evidence-backed
follow-up lens when another concern becomes primary.

### 8. LEARN

Close the learning boundary explicitly: record findings when something reusable
was discovered, or record `no_durable_learning` when nothing deserves promotion.
Do not turn every successful task into permanent project guidance merely because
an agent had thoughts while editing it.

`agent-learning` 0.9 also makes `APPLIED` stronger for Memory/Skill guidance: a
reviewed mutation must be physically proven in its canonical target. Proposal
state is not a substitute for repository reality.

### 9. VERIFY

```bash
vendor/bin/agent-loop verify --task-id=TASK-123
```

This checks cross-package consistency and drift. Each check skips itself when its
own inputs are absent; it does not manufacture missing work merely to produce a
green dashboard.

### 10. CLOSE

```bash
vendor/bin/agent-loop workflow close TASK-123 --status done
```

A successful close is gated. For L2-selected tasks, a current ready execution
contract is mandatory and `--accept-risk` does **not** bypass that boundary.
Accepted risk remains available only for explicitly bypassable close gates and
must carry both reason and owner.

## Status and run manifest

The run manifest is a read model joining the owning artifacts; it is not a new
workflow authority.

```bash
vendor/bin/agent-loop workflow status TASK-123
vendor/bin/agent-loop workflow status TASK-123 --format=json
vendor/bin/agent-loop workflow manifest TASK-123 --format=json
vendor/bin/agent-loop workflow manifest TASK-123 --write
```

See [docs/architecture/run-manifest-v1.md](docs/architecture/run-manifest-v1.md).

## Navigation before broad reads

For PHP repositories, use the map as bounded navigation rather than dumping the
whole index into model context:

```bash
vendor/bin/agent-loop map query SomeClass
vendor/bin/agent-loop map related SomeClass
vendor/bin/agent-loop map file src/SomeClass.php
vendor/bin/agent-loop map changed --base=main
```

The map selects primary-source reads. It does not replace source evidence.

## Agent assets

The package ships reviewed first-party workflow skills, bounded subagent roles,
and supported client hook bundles. Install them explicitly:

```bash
vendor/bin/agent-loop init install-assets --agent=all
```

A project can additionally merge the explicit `voku/agent-skills` catalog as an
extra skill root. Package-owned assets and external catalog skills remain
separate sources with manifest-based ownership rather than silently overwriting
each other.

Useful diagnostics:

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init status
vendor/bin/agent-loop init validate --kind=all --agent=codex
```

## Git hooks and Make integration

`agent-loop` also ships generic repository Git-hook logic and
`make/agent-loop.mk`. Project-specific checks/container settings remain data in
the host repository instead of being copied into another wrapper script.

See the docs under `docs/` for the detailed installation and ownership rules.

## CI and dogfood

The project tests the workflow at distinct levels:

1. PHPUnit + PHPStan/Composer CI on supported PHP versions;
2. the governed execution-contract dogfood proving mutation is denied before a
   bound L1 and allowed afterward;
3. `Agent-loop shapes itself`, which runs the workflow against its own change;
4. the installed release-set dogfood, which creates a clean Composer consumer and
   proves the **published** package set works without sibling checkout leakage.

Candidate cross-package CI is pinned to exact commit SHAs. The installed
release-set gate intentionally uses published versions and therefore catches a
release that was prepared in Git but never actually made installable.

See:

- [docs/testing/installed-release-set-gate.md](docs/testing/installed-release-set-gate.md)
- [docs/architecture/supported-release-set.md](docs/architecture/supported-release-set.md)

## What agent-loop does not do

- It does not call an LLM inside deterministic workflow/session/recall libraries.
- It does not select prompt recipes by keyword or model guess.
- It does not crawl an entire repository and call that context management.
- It does not let a run manifest replace the packages that own task/session/recall/learning state.
- It does not treat selected guidance or recipes as proof that they were useful.
- It does not automatically rewrite operational recipes from outcome statistics.
- It does not make accepted risk a universal bypass.

## Detailed docs

- [Quick start](docs/quick-start.md)
- [Lifecycle](docs/agents/LIFECYCLE.md)
- [Run manifest v1](docs/architecture/run-manifest-v1.md)
- [Supported release set](docs/architecture/supported-release-set.md)
- [Installed release-set gate](docs/testing/installed-release-set-gate.md)
- [Agent guidance](docs/agents/)
- [Changelog](CHANGELOG.md)

## Development

```bash
composer install
composer ci
```

## License

MIT — see [LICENSE](LICENSE).
