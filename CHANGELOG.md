# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### 0.6.2 - 2026-07-23

- `workflow plan` accepts optional, repeatable `--tag LABEL`, forwarded to
  `agent-session brief create`/`revise`. Tags flow through the approved work
  brief into recall compilation unchanged, so a task can be tied to
  cross-cutting learnings/documents (e.g. `identity`, `ldap`) that live under
  a directory unrelated to the changed files. Requires
  `voku/agent-recall-compiler` `^0.6.6` for tag-aware fact selection.

## 0.6.1 - 2026-07-22

### Added

- `workflow approve` now forwards an opt-in, Git-tracked
  `<learning-root>/recall-documents.json` manifest to the recall compiler. The
  manifest is the project policy for scoped Skill/ADR facts; workflow never
  scans all Markdown files.
- When a typed board card exists, approval writes a small revision-pinned
  Kanban context projection beside the approved session brief. `workflow
  context` renders its title, lane/status, and next action from compiled facts
  without reparsing board Markdown.

### Changed

- `workflow plan` creates or revises a candidate work brief only. `workflow
  approve` compiles recall from the approved revision, preventing unapproved
  file lists from becoming a task briefing.
- Existing `.agent-map` indices now pass the host project root into recall, so
  an index built in Docker can be freshness-checked on the host instead of
  treating `/var/www/html` as the host checkout.
- Requires `voku/agent-recall-compiler` `^0.6.5`, which provides canonical
  recall bundles, scoped document facts, board projections, and map-root
  validation.

## 0.6.0 - 2026-07-20

- Added `agent-loop init tools [--refresh] [--max-age=SECONDS] [--cache=PATH]`,
  which probes whether `rg`, `git`, `php`, `composer`, and `docker` are
  reachable in `PATH` and whether an `agent-map` index exists, then caches
  the result (default `.agent-loop/tool-inventory.json`, gitignore this path)
  so agents don't have to re-probe availability at the start of every
  session. Unlike `init doctor`/`init status`, which stay read-only, this
  command's whole purpose is to write that cache file; it re-probes once the
  cache exceeds `--max-age` (default 3600s) or immediately with `--refresh`.
- `docs/agents/skills/agent-loop-l2-context/SKILL.md` (this package's own
  self-hosted skill, used when developing agent-loop itself) now points at
  `init tools` and stops gating `map` behind `workflow plan/approve`:
  querying the map is a plain lookup (like `rg`), useful any time a task
  touches more than one or two files. Note this is not a distribution
  mechanism -- `skills_root` always resolves against the *consuming*
  project's own root (`AgentAssetSourcePaths::fromSources()`), and `init
  scaffold` does not seed skill content, so other projects do not inherit
  this automatically; the README/CHANGELOG entries above are the actual
  discovery path for anyone hand-adapting a skill from this package.

## 0.5.0 - 2026-07-16

- Added `agent-loop verify --task-id=<id>`, scoping the tasks/sessions/recall
  checks to one task so an unrelated task's stale recall draft or broken
  task file no longer fails the run. `workflow close` now passes its own
  task id through automatically, so an unrelated task's drift can no longer
  block a close that is otherwise clean. package delegates, board, and the
  learning root stay repo-wide checks either way.
- `workflow context`'s map-symbol section no longer silently renders an
  empty "Relevant symbols" section for a directory-shaped `--scope` entry
  (the map index only matches exact file paths). A directory entry now
  expands to every indexed file under it; a scope entry that still matches
  nothing gets an explicit `[SKIP]` instead of no signal at all.
- Added `--adopt-existing` to `init sync-skills/sync-subagents/sync-hooks`,
  alongside `--force`. The existing unmanaged-target guard blocks overwrite
  unless one of these is passed; `--force` overwrites unconditionally,
  while `--adopt-existing` records an existing unmanaged target as managed
  *without* touching its content, so a first sync in an environment where
  the manifest file itself doesn't durably persist (e.g. a gitignored
  per-client target directory) doesn't have to keep using `--force`
  indefinitely -- the next sync, now that the target is managed, converges
  it normally.
- Requires `voku/agent-recall-compiler` 0.6.2+. `review blindspots`/`review
  code` now write their report/prompt files under a `reviews/` subfolder of
  the same `--output-dir` they read compiled recall inputs from (see that
  package's 0.6.2 changelog), instead of a hardcoded
  `.agent-recall/reviews/` that ignored a configured recall root entirely.
  `WorkflowReviewReportReader` (used by `workflow status/report/close`) now
  reads the report from `<recall-root>/<task-id>/reviews/<task-id>.blindspots.json`
  to match.

## 0.4.0 - 2026-07-16

- Implemented `init scaffold`, replacing the reserved
  `--profile=wsl2 --agent=<agent>` stub that always exited `1` with `not
  implemented yet`. It now takes only an optional `--dry-run`, creates the
  minimum local workflow structure (`.agent-loop/init.json`, `todo/board.md`,
  `session_plan/`, `infra/doc/agent-learning/findings/`) plus a `DEMO-1`
  example task and board card, never overwrites an existing file, and prints
  the next `board card show` / `workflow plan` commands to run.
- `workflow plan` and `workflow start` no longer require `--learning-root`.
  Added `WorkflowLearningRoot::resolve()`, which uses the explicit flag when
  given, else auto-detects `infra/doc/agent-learning` or the legacy
  `learning-root` directory under the project root, else fails with a message
  pointing at `init scaffold`.
- `Dispatcher`'s review `--output-dir` default now calls
  `RecallOutputRoot::resolve()` instead of a hardcoded `<root>/recall/`
  path, so `review blindspots`/`review code` pick up
  `infra/doc/agent-learning/recall-output` the same way `recall compile`
  does.
- Added `docs/quick-start.md` ("Your first governed task") and a README
  "Quick start" section walking `init scaffold` through plan, approve, and
  context in one path; `docs/agents/INFO_Agents.md` and the package/command
  tables no longer describe `scaffold` as reserved/planned.
- `composer require voku/agent-loop` install instructions now say
  `--dev`, matching how the package is actually consumed.

## 0.3.0 - 2026-07-14

- Added `RecallOutputRoot::resolve()`, a single config-driven source of truth
  for where a task's compiled recall briefing lives, replacing the hardcoded
  `<root>/recall/<taskId>` default and the ad hoc fallback added in 0.2.11.
  Configure `paths.recall_root` in `.agent-loop/init.json`; with no config it
  defaults to `<root>/infra/doc/agent-learning/recall-output` when that
  directory exists, else `<root>/recall`. Wired into `Dispatcher`,
  `AgentLoopVerifier`, and all four `Workflow*Command` classes so `workflow
  plan/context/status/report/close` and `agent-loop verify` always resolve
  the same path.
- Fixed `AgentLoopVerifier::checkRecallCoverage()` and
  `checkRecallStaleness()` resolving two different recall roots in the same
  `verify` run (the documented `--recall-root` flag was silently ignored by
  coverage checking); both now share one resolution.
- Restored the `current/meta.json` task_id-matching fallback in
  `checkRecallCoverage()` that 0.2.11's rework had dropped, fixing a real
  regression against `testRecallRootAutoDetectionAndCurrentFallback`.
- Added `PathResolver`, a shared absolute/relative path helper (extracted
  from `Init\AgentAssetSourcePaths`, which now delegates to it) with correct
  Windows drive-letter and UNC path detection; used by `RecallOutputRoot` and
  by all four `Workflow*Command` classes for their briefing-path display
  logic, replacing four independent, less robust copies of the same
  `str_replace`-based snippet.

## 0.2.11 - 2026-07-13

- Enhance recall logic to prioritize workflow metadata file.

## 0.2.10 - 2026-07-13

- Require `voku/agent-session` 0.2 for revision-bound validation evidence and
  explicit learning decisions.

## 0.2.9 - 2026-07-13

- Require the released `voku/agent-learning` 0.8 and
  `voku/agent-recall-compiler` 0.6 lines. The package no longer opts into
  Composer's global development stability.
- Added `workflow context`, a read-only budgeted view of the work brief,
  session state, recall selections, validation, and optional agent-map symbols.
- `workflow report` now distinguishes passed, failed, stale, and missing
  validation evidence by exact work-brief revision.
- `workflow close` now requires recorded validation, explicit selected-guidance
  outcomes, and a learning decision unless an accepted-risk bypass is recorded.

## 0.2.8 - 2026-07-13

- Migrated onto `voku/agent-kanban` 0.2.0's typed engine: `Dispatcher`'s `board` and `board:verify`
  namespaces now delegate to `voku\AgentKanban\Cli\CliApplication` instead of the removed
  `TodoBoardCli`/`TodoBoardVerifier`, and `AgentLoopVerifier`'s board check now delegates to the
  same `CliApplication::run(['agent-loop', 'verify'])` path. `board ticket`/`context`/`brief`
  became `board card show`; `board jira-sync` became `board external-sync
  --provider-class=<FQCN>`, so `Dispatcher` no longer takes a `JiraIssueProvider`/`projectPrefix`
  constructor argument — a host's `ExternalIssueProvider` implementation is now passed per
  invocation via `--provider-class`. Bumped the `voku/agent-kanban` constraint to `0.2.*@dev`.

## 0.2.7 - 2026-07-06

- `init install-plan` now prompts installing and verifying ripgrep (`rg`) alongside RTK and Caveman.

## 0.2.6 - 2026-07-06

- Updated agent-learning dependency to 0.8.0

## 0.2.5 - 2026-07-06

- Updated Skills files for the workflow of this package

## 0.2.4 - 2026-07-01

- Updated Skills files for the workflow of this package

## 0.2.3 - 2026-06-29

- Added Skills files for the workflow of this package

## 0.2.2 - 2026-06-29

- Added support for at least Windows paths (`init install-plan --profile=windows`)

## 0.2.1 - 2026-06-29

- Added a native Linux `init install-plan --profile=linux` variant alongside the WSL2 profile, reusing the same reviewed tool-install commands but with Linux-specific restart and boundary guidance.
- `init validate` now covers `skills`, `subagents`, `hooks`, and `all`, including canonical subagent frontmatter/path checks and Codex hook manifest validation.
- Added `init sync-skills`, `init sync-subagents`, and `init sync-hooks` with manifest-based stale-entry cleanup, unmanaged-target overwrite protection, dry-run support, and client target defaults for Codex, Copilot, Claude, and Antigravity.
- Added host-repo migration examples and expanded the portable guidance to cover RTK at the shell boundary, nested Make/Docker noise, and the need to audit host docs such as `AGENTS.md` and `README.md` for missing RTK guidance.

## 0.2.0 - 2026-06-29

- Added the `init` namespace for setup diagnostics, repo-managed agent-asset validation, WSL2 install-plan output, and reserved sync/scaffold command slots.
- `init` now validates repo-managed skills from a repo-neutral default source layout under `docs/agents/` (`skills`, `subagents`, `codex-hooks`, `tools`), with CLI/config path overrides for host repositories
- Expanded the portable guidance and IT-Portal migration references to cover RTK at the shell boundary, the nested Make/Docker output problem, and the need to audit host-repo docs such as `AGENTS.md` and `README.md` for missing RTK usage guidance.
- Added the `agent-loop-workflow` starter skill so repositories adopting `agent-loop` can load the real command sequence and learning boundary for this project's governed workflow without re-reading the full README.
- Added the `workflow` namespace for governed task start/status/close orchestration, including close gates and accepted-risk files.
- Hardened workflow documentation, close-gate structure, task-id tests, and accepted-risk write error handling.

## 0.1.2 - 2026-06-23

- Bumped the `voku/agent-learning` constraint from `0.6.*@dev` to `0.7.*@dev` to
  pick up the new `retired` `ProposalStatus` / `proposal-retire` command
  (`agent-learning` 0.7.0). This repo's own code needed no other change: the
  `learn` dispatch in `Dispatcher.php` already passes every `learn <command>`
  through generically (`proposal-*` in its own help text already covers the new
  command), and `voku/agent-recall-compiler` needed no change either, since it
  never scans `proposals/retired/`.

## 0.1.1 - 2026-06-22

- Added fallback for auto-detecting `recall-root` and enhance `recall` consistency checks in `AgentLoopVerifier`.

## 0.1.0 - 2026-06-22

- README and `examples/basic-loop` now lead with `todo/cards/*.md`, the
  preferred local Markdown card directory added in `voku/agent-kanban`
  0.1.0 (`todo/jira/*.md` still works for boards that already use it).
  Bumped the `voku/agent-kanban` constraint from `0.0.*@dev` to
  `0.1.*@dev` to pick it up — this repo's own code needed no other
  change, since the card directory is entirely owned and resolved by
  `voku/agent-kanban`.
- `verify` is now a cross-package consistency check (`AgentLoopVerifier`):
  tasks, board, session/recall linkage with hash-based staleness
  detection, and the learning root, each skipping itself when its inputs
  are absent. The previous board-only check remains available as
  `board:verify`.
- Reworked the README around the package map, the exact verified
  commands, and an explicit "what agent-loop does not do" section.
- Added `tests/fixtures/basic-loop` and `SmokeLoopTest`, an end-to-end
  proof of session -> recall -> learn -> verify.
- Fixed `bin/agent-loop` missing its executable bit in git, which broke
  running it directly from a checkout (`./bin/agent-loop`); installs via
  Composer as a dependency were unaffected, since Composer force-sets
  `+x` on `vendor/bin/` proxies regardless of the source file's mode.
- Added `examples/basic-loop`, a runnable walkthrough of the full loop
  against a tiny fake task, with real captured output.
- `Dispatcher` now resolves request-time defaults instead of requiring
  the caller to already know upstream conventions, fixing three things
  the README previously only documented as gotchas:
  - `session record`/`checkpoint`/`close`/`claim`/`show` accept the task
    id you started the session with, not just the generated session id
    (e.g. `2025-01-15-abc-123`) — `agent-loop` looks up the matching
    session before delegating. The session id still works directly.
  - `recall compile --task <id>` without `--output-dir` now defaults to
    `<root>/recall/<id>` (matching what `agent-loop verify`'s
    recall-coverage check expects), instead of the dependency's own
    default of the current directory.
  - `agent-loop board` no longer triggers a `PHP Warning:
    file_get_contents(.../todo/board.md)` when that file doesn't exist
    yet, and `agent-loop board --help`/`board help` now exit 0 with
    usage on stdout instead of being treated as an unknown subcommand.

## 0.0.3 - 2026-06-20

- Bumped `voku/agent-recall-compiler` dependency to version 0.5.*

## 0.0.2 - 2026-06-20

- Added `voku/agent-session` library integration

## 0.0.1 - 2026-06-20

- init commit
