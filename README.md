# Agent Loop (`voku/agent-loop`)

Umbrella package and **one unified CLI** for the governed agentic-coding loop.

Instead of wiring three separate libraries and a pile of glue scripts into every
repository, `voku/agent-loop` pulls them together behind a single `agent-loop`
binary and a single programmatic entrypoint (`voku\AgentLoop\Dispatcher`).

```
                ┌──────────────────────────── voku/agent-loop ───────────────────────────┐
  agent-loop →  │  board   →  voku/agent-kanban           (TODO Kanban board + Jira sync)  │
                │  verify  →  voku/agent-kanban           (TodoBoardVerifier)              │
                │  learn   →  voku/agent-learning         (findings → proposals → history) │
                │  recall  →  voku/agent-recall-compiler  (L2 meta-prompt compilation)     │
                │  memory  →  voku/agent-loop             (MEMORY.md promotion review)      │
                └─────────────────────────────────────────────────────────────────────────┘
```

## The loop

1. **board** — pick work from a Markdown/Jira Kanban board.
2. **recall** — compile the approved learnings relevant to the files you will touch into a scoped L2 meta-prompt before editing.
3. *…do the work…*
4. **learn** — capture findings, raise proposals, and keep an auditable decision history.
5. **recall log-outcome** / **learn guidance-evaluate** — close the session and promote durable guidance.
6. **memory** — review which archived notes still need a promotion home.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | 8.3 or newer |
| Composer | required |

## Installation

```bash
composer require voku/agent-loop
```

This installs `voku/agent-kanban`, `voku/agent-learning`, and
`voku/agent-recall-compiler` as dependencies and exposes `vendor/bin/agent-loop`.

## CLI

```bash
agent-loop help            # top-level namespaces
agent-loop learn help      # commands for a namespace
agent-loop recall help

# board (runs against the current working directory)
agent-loop board summary
agent-loop board render --lanes=READY,BACKLOG --limit=10
agent-loop board ticket ABC-123
agent-loop verify

# learning loop
agent-loop learn validate --root infra/doc/agent-learning
agent-loop recall compile --root infra/doc/agent-learning --task ABC-123 --file lib/foo.php
agent-loop recall log-outcome --root infra/doc/agent-learning --by lars --commit abc1234

# memory promotion review
agent-loop memory review --file MEMORY.md
```

`agent-loop board jira-sync` needs a `JiraIssueProvider`. The bare binary does
not wire one (Jira clients are host-specific). To enable it, construct the
`Dispatcher` yourself and inject your provider.

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

That single wrapper replaces the previous per-library glue scripts: every
`board`/`verify`/`learn`/`recall`/`memory` command flows through it.

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

## License

MIT — see [LICENSE](LICENSE).
