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

## Quick start

Install the package in an existing PHP project:

```bash
composer require --dev voku/agent-loop
```

Create the minimal local workflow structure and a clearly marked example
task:

```bash
vendor/bin/agent-loop init scaffold
```

Inspect the generated card:

```bash
vendor/bin/agent-loop board card show DEMO-1
```

Plan and approve it. This example uses `composer.json`, which is already in a
Composer project; replace it with the real file you intend to change.

```bash
vendor/bin/agent-loop workflow plan DEMO-1 \
  --by "$(git config user.name)" \
  --file composer.json \
  --goal "Add a small validated change." \
  --validation "composer test"

vendor/bin/agent-loop workflow approve DEMO-1 \
  --by "$(git config user.name)"
```

Generate the bounded context for your coding agent:

```bash
vendor/bin/agent-loop workflow context DEMO-1
```

After making the change, record its validation and continue through review and
closure. See [Your first governed task](docs/quick-start.md).

The goal is not to make a coding agent "remember everything".

The goal is to make it work in a controlled loop where useful context is
selected, work is verified, findings are reviewed, and only approved
knowledge becomes durable guidance.

```text
agent-loop
  board     → pick and inspect work
  session   → track active task context
  map       → navigate compact PHP symbols
  recall    → compile relevant approved guidance
  workflow  → plan / approve / start / status / report / close
  verify    → check board/task/session/recall/learning consistency
  review    → deterministic blind-spot and code-review prompts
  learn     → capture findings, proposals and decision history
  memory    → review what should become durable project memory
  init      → diagnostics, minimal workflow scaffolding, repo-managed agent assets
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
  plan/approve/start/status/report/close orchestration layer on top,
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
│  agent-loop board         → voku/agent-kanban       (board + optional external sync) │
│  agent-loop board:verify  → voku/agent-kanban            (board-source-only check)   │
│  agent-loop session       → voku/agent-session           (per-task working memory)   │
│  agent-loop map           → voku/agent-map               (compact PHP symbol map)    │
│  agent-loop recall        → voku/agent-recall-compiler   (L2 meta-prompt compiling)  │
│  agent-loop review        → voku/agent-recall-compiler   (blind-spot / code prompts) │
│  agent-loop learn         → voku/agent-learning          (findings/proposals/history)│
│  agent-loop workflow      → voku/agent-loop              (governed lifecycle gate)   │
│  agent-loop verify        → voku/agent-loop              (cross-package consistency) │
│  agent-loop memory        → voku/agent-loop              (MEMORY.md promotion review)│
│  agent-loop init          → voku/agent-loop              (setup/scaffold/sync)       │
│                                                                                       │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

Each dependency package has one job:

| Package | Responsibility |
| --- | --- |
| `voku/agent-kanban` | Markdown task board, verification, and optional external-issue-tracker sync |
| `voku/agent-session` | Per-task working memory and session plans |
| `voku/agent-map` | Compact PHP symbol maps for bounded source navigation |
| `voku/agent-recall-compiler` | Task-specific recall/L2 meta-prompt compilation, plus blind-spot and code-review prompts |
| `voku/agent-learning` | Findings, proposals, decision history and guidance evaluation |
| `voku/agent-loop` | Unified CLI, gated workflow orchestration, cross-package verification, memory promotion review, and setup diagnostics |

| Namespace | Status | Purpose | Owning package |
| --- | --- | --- | --- |
| `board` | Stable | Pick work from local Markdown cards; external sync is optional and host-provided | `voku/agent-kanban` |
| `session` | Stable | Working memory for an in-progress task | `voku/agent-session` |
| `map` | Stable | Build and query a compact PHP symbol map before reading broad files | `voku/agent-map` |
| `recall` | Stable | Compile task-scoped context as review artifacts | `voku/agent-recall-compiler` |
| `learn` | Stable | Findings, proposals, and reviewed decision history | `voku/agent-learning` |
| `verify` | Stable | Cross-package consistency check | `voku/agent-loop` |
| `workflow` | Stable | Plan, approve, start, inspect, report, and close governed work | `voku/agent-loop` |
| `board:verify` | Stable | Narrow check of the kanban board source only | `voku/agent-kanban` |
| `memory` | Stable | `MEMORY.md` promotion review | `voku/agent-loop` |
| `review` | Stable | Deterministic blind-spot reports and L2 review prompts | `voku/agent-recall-compiler` |
| `init` | Stable | Setup diagnostics, repo-managed assets, and minimal workflow scaffolding | `voku/agent-loop` |

The table is the current executable surface. `init scaffold` creates only the
local workflow directories, a small `.agent-loop/init.json`, and a `DEMO-1`
board card/task; it never writes over an existing file.

## The loop

A typical workflow looks like this. The gated `workflow` commands are the
preferred entry and exit points; the lower-level package commands they wrap
stay available directly when you need finer control.

Pick the work:

```bash
vendor/bin/agent-loop board summary
vendor/bin/agent-loop board render --lanes=READY,BACKLOG --limit=10
vendor/bin/agent-loop board card show ABC-123
```

Plan and approve the governed task context. Planning starts session working
memory and records a candidate work brief. Approval seals that exact revision
and compiles recall from it:

```bash
vendor/bin/agent-loop workflow plan ABC-123 \
  --by lars \
  --file src/Foo.php \
  --goal "Implement the approved task." \
  --validation "vendor/bin/phpunit tests/FooTest.php"

vendor/bin/agent-loop workflow approve ABC-123 --by lars
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
requires an approved current work brief, recall metadata, a blind-spot review
report, and a passing `agent-loop verify` before it will let a task go to
`done`:

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
board        Local Markdown task board (voku/agent-kanban)
board:verify Board-source-only check (voku/agent-kanban)
session      Per-task working memory (voku/agent-session)
map          Compact PHP symbol map (voku/agent-map)
recall       Recall / L2 meta-prompt compilation (voku/agent-recall-compiler)
review       Deterministic blind-spot and code-review L2 prompts (voku/agent-recall-compiler)
learn        Findings, proposals and learning history (voku/agent-learning)
workflow     Gated plan/approve/start/status/report/close orchestration (voku/agent-loop)
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
vendor/bin/agent-loop board card show ABC-123
```

Reads work items from local Markdown card files under `todo/cards/*.md`
(one file per card), with `todo/board.md` holding board metadata (project
prefix, done count). This works fully standalone — no tracker host,
credentials, or network access required. `todo/jira/` and a root
`TODO.md` remains an optional rendered board projection when present.

Only `board external-sync` talks to an external issue tracker, and only
when the invocation passes `--provider-class=<FQCN>` pointing at your own
`voku\AgentKanban\ExternalIssue\ExternalIssueProvider` implementation (see
"Programmatic usage" below) — nothing is wired in by default. Every other
`board` command works from the local Markdown cards alone.

### Map

```bash
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map query EvidenceValidator
vendor/bin/agent-loop map related EvidenceValidator
```

Builds a compact generated symbol index under `.agent-map/` by default.
Use it to choose the smallest useful source read; it is optional and never
becomes another durable memory store.

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
vendor/bin/agent-loop workflow plan <task-id> --by <actor> --file src/Foo.php --goal "Implement the approved task." --validation "vendor/bin/phpunit tests/FooTest.php"
vendor/bin/agent-loop workflow approve <task-id> --by <actor>
vendor/bin/agent-loop workflow status <task-id>
vendor/bin/agent-loop workflow context <task-id> --max-lines 120 --max-bytes 12000

vendor/bin/agent-loop workflow report <task-id> \
  --changed-file src/Foo.php \
  --format text

vendor/bin/agent-loop workflow close <task-id> --status done
```

`workflow plan` starts or reuses a session and writes a candidate work brief;
it deliberately does not compile recall from unapproved scope. `workflow
approve` records the actor and revision, then compiles recall from that sealed
brief. When a typed board card and/or map index exist, it passes their stable
fact projections too. When `<learning-root>/recall-documents.json` exists, it
also passes that explicit, Git-tracked Skill/ADR manifest; it never scans all
project Markdown files. It automatically uses `infra/doc/agent-learning` (or
the legacy `learning-root`) when one exists; pass `--learning-root` only for a
different location. Its `--file` values become the initial approved scope
unless one or more explicit `--scope` values are supplied. `workflow approve`
records the actor and revision that approved that candidate. A later plan
revision must be approved again.

`workflow start` remains available when a host deliberately needs the lower
level session-plus-recall step without work-brief orchestration.

`workflow status` prints read-only session, recall, and review state.

`workflow context` is the bounded working view for an agent: it reads the
approved brief, session decision/checkpoint titles, selected recall guidance,
required validation, and navigation facts from the recall bundle (falling back
to `.agent-map/` only for legacy outputs). It never recompiles recall or a map, embeds no source body, and
prints `[SKIP]` plus explicit omission counts when an input or budget is absent.

`workflow report` is the bounded handoff view: it reports the current work
brief and approval, supplied changed files that fall outside approved scope,
revision-bound validation evidence, recall outcome state, review state, task-associated
learning counts, and any accepted risk. It is read-only and never runs `git`;
pass each observed path with `--changed-file` (or use `--format json` for CI).

`workflow close` is a gated wrapper around `session close`. It requires an
approved current work brief, passing validation evidence for its exact revision,
recall metadata and explicit outcomes for selected guidance, a blind-spot review
report, an explicit session learning decision, and a passing `agent-loop verify`
before closing a task as done.

Record completion evidence through the session owner; it remains auditable but
does not become durable guidance by itself:

```bash
vendor/bin/agent-loop session validation record <task-id> \
  --brief-revision 2 --command "vendor/bin/phpunit tests/FooTest.php" \
  --status passed --exit-code 0 --duration-ms 1840 --by lars

vendor/bin/agent-loop session learning decide <task-id> \
  --status no_durable_learning --by lars
```

Existing `agent-loop session close` remains unchanged.

Workflow commands do not approve code, do not approve durable learning, and do not call an LLM.

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
vendor/bin/agent-loop init tools
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init sync-skills --agent=codex
```

Diagnoses local setup, prints reviewed install plans (ripgrep, RTK,
Caveman), and syncs repo-managed skills/subagents/hooks into client
target directories. It does not affect workflow close, does not call an
LLM, and does not install remote tools.

`init doctor`/`init status` are read-only and never write files. `init tools`
is the one exception: it probes whether `rg`, `git`, `php`, `composer`, and
`docker` are reachable in `PATH` and whether an `agent-map` index exists,
then caches the result to `.agent-loop/tool-inventory.json` (gitignore this
path) so an agent does not have to re-probe availability at the start of
every session. Re-probes automatically once the cache passes `--max-age`
(default 3600s), or immediately with `--refresh`.

## Installation

```bash
composer require --dev voku/agent-loop
```

| Requirement | Version |
| --- | --- |
| PHP | 8.3 or newer |
| Composer | required |

This installs `voku/agent-kanban`, `voku/agent-session`, `voku/agent-map`,
`voku/agent-recall-compiler`, and `voku/agent-learning` as dependencies
and exposes `vendor/bin/agent-loop`.

## Programmatic usage

Start with the smallest useful loop — one task, one session, one compiled
briefing. The high-level workflow command is preferred for creating and
closing the governed task context:

```bash
# Preferred governed path: plan writes the candidate scope contract, then approval is explicit.
agent-loop workflow plan ABC-123 --by lars --learning-root infra/doc/agent-learning --file src/Foo.php --goal "Implement the approved task." --validation "vendor/bin/phpunit tests/FooTest.php"
agent-loop workflow approve ABC-123 --by lars

# ...do the work...

agent-loop session record ABC-123 --kind decision --title "Keep change scoped" --body "..."
agent-loop session checkpoint ABC-123 --title "Validation" --body "PHPStan passed."
agent-loop review blindspots ABC-123
agent-loop session checkpoint ABC-123 --title "Review" --body "agent-loop review blindspots ABC-123 was checked; human review remains required."
agent-loop verify
agent-loop workflow status ABC-123
agent-loop workflow close ABC-123 --status done
```

The lower-level equivalent of `workflow start` is still available when you
need direct package commands:

```bash
agent-loop session start --task ABC-123 --by lars --base-commit "$(git rev-parse HEAD)"
# -> Started session: 2025-01-15-abc-123

agent-loop recall compile --root infra/doc/agent-learning --task ABC-123 --file src/Foo.php
```

`session start` prints its own generated **session id**
(date-prefixed, e.g. `2025-01-15-abc-123`) on its first line. You don't
need to capture it: `session record`/`checkpoint`/`close`/`claim`/`show`/`brief`
also accept the task id you started the session with — `agent-loop`
resolves it to the matching session id before delegating. The session id
still works directly if you have it (e.g. from a list of multiple
sessions for the same task). Likewise, `recall compile --task ABC-123`
without `--output-dir` writes to `<recall-root>/ABC-123/` automatically
(`RecallOutputRoot::resolve()`: `paths.recall_root` from `.agent-loop/init.json`
if configured, else `infra/doc/agent-learning/recall-output` when that
directory exists, else `recall/`), where `agent-loop verify`'s recall-coverage
check expects to find it; pass `--output-dir` explicitly only to override that
default. See
[`examples/basic-loop`](examples/basic-loop) for this full sequence run
against a tiny fake task with real captured output.

`recall compile` only writes files (`system.md`, `validation-plan.md`,
`recall-log.draft.json`, `meta.json`) under `<recall-root>/<task-id>/`
(see `RecallOutputRoot::resolve()` above); it does not
inject them into a running coding agent itself. After a successful `compile`,
`agent-loop` prints a reminder of this:

```text
[NOTE] Recall artifacts were written for review or harness ingestion.
[ACTION REQUIRED] Pass system.md / validation-plan.md into your agent workflow manually unless your harness consumes them automatically.
```

Whatever drives the agent (a human, an editor integration, or
`voku/housekeeping`) is responsible for reading `system.md` and
`validation-plan.md` and feeding them into the actual prompt/context — that
wiring is host-specific and out of scope for this package. `agent-loop
verify`'s recall check only confirms a briefing was compiled and is not
stale; it cannot confirm anything actually read it.

Add the board once you have more than one task in flight, and the learning
loop once you want findings to survive past a single session:

```bash
agent-loop board next-pull
agent-loop map related Foo
agent-loop learn validate --root infra/doc/agent-learning
agent-loop learn guidance-evaluate --root infra/doc/agent-learning
```

## Exact available commands

Every command below is real and was verified against this repository's
installed dependencies (`composer require`'d versions); none of it is
aspirational. Run `agent-loop <namespace> help` (or `--help`) for a
namespace's own usage.

```bash
agent-loop --help                 # top-level namespaces
agent-loop learn --help           # commands for a namespace
agent-loop recall --help
agent-loop session --help
agent-loop board --help
agent-loop map --help

# board: reads cards from todo/cards/<PREFIX>-N.md (one file per ticket;
# optional todo/board.md sets board metadata). Works standalone; only
# external-sync needs a caller-provided ExternalIssueProvider class.
agent-loop board summary
agent-loop board render --lanes=READY,BACKLOG --limit=10
agent-loop board next-pull
agent-loop board card show ABC-123

# session: working memory for one task
agent-loop session start --task ABC-123 [--by ACTOR] [--base-commit SHA] [--slug S]
agent-loop session claim <id> --by ACTOR [--base-commit SHA] [--force]
agent-loop session checkpoint <id> --title T [--body TEXT]
agent-loop session record <id> --kind decision|assumption --title T [--body TEXT]
agent-loop session close <id> --status done|dropped
agent-loop session list [--status STATUS]
agent-loop session show <id>
agent-loop session prune [--keep-days N] [--status done,dropped] [--dry-run]

# map: compact PHP symbol map for token hygiene
agent-loop map build --paths=src,tests
agent-loop map summary
agent-loop map query EvidenceValidator
agent-loop map related EvidenceValidator
agent-loop map file src/EvidenceValidator.php
agent-loop map changed --base=main
agent-loop map stale
agent-loop map stats

# recall: compile a task-scoped briefing
agent-loop recall compile --root infra/doc/agent-learning --task ABC-123 --file lib/foo.php
agent-loop recall log-outcome --root infra/doc/agent-learning --by lars --commit abc1234

# learn: findings, proposals, decision history
agent-loop learn validate --root infra/doc/agent-learning
agent-loop learn guidance-evaluate --root infra/doc/agent-learning
agent-loop learn proposal-validate --proposal proposals/candidate/proposal.001.json
agent-loop learn proposal-approve --by lars proposals/candidate/proposal.001.json
# (also: prepare, proposal-import, proposal-reject, proposal-mark-applied,
#  constraint-export, constraint-activate, constraint-loop, finding-transition)

# verify: the safety net — see below
agent-loop verify

# memory promotion review
agent-loop memory review --file MEMORY.md

# review: deterministic blind-spot checks and L2 prompts
agent-loop review blindspots <task-id>
agent-loop review code <task-id>
```

## `agent-loop map`: PHP symbol maps for smaller reads

```bash
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map related EvidenceValidator
vendor/bin/agent-loop map file src/EvidenceValidator.php
vendor/bin/agent-loop map changed --base=main
vendor/bin/agent-loop map stale
```

`map` delegates to `voku/agent-map`. It builds and queries a compact PHP
symbol index so agents can find the right files/classes/methods before reading
large file ranges. It does not store source code, call an LLM, own durable
learning, or replace PHPStan.

When called through `agent-loop`, `map build` defaults `--root` and `--out` to
the dispatcher root (`<root>/.agent-map/php-symbols.json`) unless the caller
passes explicit values. Read commands default `--index` to that same root-local
index. All normal `agent-map` options still work:

```bash
vendor/bin/agent-loop map query Service --limit=10 --symbol-limit=5 --method-limit=5
vendor/bin/agent-loop map related EvidenceValidator --format=toon
vendor/bin/agent-loop map build --exclude='~Generated.*\.php$~'
```

Use `map` output to choose the smallest useful next read. Do not dump
`.agent-map/php-symbols.json` into prompts.

`agent-loop board external-sync` is the only `board` command that needs a
caller-provided `ExternalIssueProvider` implementation. The bare binary
does not ship one because tracker clients are host-specific; every other
`board` command works against local Markdown cards without it.

## `agent-loop review blindspots`: deterministic review boundary

```bash
vendor/bin/agent-loop review blindspots <task-id>
```

Run this after implementation validation and before closing the task. It writes
deterministic Markdown/JSON reports plus an L2 blind-spot analysis prompt under
`.agent-recall/reviews/`, using task, session, and recall artifacts from
`voku/agent-recall-compiler` as prompt context. It warns when session notes
do not show that `review blindspots` itself was checked. Review reports and generated prompts do not approve code.
Review reports do not approve durable learning. The CLI does not call an LLM
directly; the generated L2 prompt is for a human or harness to pass to a
receiving LLM. Human review remains required.

### L2 code-review prompt

```bash
vendor/bin/agent-loop review code <task-id>
```

Generates `.agent-recall/reviews/<task-id>.code.prompt.md`, an L2 code-review
prompt focused on purpose mismatch, contracts, invariants, edge cases, security,
and test gaps. This command is delegated to `voku/agent-recall-compiler`;
`agent-loop` only defaults `--output-dir` to `RecallOutputRoot::resolve()`'s
recall root so it fits the standard workflow. The prompt is intended for a
receiving LLM or harness; the
CLI itself does not call an LLM.

## Learning boundary: findings are not durable memory

The workflow/review spine can generate evidence for learning, but it does not
promote durable memory. Findings and learning candidates remain review inputs;
only reviewed decisions become durable guidance. Use
`agent-loop memory review --file MEMORY.md` as the human promotion boundary for
repositories that maintain a `MEMORY.md` queue. See
[`docs/workflow/learning-boundary.md`](docs/workflow/learning-boundary.md) for
the detailed boundary.

## `agent-loop verify`: the safety net

Every other namespace delegates outward and stops there. `verify` is the one
command that looks *across* board, session, recall, and learning state at
once and answers: **is this repo's agent workflow state internally
consistent?**

```bash
agent-loop verify
```

Checks, each of which prints `[OK]`, `[SKIP]`, or `[FAIL]` and skips itself
when its inputs are absent (so the command stays meaningful for a repo that
only wires up part of the stack):

- **package delegates** — board/learn/map/recall/session classes are installed and resolve
- **tasks** — every `*.md` file under `tasks/` parses (non-empty, has a heading)
- **board** — typed kanban board verification (delegated to `voku/agent-kanban`)
- **sessions** — every non-closed session under `session_plan/` points to a known task id
- **recall** — every active session has a compiled briefing, and every
  compiled `meta.json`'s recorded `system.md`/`validation-plan.md` hashes
  still match the files on disk (catches those two edited or regenerated out
  of band; `recall-log.draft.json` and `feedback-assessment.draft.json` are
  excluded from this check since they're meant to be hand-edited after
  compile)
- **learning root** — findings, proposals, and decision/outcome history validate

Run `agent-loop verify --help` for the override flags
(`--tasks-root`, `--sessions-root`, `--recall-root`, `--learning-root`).
`agent-loop board:verify` remains available as the narrower, board-only
check this command used to be.

### `--strict`: turn baseline skips into failures

By default, a missing input is reported as `[SKIP]` and does not fail the
command — useful for a repo that only wires up part of the stack. Pass
`--strict` to fail instead when `tasks/` or `session_plan/` is missing
entirely:

```bash
agent-loop verify --strict
```

`tasks/` and `session_plan/` are the baseline this command exists to
confirm — a task to work on, and a session tracking it. The board and learning
root stay skippable even under `--strict`: both are documented, opt-in
additions on top of that baseline, not something every repo using
`agent-loop` is expected to have set up. [`examples/basic-loop`](examples/basic-loop) fails `--strict` before
step 2 (`session_plan/` doesn't exist yet), then passes it from step 5
onward — the same point where its own `verify` (without `--strict`) already
passes, since by then a session and its recall briefing both exist.

## What `agent-loop` deliberately does not do

> agent-loop is not the learning engine.
> agent-loop is not the session store.
> agent-loop is not the recall compiler.
> agent-loop is the command surface.

Concretely, `agent-loop`:

- holds no working memory of its own — sessions live in `voku/agent-session`'s files, not in this package
- makes no decisions about what counts as a durable lesson — that judgment lives in `voku/agent-learning`
- selects no context for a prompt — selection logic lives in `voku/agent-recall-compiler`
- owns no repository symbol map — map state lives in the generated
  `.agent-map/php-symbols.json` owned by `voku/agent-map`
- owns no board data — board state lives in whatever Markdown/Jira source `voku/agent-kanban` reads
- adds no scheduler, hidden state machine, or plugin lifecycle — `voku/housekeeping` is the runner; this is just the loop

If a feature needs new durable state, it belongs in one of the focused
packages, not in `agent-loop`. The moment this wrapper starts hiding state of
its own, it has become the second source of truth this whole stack was built
to avoid.

## Review boundaries and safety contracts

`agent-loop` coordinates the loop. It does not approve code, approve
learning, or replace human review.

Concretely:

- it does not auto-commit, auto-merge, or push anything — every command it
  runs is the one you typed, with arguments resolved or defaulted as
  documented above, nothing more
- it does not approve code changes — that remains whatever review process
  (human or otherwise) already gates changes outside this tool
- it does not silently promote findings into durable memory. `learn
  proposal-approve --by ACTOR <id>`, `proposal-reject`, and
  `proposal-mark-applied` are `voku/agent-learning`'s own human-actor gate
  (each requires an explicit `--by` actor) on the candidate → approved →
  applied lifecycle; `agent-loop` delegates to that command verbatim and adds
  no auto-approval path of its own
- `agent-loop memory review` is read-only: it reports which `MEMORY.md` rows
  look ready for promotion (see `src/MemoryPromotionAnalyzer.php`); it never
  edits `MEMORY.md` itself. Promotion stays a manual edit by whoever owns
  that file
- `agent-loop verify` only reports `[OK]`/`[SKIP]`/`[FAIL]` on existing
  state; it never repairs drift it finds

If a workflow needs an automated approval or auto-promotion path, that is a
deliberate, separately-reviewed change to the owning package
(`voku/agent-learning` for proposals, the host application for
`MEMORY.md`), not something to add to this wrapper.

## Programmatic use (host wiring)

Hosts that need custom integrations, for example Jira, implement their own
`ExternalIssueProvider` and pass its class to `board external-sync`:

```php
<?php

declare(strict_types=1);

namespace YourApp;

use voku\AgentKanban\ExternalIssue\ExternalIssueProvider;
use voku\AgentKanban\ExternalIssue\ExternalIssueRecord;

final class JiraExternalIssueProvider implements ExternalIssueProvider
{
    public function systemName(): string
    {
        return 'jira';
    }

    /** @return list<ExternalIssueRecord> */
    public function fetchActiveIssues(string $query): array
    {
        // $query is whatever you pass via --query (e.g. a JQL string);
        // connect to your own Jira client here, never to agent-kanban.
        return [];
    }
}
```

```bash
vendor/bin/agent-loop board external-sync \
  --provider-class="YourApp\\JiraExternalIssueProvider" \
  --query="project = ABC AND statusCategory != Done"
```

`voku\AgentKanban\Cli\CliApplication` instantiates `--provider-class` with
a no-argument constructor, so your adapter should read its own
configuration (base URL, token, project key) from environment variables
or your own config file inside its own constructor.

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
