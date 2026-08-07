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

`agent-loop` ships its own reviewed agent behavior. It does not download RTK,
Caveman, Ponytail, a plugin marketplace package, or a remote installer.

Preview and install the assets already present in the Composer package:

```bash
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init install-assets --agent=all
vendor/bin/agent-loop init doctor
```

The package-owned guidance separates three budgets:

1. **Human attention:** progress, handoffs, and review findings stay concise and
   technically exact.
2. **Implementation complexity:** stop at the first verified solution that fully
   satisfies the request.
3. **Context:** use `agent-map` and recall to select bounded source reads.

Raw evidence is never compressed or rewritten. Source, full diffs, test output,
static-analysis output, verification artifacts, redirected harness files, and
decisive errors remain complete.

The first-party set adapts concrete mechanisms reviewed in Caveman and Ponytail:

| Asset | Purpose |
| --- | --- |
| `agent-loop-discipline` | Concise communication + minimal strict-PHP implementation ladder + evidence integrity |
| `agent-loop-investigate` | Read-only `agent-map` locator returning verified path/line/symbol/caller/test evidence |
| `agent-loop-surgical-edit` | Already-localized 1-2 file change; prefer deterministic `agent-loop edit` and refuse silent scope expansion |
| `agent-loop-code-review` | Concise correctness review of the complete raw diff |
| `agent-loop-simplify-review` | Diff-only review for deletion/reuse/stdlib/native/YAGNI opportunities |
| `agent-loop-simplify-audit` | Repo-wide simplify audit, prioritized through map/navigation evidence |
| `agent-loop-dogfood` | Compare observable baseline/candidate artifacts without invented savings |

The package also installs three dedicated roles where the client exposes a
repository-local agent-role format:

```text
investigator -> surgical builder -> code reviewer
```

The investigator locates and stops. The builder edits only already-understood
small scope. The reviewer returns correctness findings only. Broader features
stay in the normal governed workflow.

The same canonical role definitions are rendered for each supported client:

- Codex: `.codex/agents/*.toml`, with `name`, `description`, and
  `developer_instructions` only;
- Claude Code: `.claude/agents/*.md`;
- Copilot: `.github/agents/*.agent.md`;
- Antigravity: `.agents/agents/*.md`.

Model choice remains client/host policy. `agent-loop` does not pin a model,
reasoning level, or provider-specific economics into the role files.

Codex additionally receives package-owned PHP hooks:

- `SessionStart` injects the discipline.
- `SubagentStart` propagates it to spawned agents.
- `PreToolUse` leaves ordinary Bash commands unchanged.
- `PreToolUse` denies configured unbounded `.agent-map` dump patterns and
  suggests bounded map commands.

Hooks are behavioral guardrails, not a correctness or security boundary. The
security property is simpler: `install-assets` installs only files already
shipped in the Composer package and never fetches remote agent code.

## Host-owned hooks for Codex and Claude Code

A host repository keeps its own hook bundle - `hooks.json` plus the PHP scripts
it references - and syncs it into the client:

```bash
vendor/bin/agent-loop init validate --kind=hooks --agent=codex
vendor/bin/agent-loop init sync-hooks --agent=codex

vendor/bin/agent-loop init validate --kind=hooks --agent=claude
vendor/bin/agent-loop init sync-hooks --agent=claude
```

Source roots default to `docs/agents/codex-hooks` and `docs/agents/claude-hooks`
and are overridable through `.agent-loop/init.json` (`codex_hooks_root`,
`claude_hooks_root`) or `--hooks-root`.

The two clients register hooks differently, and the sync follows each:

- **Codex** reads `.codex/hooks.json`, so the bundle is copied as-is.
- **Claude Code** reads the `hooks` key of `.claude/settings.json`, so the sync
  merges that single key and writes every other setting back unchanged. The
  manifest entry `settings.json#hooks` records that one key as managed, which is
  what makes a later removal or an `--adopt-existing` run safe. `CLAUDE_CONFIG_DIR`
  overrides the target directory.

Hook commands must call a script inside the client's own directory
(`php .codex/hooks/<name>.php`, `php .claude/hooks/<name>.php`); validation
rejects anything else so a bundle cannot point at an unmanaged path.

## Package-owned Git hooks

`post-merge` and `post-checkout` keep the agent-map index in step with the working
tree after Git moved it. That logic is the same in every project, so it ships here:

```bash
vendor/bin/agent-loop init sync-githooks \
  --hooks-dir=.githooks \
  --commit-template=.gitmessage \
  --container-service=php --container-image=my-php \
  --container-workdir=/var/www/html --container-user=www-data
```

That installs `post-merge`, `post-checkout`, `agent-map-refresh.sh`, and the shared
`lib/agent-loop-hooks.sh`, renders the project-specific values into
`lib/agent-loop-hooks.env`, and sets `core.hooksPath` plus `commit.template`
(`--skip-git-config` leaves the repository configuration alone).

`pre-commit` and `commit-msg` are installed as well. They carry no policy: both call
`agent-loop githooks`, which reads `.agent-loop/githooks.json`:

```json
{
  "pre_commit": {
    "file_patterns": ["*.php"],
    "exclude_paths": ["/vendor/", "/archiv/"],
    "batch_size": 500,
    "checks": [
      {"name": "lint", "type": "php-lint"},
      {"name": "sniffer", "type": "phpcs", "standard": "build/phpcs.xml"},
      {"name": "phpstan", "type": "phpstan", "config": "phpstan.neon", "level": 8},
      {"name": "project rule", "type": "command", "command": "tools/custom-check.php {files}"}
    ]
  },
  "commit_msg": {
    "header_pattern": "/^\\[(\\+|~|!|\\*)\\]:\\s+.+\\s+->\\s+.+$/",
    "header_hint": "[SYMBOL]: \"scope\" -> <subject>",
    "trivial_header_pattern": "/^\\[\\*\\]:/",
    "required_section": "WHY",
    "vague_phrases": ["cleanup", "minor fixes"],
    "vague_word_threshold": 8
  }
}
```

Check types render the standard tool call from the package - `php-lint`, `phpcs`,
`phpcbf`, `php-cs-fixer`, `phpstan` - so a project configures its rule set rather
than another wrapper script, and `type: command` covers everything else.
`{files}` is replaced with the current batch of staged files (appended when the
placeholder is absent). Without that config file both hooks are a no-op, so
installing them cannot break a repository that has not configured them yet.

Any other hook in the same directory - `prepare-commit-msg`, `pre-push`, whatever a
project already has - is never read, rewritten, or removed. Only the installed
entries enter the target manifest, so a later sync cleans up exactly what it created.

The hooks never fail a Git operation and never build an index that does not exist
yet: a missing index means nobody opted into the map, and a cold build is not
something a hook starts on someone's behalf. Opt out entirely with
`AGENT_LOOP_SKIP_MAP_REFRESH=1`.

## Make targets for a host repository

Host repositories should not re-declare one Make wrapper per client. Include the
shipped targets instead:

```make
-include vendor/voku/agent-loop/make/agent-loop.mk
```

That provides `agent_init_doctor`, `agent_init_status`, `agent_init_install_plan`,
`validate_agent_skills`, `validate_agent_subagents`, `validate_codex_hooks`,
`validate_claude_hooks`, the per-client `install_*_skills` / `install_*_agents` /
`install_*_hooks` targets, and the aggregates `install_agent_skills`,
`install_agent_subagents`, `install_agent_hooks`, `install_agent_assets`.

A host with its own entrypoint (extra bootstrap, raised memory limit, container
dispatch) overrides one variable and keeps every target:

```make
AGENT_LOOP_BIN := php -d memory_limit=4G tools/agent-loop-entrypoint.php
```

`AGENT_LOOP_CONFIG` (default `.agent-loop/init.json`) and `AGENT_LOOP_SYNC_FLAGS`
(default `--force`) are overridable the same way. The asset content stays in the
host repository; only the commands live here.

`--agent=all` installs portable skills for Codex, Claude, Copilot, and
Antigravity; dedicated subagent definitions for Codex, Copilot, and Antigravity;
and Codex hooks. The exact upstream-to-agent-* mapping and what was deliberately
not ported are documented in
[THIRD_PARTY_NOTICES.md](docs/agents/THIRD_PARTY_NOTICES.md).

The implementation and failed iterations are documented in
[the dogfood report](docs/agents/dogfood/2026-08-07-first-party-discipline.md).

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

When a deliberately minimal implementation has a known ceiling, record the
ceiling and an observable revisit trigger in `agent-session` instead of leaving a
tool-specific debt marker in product code.

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
| `voku/agent-session` | Per-task working memory, decisions, assumptions, checkpoints, simplification ceilings, and validation evidence |
| `voku/agent-map` | Compact PHP symbol maps and bounded source navigation |
| `voku/agent-recall-compiler` | Task-scoped recall, validation plans, and deterministic review prompts |
| `voku/agent-learning` | Findings, proposals, decision history, constraints, and reviewed guidance maintenance |
| `voku/agent-loop` | Unified CLI, edit orchestration, governed lifecycle gates, first-party agent assets, memory review, and repository setup |

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
- rewrite durable memory files merely to shorten them;
- turn every transcript or observation into memory;
- invent per-repo token or line savings from an unbuilt counterfactual;
- hide source or verification evidence behind a lossy summary.

Findings are not durable memory. Generated map output is not source evidence.
Model confidence is not validation. A green close requires the recorded gates,
not an agent claiming that everything looks fine.

See [Learning boundary](docs/workflow/learning-boundary.md) and
[Lifecycle contract](docs/agents/LIFECYCLE.md).

## Repository-managed assets

Use `install-assets` for the immutable defaults shipped with this package:

```bash
vendor/bin/agent-loop init install-assets --agent=all
```

`install-assets` is intentionally configuration-free: host configuration cannot
replace its package-owned skills, roles, or hooks. Use `sync-*` when a host
repository owns customized canonical assets:

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init sync-subagents --agent=codex --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
```

Both paths use managed-entry manifests and refuse to overwrite unmanaged targets
unless `--force` or `--adopt-existing` is explicit.

Detailed asset behavior is documented in
[Agent Assets In agent-loop](docs/agents/INFO_Agents.md).

## Dogfood and validation

The first-party behavior is tested at three levels:

1. PHPUnit covers typed hook semantics and offline asset installation.
2. `composer dogfood:discipline` executes the packaged discipline and hooks in
   an isolated workspace.
3. GitHub Actions installs `agent-loop` as a non-symlinked Composer dependency,
   runs `init install-assets --agent=all`, verifies the installed skills/roles/
   hooks, and reruns the dogfood gate from the installed package.

Guidance changes also require an observable behavioral comparison. A green
installer or hook test alone does not prove that agents became easier to review,
more selective in context, or less likely to add unrequested work.

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
- [Upstream mechanism mapping and notices](docs/agents/THIRD_PARTY_NOTICES.md)

## Scheduled execution

`agent-loop` is the workflow CLI, not a scheduler. Use a conservative runner such
as [`voku/housekeeping`](https://github.com/voku/housekeeping) when selected
maintenance commands need cron or another scheduler.

Agents may suggest. Humans approve.

## License

MIT. See [LICENSE](LICENSE).
