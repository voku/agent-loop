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

`agent-loop` exists only to remove that friction: one binary, one stable
command vocabulary, zero shared state of its own.

## Package map

```text
                ┌──────────────────────────── voku/agent-loop ───────────────────────────┐
  agent-loop →  │  board    →  voku/agent-kanban           (TODO Kanban board + Jira sync) │
                │  verify   →  voku/agent-loop              (cross-package consistency)    │
                │  board:verify → voku/agent-kanban         (TodoBoardVerifier, board only) │
                │  session  →  voku/agent-session           (working memory per task)      │
                │  recall   →  voku/agent-recall-compiler   (L2 meta-prompt compilation)    │
                │  learn    →  voku/agent-learning          (findings → proposals → history)│
                │  memory   →  voku/agent-loop               (MEMORY.md promotion review)    │
                └─────────────────────────────────────────────────────────────────────────┘
```

| Namespace | Purpose | Owning package |
| --- | --- | --- |
| `board` | Pick work from a Markdown/Jira Kanban board | `voku/agent-kanban` |
| `session` | Working memory for an in-progress task | `voku/agent-session` |
| `recall` | Compile task-scoped context (L2 meta-prompt) | `voku/agent-recall-compiler` |
| `learn` | Findings → proposals → reviewed decision history | `voku/agent-learning` |
| `verify` | Cross-package consistency check (the only thing that looks at all of the above at once) | `voku/agent-loop` |
| `board:verify` | Narrow check of the kanban board source only | `voku/agent-kanban` |
| `memory` | `MEMORY.md` promotion review | `voku/agent-loop` |

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
briefing:

```bash
agent-loop session start --task ABC-123 --by lars --base-commit "$(git rev-parse HEAD)"
agent-loop recall compile --root infra/doc/agent-learning --task ABC-123 --file src/Foo.php

# ...do the work...

agent-loop session record ABC-123 --kind decision --title "Keep change scoped" --body "..."
agent-loop session checkpoint ABC-123 --title "Validation" --body "PHPStan passed."
agent-loop verify
agent-loop session close ABC-123 --status done
```

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
namespace's own usage — `board` is the one exception noted below.

```bash
agent-loop --help                 # top-level namespaces
agent-loop learn --help           # commands for a namespace
agent-loop recall --help
agent-loop session --help

# board (requires a TODO.md board source under the working directory; the
# upstream package treats `--help`/`help` as an unknown subcommand, so use
# `agent-loop board` with no arguments to see its usage instead)
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
```

`agent-loop board jira-sync` needs a `JiraIssueProvider`. The bare binary
does not wire one (Jira clients are host-specific) — see "Programmatic use"
below.

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
briefing goes missing or gets edited out of band.

## License

MIT — see [LICENSE](LICENSE).
