# Agent Loop (`voku/agent-loop`)

`agent-loop` is one boring CLI for coding-agent DX. It does not think, decide,
or remember anything itself — it routes a stable set of commands to the
packages that do.

## The problem it solves

A realistic coding-agent workflow needs a board to pick work from, a session
to hold working memory while a task is in progress, a compiler that selects
relevant context instead of dumping everything into the prompt, and a
learning loop that turns findings into durable rules without overreacting to
noise. Each of those is its own focused package. Wiring them by hand means a
different `vendor/bin/...` invocation per concern, which nobody — human or
agent — keeps straight across a long session.

`agent-loop` exists only to remove that friction: one Composer-installed PHP CLI,
one stable command vocabulary, zero shared state of its own.

## Package map

```text
                ┌───────────────────────────────── voku/agent-loop ──────────────────────────────────┐
  agent-loop →  │  board         →  voku/agent-kanban           (local Markdown board, Jira optional)│
                │  verify        →  voku/agent-loop             (cross-package consistency)          │
                │  workflow      →  voku/agent-loop             (start/status/close orchestration)    │
                │  board:verify  →  voku/agent-kanban           (TodoBoardVerifier, board only)      │
                │  session       →  voku/agent-session          (working memory per task)            │
                │  recall        →  voku/agent-recall-compiler  (L2 meta-prompt compilation)         │
                │  learn         →  voku/agent-learning         (findings → proposals → history)     │
                │  review        →  voku/agent-recall-compiler  (blind-spot reports + L2 prompts)    │
                │  memory        →  voku/agent-loop             (MEMORY.md promotion review)         │
                └────────────────────────────────────────────────────────────────────────────────────┘
```

| Namespace | Purpose | Owning package |
| --- | --- | --- |
| `board` | Pick work from local Markdown cards (`todo/cards/*.md`); Jira sync is optional and host-wired | `voku/agent-kanban` |
| `session` | Working memory for an in-progress task | `voku/agent-session` |
| `recall` | Compile task-scoped context (L2 meta-prompt) as review artifacts — not auto-injected into any agent | `voku/agent-recall-compiler` |
| `learn` | Findings → proposals → reviewed decision history | `voku/agent-learning` |
| `verify` | Cross-package consistency check (the only thing that looks at all of the above at once) | `voku/agent-loop` |
| `workflow` | Start, inspect, and close a governed task workflow | `voku/agent-loop` |
| `board:verify` | Narrow check of the kanban board source only | `voku/agent-kanban` |
| `memory` | `MEMORY.md` promotion review | `voku/agent-loop` |
| `review` | Deterministic blind-spot reports and L2 review prompts | `voku/agent-recall-compiler` |

### Board: local Markdown first, Jira sync optional

`board` reads work items from local Markdown card files under
`todo/cards/*.md` (one file per card), with `todo/board.md` holding board
metadata (project prefix, done count). This works fully standalone — no
Jira host, credentials, or network access required. `todo/cards/*.md` is
the preferred local card path. `todo/jira/` and root `TODO.md` remain
supported fallback inputs: `voku/agent-kanban` checks `todo/cards/` first,
falls back to `todo/jira/`, and then falls back further to reading a
single `TODO.md` at the project root (`voku/agent-kanban`'s own fallback,
not something `agent-loop` adds).

Only `board jira-sync` talks to Jira, and only once the host application
constructs the `Dispatcher` with its own `JiraIssueProvider` (see
"Programmatic use" below) — the bare `bin/agent-loop` wires none. Every
other `board` command (`summary`, `render`, `lane`, `next-pull`,
`ticket`, `context`, `brief`) works from the local Markdown cards alone.


## `agent-loop workflow`: start, inspect, and close a governed task

```bash
vendor/bin/agent-loop workflow start <task-id> \
  --by <actor> \
  --learning-root infra/doc/agent-learning \
  --file src/Foo.php

vendor/bin/agent-loop workflow status <task-id>

vendor/bin/agent-loop workflow close <task-id> --status done
```

`workflow start` wraps `session start` and `recall compile`.

`workflow status` prints read-only session, recall, and review state.

`workflow close` is a gated wrapper around `session close`. It requires recall metadata, a blind-spot review report, and a passing `agent-loop verify` before closing a task as done.

Existing `agent-loop session close` remains unchanged.

Workflow commands do not approve code, do not approve durable learning, and do not call an LLM.

Accepted risk is explicit and written to disk:

```bash
vendor/bin/agent-loop workflow close <task-id> \
  --status done \
  --accept-risk "Manual review by Lars for urgent legacy hotfix."
```

Accepted risk writes `.agent-loop/risks/<task-id>.accepted-risk.md`.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | 8.3 or newer |
| Composer | required |

## Installation

```bash
composer require voku/agent-loop
```

This installs `voku/agent-kanban`, `voku/agent-session`,
`voku/agent-recall-compiler`, and `voku/agent-learning` as dependencies and
exposes `vendor/bin/agent-loop`.

## Basic workflow

Start with the smallest useful loop — one task, one session, one compiled
briefing. The high-level workflow command is preferred for creating and
closing the governed task context:

```bash
agent-loop workflow start ABC-123 --by lars --learning-root infra/doc/agent-learning --file src/Foo.php

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
need to capture it: `session record`/`checkpoint`/`close`/`claim`/`show`
also accept the task id you started the session with — `agent-loop`
resolves it to the matching session id before delegating. The session id
still works directly if you have it (e.g. from a list of multiple
sessions for the same task). Likewise, `recall compile --task ABC-123`
without `--output-dir` writes to `recall/ABC-123/` automatically, where
`agent-loop verify`'s recall-coverage check expects to find it; pass
`--output-dir` explicitly only to override that default. See
[`examples/basic-loop`](examples/basic-loop) for this full sequence run
against a tiny fake task with real captured output.

`recall compile` only writes files (`system.md`, `validation-plan.md`,
`recall-log.draft.json`, `meta.json`) under `recall/<task-id>/`; it does not
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

# board: reads cards from todo/cards/<PREFIX>-N.md (one file per ticket;
# optional todo/board.md sets the project prefix and done count). Works
# standalone, no Jira connection needed. todo/jira/ also still works as
# a fallback for boards that already use it. Falls back further to a
# single TODO.md fallback only if neither card directory exists. Only
# `board jira-sync` needs a host-wired JiraIssueProvider.
agent-loop board summary
agent-loop board render --lanes=READY,BACKLOG --limit=10
agent-loop board next-pull
agent-loop board ticket ABC-123

# session: working memory for one task
agent-loop session start --task ABC-123 [--by ACTOR] [--base-commit SHA] [--slug S]
agent-loop session claim <id> --by ACTOR [--base-commit SHA] [--force]
agent-loop session checkpoint <id> --title T [--body TEXT]
agent-loop session record <id> --kind decision|assumption --title T [--body TEXT]
agent-loop session close <id> --status done|dropped
agent-loop session list [--status STATUS]
agent-loop session show <id>
agent-loop session prune [--keep-days N] [--status done,dropped] [--dry-run]

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

`agent-loop board jira-sync` needs a `JiraIssueProvider`; it is the only
`board` command that does. The bare binary does not wire one (Jira clients
are host-specific) — see "Programmatic use" below. Every other `board`
command works against the local Markdown cards without it.

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
`agent-loop` only defaults `--output-dir` to `recall/<task-id>` so it fits the
standard workflow. The prompt is intended for a receiving LLM or harness; the
CLI itself does not call an LLM.

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

- **package delegates** — board/learn/recall/session classes are installed and resolve
- **tasks** — every `*.md` file under `tasks/` parses (non-empty, has a heading)
- **board** — `TODO.md` kanban board projection (delegated to `voku/agent-kanban`)
- **sessions** — every non-closed session under `session_plan/` points to a known task id
- **recall** — every active session has a compiled briefing, and every
  `recall/<task>/meta.json` output hash still matches the file on disk
  (catches a briefing edited or regenerated out of band)
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
confirm — a task to work on, and a session tracking it. `board` (`TODO.md`)
and the learning root stay skippable even under `--strict`: both are
documented, opt-in additions on top of that baseline (see "Board: local
Markdown first, Jira sync optional" above, and the learning loop in "Basic
workflow"), not something every repo using `agent-loop` is expected to have
set up. [`examples/basic-loop`](examples/basic-loop) fails `--strict` before
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

Hosts that already have a Jira client wire it once and reuse the whole CLI:

```php
use voku\AgentKanban\JiraIssueProvider;
use voku\AgentLoop\Dispatcher;

$provider = new class implements JiraIssueProvider {
    public function projectKey(): string { /* ... */ }
    public function searchIssues(string $jql): array { /* ... */ }
};

exit((new Dispatcher($rootPath, $provider))->run($argv));
```

That single wrapper replaces per-library glue scripts: every
`board`/`verify`/`session`/`recall`/`learn`/`memory` command flows through it.

## Auto-running it on a schedule

`voku/agent-loop` is the *loop*; [`voku/housekeeping`](https://github.com/voku/housekeeping)
is the *runner*. Install Housekeeping in its own checkout, point it at your
target repository, and let it invoke `agent-loop` commands (board refinement,
verification, recall, …) from cron in safe patch mode.

## Development

```bash
composer install
composer ci    # composer validate --strict + phpunit + phpstan (level 8)
```

`tests/fixtures/basic-loop` is a minimal end-to-end fixture
(`SmokeLoopTest`) proving the orchestration shape: a task file exists, a
session starts against it, recall compiles a briefing, learn validates the
root, and `agent-loop verify` reports no drift — then fails on purpose once a
briefing goes missing or gets edited out of band. [`examples/basic-loop`](examples/basic-loop)
walks through the same shape by hand, with real command output, for reading
or running yourself.

## License

MIT — see [LICENSE](LICENSE).
