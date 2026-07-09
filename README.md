# Agent Loop

A governed coding-agent workflow for PHP repositories.

`voku/agent-loop` is the umbrella package for a local, auditable
agentic-coding loop. It combines task selection, working sessions, recall
compilation, gated workflow orchestration, verification, deterministic
review, learning capture, memory promotion, and repo setup/diagnostics
behind one CLI:

```bash
vendor/bin/agent-loop
```

The goal is not to make a coding agent "remember everything".

The goal is to make it work in a controlled loop where useful context is
selected, work is verified, findings are reviewed, and only approved
knowledge becomes durable guidance.

```text
agent-loop
  board     → pick and inspect work
  session   → track active task context
  recall    → compile relevant approved guidance
  workflow  → gated start / status / close orchestration
  verify    → check board/task/session/recall/learning consistency
  review    → deterministic blind-spot and code-review prompts
  learn     → capture findings, proposals and decision history
  memory    → review what should become durable project memory
  init      → diagnostics, install plans, repo-managed agent assets
```

## Why this exists

Coding agents are useful, but they are also very good at three bad habits:

1. forgetting project-specific rules,
2. repeating old mistakes,
3. stuffing too much irrelevant context into the prompt.

`agent-loop` gives them a process.

Instead of asking an agent to "just fix this", you run a loop:

```text
pick work
  → load only relevant guidance
  → make the change
  → verify
  → review for blind spots
  → capture what was learned
  → decide what survives
```

That last part matters.

Not every observation should become memory. Some findings are temporary,
accidental, stale, or just wrong. A useful agent workflow needs both
learning and forgetting.

## What agent-loop is

`agent-loop` is:

- a unified CLI for several focused agent packages,
- a workflow boundary around coding-agent sessions, with a gated
  start/status/close orchestration layer on top,
- a way to make project knowledge auditable,
- a human-in-the-loop process for promoting durable guidance,
- a local-first toolchain for agentic coding work, including its own
  setup diagnostics (`init`).

## What agent-loop is not

`agent-loop` is not:

- an autonomous coding platform,
- an LLM provider,
- a vector database,
- a hidden memory system,
- a replacement for tests or static analysis,
- a place to dump every transcript forever.

If everything becomes memory, memory becomes landfill.

## Package architecture

`agent-loop` delegates to specialized packages instead of rebuilding
everything in one large tool.

```text
┌────────────────────────────────── voku/agent-loop ──────────────────────────────────┐
│                                                                                       │
│  agent-loop board         → voku/agent-kanban           (board + optional Jira sync) │
│  agent-loop board:verify  → voku/agent-kanban            (board-source-only check)   │
│  agent-loop session       → voku/agent-session           (per-task working memory)   │
│  agent-loop recall        → voku/agent-recall-compiler   (L2 meta-prompt compiling)  │
│  agent-loop review        → voku/agent-recall-compiler   (blind-spot / code prompts) │
│  agent-loop learn         → voku/agent-learning          (findings/proposals/history)│
│  agent-loop workflow      → voku/agent-loop              (start/status/close gate)   │
│  agent-loop verify        → voku/agent-loop              (cross-package consistency) │
│  agent-loop memory        → voku/agent-loop              (MEMORY.md promotion review)│
│  agent-loop init          → voku/agent-loop              (setup diagnostics/sync)    │
│                                                                                       │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

Each dependency package has one job:

| Package | Responsibility |
| --- | --- |
| `voku/agent-kanban` | Markdown/Jira-style task board and board-source verification |
| `voku/agent-session` | Per-task working memory and session plans |
| `voku/agent-recall-compiler` | Task-specific recall/L2 meta-prompt compilation, plus blind-spot and code-review prompts |
| `voku/agent-learning` | Findings, proposals, decision history and guidance evaluation |
| `voku/agent-loop` | Unified CLI, gated workflow orchestration, cross-package verification, memory promotion review, and setup diagnostics |

## The loop

A typical workflow looks like this. The gated `workflow` commands are the
preferred entry and exit points; the lower-level package commands they wrap
stay available directly when you need finer control.

Pick the work:

```bash
vendor/bin/agent-loop board summary
vendor/bin/agent-loop board render --lanes=READY,BACKLOG --limit=10
vendor/bin/agent-loop board ticket ABC-123
```

Start the governed task context — this wraps `session start` and
`recall compile` in one gated step:

```bash
vendor/bin/agent-loop workflow start ABC-123 \
  --by lars \
  --learning-root infra/doc/agent-learning \
  --file src/Foo.php
```

Do the actual coding work with your preferred agent, feeding it the
compiled recall artifacts (`system.md`, `validation-plan.md`) yourself —
`agent-loop` writes them for review or harness ingestion, it does not
inject them into a running agent.

Run the deterministic blind-spot review before closing:

```bash
vendor/bin/agent-loop review blindspots ABC-123
```

Verify cross-package consistency, then close the task — `workflow close`
requires recall metadata, a blind-spot review report, and a passing
`agent-loop verify` before it will let a task go to `done`:

```bash
vendor/bin/agent-loop verify

vendor/bin/agent-loop workflow close ABC-123 --status done
```

Capture what the session discovered:

```bash
vendor/bin/agent-loop learn validate --root infra/doc/agent-learning
vendor/bin/agent-loop learn guidance-evaluate --root infra/doc/agent-learning
```

Finally, review durable memory candidates:

```bash
vendor/bin/agent-loop memory review --file MEMORY.md
```

## Human-in-the-loop by design

`agent-loop` deliberately keeps humans in the loop.

A coding agent may collect findings, suggest rules, and propose new
guidance. But it should not silently rewrite the project's long-term
memory, and it should not close its own task as done without evidence.

Durable guidance should be reviewed because project rules have
consequences:

```text
Finding:
  "This failed because the service locator was used inside a validator."

Proposal:
  "Validators must receive dependencies explicitly."

Possible durable constraint:
  "Do not call ServiceLocator::get() from Validator classes."
```

That final step needs a human decision.

The agent can notice the pattern. `workflow close` can require the
evidence exists. The project owner decides whether it becomes a rule.

## Learning vs constraints

There are different kinds of knowledge:

| Type | Meaning |
| --- | --- |
| Finding | Something observed during a session |
| Proposal | A suggested rule, skill, or memory update |
| Learning note | Useful context, but not necessarily a hard rule |
| Skill | Repeatable procedural guidance |
| Constraint | A hard project rule the agent must follow |
| Rejected guidance | Something considered and intentionally not adopted |

The most important output is often not "more memory".

It is a sharper constraint:

```text
Do not use direct Smarty rendering in new module code.
Always run the focused PHPUnit test after changing EvidenceValidator.
Do not promote ctx search results unless the referenced event was inspected.
```

Hard constraints prevent repeated mistakes.

Soft notes merely hope the agent behaves. Hope is not a strategy, despite
its popularity.

## Forgetting is part of the system

A good agent workflow needs explicit rejection.

Some observations should not survive:

- one-off workarounds,
- stale debugging notes,
- accidental implementation details,
- failed ideas,
- project-specific exceptions that should not become general rules.

`agent-loop` treats this as part of governance.

Forgetting bad or irrelevant context is often more valuable than learning
another vague rule.

## CLI overview

```bash
vendor/bin/agent-loop help
```

Available namespaces:

```text
board        Local Markdown/Jira-style task board (voku/agent-kanban)
board:verify Board-source-only check (voku/agent-kanban)
session      Per-task working memory (voku/agent-session)
recall       Recall / L2 meta-prompt compilation (voku/agent-recall-compiler)
review       Deterministic blind-spot and code-review L2 prompts (voku/agent-recall-compiler)
learn        Findings, proposals and learning history (voku/agent-learning)
workflow     Gated start/status/close orchestration (voku/agent-loop)
verify       Cross-package consistency check (voku/agent-loop)
memory       MEMORY.md promotion review (voku/agent-loop)
init         Setup diagnostics, install plans, agent-asset syncing (voku/agent-loop)
```

Run `agent-loop <namespace> help` (or `--help`) for a namespace's own
command list.

### Board

```bash
vendor/bin/agent-loop board summary
vendor/bin/agent-loop board render --lanes=READY,BACKLOG --limit=10
vendor/bin/agent-loop board ticket ABC-123
```

Reads work items from local Markdown card files under `todo/cards/*.md`
(one file per card), with `todo/board.md` holding board metadata (project
prefix, done count). This works fully standalone — no Jira host,
credentials, or network access required. `todo/jira/` and a root
`TODO.md` remain supported fallback inputs.

Only `board jira-sync` talks to Jira, and only once the host application
constructs the `Dispatcher` with its own `JiraIssueProvider` (see
"Programmatic usage" below) — the bare `bin/agent-loop` wires none. Every
other `board` command works from the local Markdown cards alone.

### Session

```bash
vendor/bin/agent-loop session help
```

Tracks per-task working memory and session plans. Use this for active
work state, not durable project memory.

### Recall

```bash
vendor/bin/agent-loop recall compile \
  --root infra/doc/agent-learning \
  --task ABC-123 \
  --file src/Foo.php
```

Compiles a scoped briefing for the current task. The recall compiler
should select relevant approved guidance, not dump every note the
project ever had.

### Workflow

```bash
vendor/bin/agent-loop workflow start <task-id> --by <actor> --learning-root infra/doc/agent-learning --file src/Foo.php
vendor/bin/agent-loop workflow status <task-id>
vendor/bin/agent-loop workflow close <task-id> --status done
```

`workflow start` wraps `session start` and `recall compile`. `workflow
status` prints read-only session, recall, and review state. `workflow
close` is a gated wrapper around `session close`: it requires recall
metadata, a blind-spot review report, and a passing `agent-loop verify`
before closing a task as done. It does not approve code, does not
approve durable learning, and does not call an LLM.

Accepted risk is explicit and written to disk:

```bash
vendor/bin/agent-loop workflow close <task-id> \
  --status done \
  --accept-risk "Manual review by Lars for urgent legacy hotfix."
```

### Verify

```bash
vendor/bin/agent-loop verify
```

The one command that looks across board, session, recall, and learning
state at once. Checks each print `[OK]`, `[SKIP]`, or `[FAIL]` and skip
themselves when their inputs are absent, so the command stays meaningful
for a repo that only wires up part of the stack. Pass `--strict` to fail
instead of skip when the `tasks/` or `session_plan/` baseline is missing
entirely.

### Review

```bash
vendor/bin/agent-loop review blindspots <task-id>
vendor/bin/agent-loop review code <task-id>
```

Writes deterministic Markdown/JSON blind-spot reports plus an L2 review
prompt under `.agent-recall/reviews/`, using task, session, and recall
artifacts as prompt context. `review code` generates a focused L2
code-review prompt (purpose mismatch, contracts, invariants, edge cases,
security, test gaps). Neither command approves code or calls an LLM
itself — the generated prompt is for a human or harness to pass to a
receiving LLM.

### Learn

```bash
vendor/bin/agent-loop learn validate --root infra/doc/agent-learning
vendor/bin/agent-loop learn guidance-evaluate --root infra/doc/agent-learning
```

Captures and evaluates findings, proposals, and learning history.

### Memory

```bash
vendor/bin/agent-loop memory review --file MEMORY.md
```

Reports which `MEMORY.md` rows look ready for promotion; it never edits
`MEMORY.md` itself. Promotion stays a manual edit by whoever owns that
file.

### Init

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init status
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init sync-skills --agent=codex
```

Diagnoses local setup, prints reviewed install plans (ripgrep, RTK,
Caveman), and syncs repo-managed skills/subagents/hooks into client
target directories. It does not affect workflow close, does not call an
LLM, and does not install remote tools.

## Installation

```bash
composer require voku/agent-loop
```

| Requirement | Version |
| --- | --- |
| PHP | 8.3 or newer |
| Composer | required |

This installs `voku/agent-kanban`, `voku/agent-session`,
`voku/agent-recall-compiler`, and `voku/agent-learning` as dependencies
and exposes `vendor/bin/agent-loop`.

## Programmatic usage

Hosts that need custom integrations, for example Jira, can wire the
dispatcher manually.

```php
<?php

declare(strict_types=1);

use voku\AgentKanban\JiraIssueProvider;
use voku\AgentLoop\Dispatcher;

$provider = new class implements JiraIssueProvider {
    public function projectKey(): string
    {
        return 'ABC';
    }

    public function searchIssues(string $jql): array
    {
        // Connect to your own Jira client here.
        return [];
    }
};

$rootPath = getcwd() ?: '.';

exit((new Dispatcher(
    rootPath: $rootPath,
    jiraIssueProvider: $provider,
    projectPrefix: 'ABC',
))->run($argv));
```

The default binary does not ship a Jira client because Jira clients are
host-specific.

That is intentional. The package should not pretend your company's Jira
setup is universal. Software has enough lies already.

## Scheduled execution

`agent-loop` is the workflow CLI.

If you want scheduled maintenance, use a runner such as
[`voku/housekeeping`](https://github.com/voku/housekeeping) to call
selected `agent-loop` commands from cron or another scheduler.

Example scheduled jobs could include:

```text
board refinement
board verification
recall validation
memory review
learning consistency checks
```

Keep scheduled jobs conservative.

Agents may suggest. Humans approve.

## Suggested repository layout

A repository using `agent-loop` may keep agent workflow files under
`infra/doc/agent-learning`:

```text
infra/
  doc/
    agent-learning/
      findings/
      proposals/
      decisions/
      skills/
      constraints/
      rejected/
```

Example workflow files:

```text
MEMORY.md
AGENTS.md
session_plan/
```

The exact structure depends on the consuming packages and project
conventions.

## Token hygiene

`agent-loop` is part of a broader token-hygiene strategy.

It reduces prompt waste by making context selective:

```text
session
  current task state

recall
  relevant approved guidance

learn
  structured findings and proposals

memory
  reviewed durable knowledge
```

The point is not to compress everything.

The point is to avoid loading irrelevant things in the first place.

## Example: from finding to constraint

A session discovers this:

```text
Finding:
  A previous agent changed validation logic but did not run the focused validator test.
```

A proposal is created:

```text
Proposal:
  When changing EvidenceValidator, always run EvidenceValidatorTest before finalizing.
```

A human reviews it.

If accepted, it may become durable guidance:

```text
Constraint:
  Changes to EvidenceValidator require running tests/EvidenceValidatorTest.php.
```

If rejected, it is recorded as rejected guidance instead of being
silently forgotten or accidentally rediscovered next week like a cursed
treasure.

## Development

Install dependencies:

```bash
composer install
```

Run the test suite:

```bash
composer test
```

Run PHPStan:

```bash
composer phpstan
```

Run all CI checks:

```bash
composer ci
```

`composer ci` runs:

```bash
composer validate --strict
phpunit
phpstan
```

## Design principles

`agent-loop` follows a few boring but useful rules:

- keep packages focused,
- keep generated context reviewable,
- prefer explicit files over hidden state,
- treat durable memory as a reviewed artifact,
- reject bad learnings instead of accumulating noise,
- keep humans in control of project rules,
- make agent work verifiable.

Boring is good here.

Boring tools fail less dramatically.

## License

MIT. See [LICENSE](LICENSE).
