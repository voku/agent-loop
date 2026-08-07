# Agent Loop

A governed coding-agent workflow for PHP repositories.

`voku/agent-loop` gives coding agents a controlled loop instead of more hidden
autonomy:

```text
find the right code
  -> approve the task scope
  -> make the smallest correct change
  -> validate with evidence
  -> review blind spots
  -> decide what should be learned
  -> close only when the gates pass
```

It is local-first, auditable through files and Git, independent of a specific
LLM provider, and built around focused `agent-*` packages rather than one large
agent platform.

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

## Start a repository

Create the minimum local workflow structure and a clearly marked example task:

```bash
vendor/bin/agent-loop init scaffold
vendor/bin/agent-loop board card show DEMO-1
```

See [Your first governed task](docs/quick-start.md) for the complete first run.

## First-party agent discipline

`agent-loop` ships its own reviewed skills and PHP hooks. It does not download
RTK, Caveman, Ponytail, a plugin marketplace package, or a remote installer.

Preview and install the assets already present in the Composer package:

```bash
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
vendor/bin/agent-loop init install-assets --agent=codex
vendor/bin/agent-loop init doctor
```

The package-owned guidance separates three budgets:

1. **Human attention:** progress and final replies stay concise and factual.
2. **Implementation complexity:** stop at the first verified solution that fully
   satisfies the request.
3. **Context:** use `agent-map` and recall to select bounded source reads.

Raw evidence is never compressed or rewritten. Source, full diffs, test output,
static-analysis output, verification artifacts, redirected harness files, and
decisive errors remain complete.

Bundled skills:

| Skill | Purpose |
| --- | --- |
| `agent-loop-discipline` | Map-first navigation, minimal strict PHP changes, concise communication, and evidence integrity |
| `agent-loop-simplify-review` | Review the complete raw diff for avoidable complexity without replacing correctness or security review |
| `agent-loop-dogfood` | Compare stable baseline and candidate runs through observable artifacts |

Codex also receives package-owned PHP hooks installed into the repository:

- `SessionStart` injects the discipline.
- `SubagentStart` propagates it to spawned agents.
- `PreToolUse` leaves ordinary Bash commands unchanged.
- `PreToolUse` redirects unbounded generated `.agent-map` dumps toward bounded
  map commands.

Hooks are behavioral guardrails, not a correctness or security boundary. The
security property is simpler: `install-assets` installs only files already
shipped in the Composer package and never fetches remote agent code.

Other supported clients receive the portable skills. Use `--agent=all` to sync
them for Codex, Claude, Copilot, and Antigravity.

The implementation and its failed iterations are documented in
[the dogfood report](docs/agents/dogfood/2026-08-07-first-party-discipline.md).
Upstream inspiration and MIT attribution live in
[THIRD_PARTY_NOTICES.md](docs/agents/THIRD_PARTY_NOTICES.md); they are not runtime
dependencies.

## Exact target edit

For a method-scoped change, let `agent-loop` resolve the symbol, build or refresh
the semantic map, compile bounded recall, and prepare an auditable execution
bundle:

```bash
vendor/bin/agent-loop edit 'App\Service\UserService::save' -- \
  'Reject inactive users before persistence and adapt affected callers.'
```

The default `stdout` runner writes the bundle but launches no external agent.
Artifacts are stored under:

```text
.agent-loop/edit/<task-id>/
```

For a deterministic one-for-one replacement inside one resolved PHP method:

```bash
vendor/bin/agent-loop edit 'Legacy\ResourceService::save' \
  --runner=auto \
  --replace-old='$legacyUser->regionId' \
  --replace-new='$legacyUser->getCurrentRegionId()' -- \
  'Replace the deprecated region property exactly once.'
```

The mechanical runner requires exactly one match inside the target method,
checks map freshness immediately before writing, runs `php -l`, and reverts on
lint failure. It does not invoke a model.

Verify an execution bundle separately:

```bash
vendor/bin/agent-loop edit verify \
  --bundle=.agent-loop/edit/<task-id> \
  --run-commands
```

`edit verify` checks one concrete edit execution. `agent-loop verify` checks the
cross-package workflow state. They are intentionally different gates.

## Governed task workflow

Plan the exact scope and validation contract:

```bash
vendor/bin/agent-loop workflow plan ABC-123 \
  --by lars \
  --file src/Foo.php \
  --goal 'Implement the approved task.' \
  --behavior-anchor 'request -> Foo service -> persisted state' \
  --validation 'vendor/bin/phpunit tests/FooTest.php'
```

Approve that exact revision through a named human actor:

```bash
vendor/bin/agent-loop workflow approve ABC-123 --by lars
```

Build navigation state and render the bounded working context:

```bash
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map query Foo
vendor/bin/agent-loop map related Foo
vendor/bin/agent-loop workflow context ABC-123 \
  --max-lines 120 \
  --max-bytes 12000
```

After implementation, record the exact validation result:

```bash
vendor/bin/agent-loop session validation record ABC-123 \
  --brief-revision 1 \
  --command 'vendor/bin/phpunit tests/FooTest.php' \
  --status passed \
  --exit-code 0 \
  --by lars
```

Review and verify:

```bash
vendor/bin/agent-loop review blindspots ABC-123
vendor/bin/agent-loop review code ABC-123
vendor/bin/agent-loop verify --task-id=ABC-123
vendor/bin/agent-loop workflow report ABC-123 \
  --changed-file src/Foo.php
```

Record the learning outcome and close only when every required gate passes:

```bash
vendor/bin/agent-loop session learning decide ABC-123 \
  --status no_durable_learning \
  --by lars \
  --reason 'No reusable finding from this bounded task.'

vendor/bin/agent-loop workflow close ABC-123 --status done
```

A re-plan creates a new brief revision. Approval and validation evidence from an
older revision remain auditable but cannot satisfy the revised task.

## agent-map: navigate before reading broadly

Use the generated semantic map to locate relevant source and callers:

```bash
vendor/bin/agent-loop map query EvidenceValidator
vendor/bin/agent-loop map related EvidenceValidator
vendor/bin/agent-loop map file src/EvidenceValidator.php
vendor/bin/agent-loop map changed --base=main
vendor/bin/agent-loop map stats
```

The generated `.agent-map` files are disposable navigation state. Do not paste
`php-symbols.json` or `search.sqlite` into a prompt. Use map results to select the
smallest real source range, then inspect that source directly.

## What each package owns

| Package | Responsibility |
| --- | --- |
| `voku/agent-kanban` | Git-native Markdown task board and optional external issue comparison |
| `voku/agent-session` | Per-task working memory, decisions, assumptions, checkpoints, and validation evidence |
| `voku/agent-map` | Compact PHP symbol maps and bounded source navigation |
| `voku/agent-recall-compiler` | Task-scoped recall, validation plans, and deterministic review prompts |
| `voku/agent-learning` | Findings, proposals, decision history, constraints, and reviewed guidance maintenance |
| `voku/agent-loop` | Unified CLI, edit orchestration, governed lifecycle gates, memory review, and repository setup |

`agent-loop` does not become a second store for board, session, map, recall, or
learning state. It orchestrates the focused packages and verifies their shared
contract.

## CLI namespaces

```text
edit         exact target -> semantic map -> bounded recall -> execution bundle
board        local Markdown task board
board:verify board-source-only verification
session      per-task working memory and validation evidence
map          compact PHP symbol navigation
recall       task-scoped recall / L2 briefing compilation
review       deterministic blind-spot and code-review prompts
learn        findings, proposals, constraints, and guidance maintenance
workflow     plan / approve / start / context / status / report / close
verify       cross-package workflow consistency
memory       read-only durable-memory promotion review
init         diagnostics, offline asset installation, sync, and scaffolding
```

Use the command itself as the executable reference:

```bash
vendor/bin/agent-loop help
vendor/bin/agent-loop <namespace> help
```

## Boundaries

`agent-loop` deliberately does not:

- call an LLM by itself;
- auto-commit, auto-push, or auto-merge;
- silently approve code or durable learning;
- inject recall artifacts into an agent without the host doing so explicitly;
- replace tests, PHPStan, code review, or human approval;
- treat hooks as a correctness or security sandbox;
- turn every transcript or observation into memory;
- hide source or verification evidence behind a lossy summary.

Findings are not durable memory. Generated map output is not source evidence.
Model confidence is not validation. A green close requires the recorded gates,
not an agent claiming that everything looks fine.

See [Learning boundary](docs/workflow/learning-boundary.md) and
[Lifecycle contract](docs/agents/LIFECYCLE.md).

## Repository-managed assets

Use `install-assets` for the immutable defaults shipped with this package:

```bash
vendor/bin/agent-loop init install-assets --agent=codex
```

`install-assets` is intentionally configuration-free: host configuration cannot
replace its source skills or hooks. Use `sync-*` when a host repository owns
customized canonical assets:

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init sync-subagents --agent=copilot --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
```

Both paths use managed-entry manifests and refuse to overwrite unmanaged targets
unless `--force` or `--adopt-existing` is explicit.

Detailed asset behavior is documented in
[Agent Assets In agent-loop](docs/agents/INFO_Agents.md).

## Dogfood and validation

The first-party discipline is tested at three levels:

1. PHPUnit covers typed hook semantics and offline installation.
2. `composer dogfood:discipline` executes the packaged skills and hooks in an
   isolated workspace.
3. GitHub Actions installs `agent-loop` as a non-symlinked Composer dependency,
   installs the bundled assets from `vendor/`, and reruns the dogfood gate from
   the installed package.

Guidance changes also require an observable behavioral comparison. A green
installer or hook test alone does not prove that agents became easier to review
or less likely to add unrequested work.

Local commands:

```bash
composer install
composer test
composer phpstan
composer dogfood:discipline
composer ci
```

`composer ci` runs:

```text
composer validate --strict
phpunit
phpstan
php tools/agent-discipline-dogfood.php
```

Never report a command as passed unless it ran and its exit code was observed.

## Documentation

- [Your first governed task](docs/quick-start.md)
- [Agent asset and offline installation contract](docs/agents/INFO_Agents.md)
- [Cross-package lifecycle](docs/agents/LIFECYCLE.md)
- [Learning and durable-memory boundary](docs/workflow/learning-boundary.md)
- [First-party discipline dogfood report](docs/agents/dogfood/2026-08-07-first-party-discipline.md)
- [Third-party inspiration notices](docs/agents/THIRD_PARTY_NOTICES.md)

## Scheduled execution

`agent-loop` is the workflow CLI, not a scheduler. Use a conservative runner such
as [`voku/housekeeping`](https://github.com/voku/housekeeping) when selected
maintenance commands need cron or another scheduler.

Agents may suggest. Humans approve.

## License

MIT. See [LICENSE](LICENSE).
