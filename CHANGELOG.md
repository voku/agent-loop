# Changelog

All notable changes to this project will be documented in this file.

## 0.10.1 - 2026-08-05

- RTK guidance in `agent-loop-workflow` and `docs/agents/INFO_Agents.md` now
  distinguishes clients that carry the `rtk hook claude` PreToolUse hook, which
  rewrite commands on their own, from clients that need an explicit `rtk` prefix.
  Hand-prefixing in a hook-equipped client adds nothing.
- Both documents now state that `rtk discover` / `rtk learn` derive coverage from
  session transcripts, which store the pre-hook command text: a rewritten command
  is counted as "not using RTK", so a low coverage number is not evidence on its
  own. The guidance names the two checks that settle it - a `rtk hook claude`
  probe for the rewrite, `rtk gain` for what actually executed.
- Added the rule that a bind-mounted repository needs no `docker cp`: files a
  container command has to read belong in a git-ignored scratch path
  (`.agent-loop/tmp/`), which is also one of the commands RTK does not filter.

## 0.10.0 - 2026-08-05

- `workflow approve` now passes `--map-search-index .agent-map/search.sqlite` to
  the recall compiler when that file exists, so a brief that names no exact
  target still gets ranked candidates. A repository that never ran
  `agent-map search-index build` produces exactly the same briefing as before -
  the derived index is a cache, and its absence is not an error.
- Requires `voku/agent-recall-compiler` `^0.9.0` for the flag.

## 0.9.2 - 2026-08-05

- Requires `voku/agent-map` `^0.4.0` and `voku/agent-recall-compiler` `^0.8.1`.
  The edit orchestration and map-refresh paths are unchanged; the bump is what
  unblocks the 0.4.x agent-map line for the whole tree, including the derived
  hybrid-search index and the parallel chunk extraction added there.

## 0.9.1 - 2026-08-05

- `workflow help` advertised `--accept-risk <reason>` on its own, which 0.9.0
  refuses. The usage line now shows the required `--accept-risk-by <name>` as
  well; a CLI that documents a flag combination it rejects is worse than one
  that documents nothing.

## 0.9.0 - 2026-08-05

- `workflow close` now gates on edit verification: when
  `.agent-loop/edit/<task-id>/` exists it must contain a
  `verification-result.json` with status `passed`. A task that never ran
  `agent-loop edit` has no bundle and is not asked for one - demanding one would
  only encourage faking it - but a bundle that exists and was never verified
  blocks the close, which is the case the gate is for.
- The accepted-risk override now records who overrode it, why, and every gate
  that was failing at that moment, including which validation evidence was
  missing, in `.agent-loop/risks/<task-id>.accepted-risk.md` and a
  machine-readable `.json` beside it.
- **Breaking:** `--accept-risk` now also requires `--accept-risk-by <name>`. An
  override without a named owner is an anonymous decision, which is exactly what
  the record exists to prevent.
- Gates report why they failed instead of only that they failed, and every gate
  still runs after the first failure: a human deciding whether to override needs
  the whole picture, not the first problem in the list.

## 0.8.0 - 2026-08-05

- Added `agent-loop edit verify --bundle=.agent-loop/edit/<task-id>`, which
  verifies one concrete edit execution and writes `verification-result.json`
  into the bundle. It is not `agent-loop verify`, which checks cross-package
  consistency and drift; those stay separate commands.
- The command loads `verification-plan.json`, `verification-key.json`,
  `agent-result.json` and `execution.json`, and refuses to grade anything whose
  bindings do not line up: the key must be cut from the plan, the answer sheet
  must answer that same plan, and task and target must match across all of
  them. A mismatch exits 2 and writes no result, because a graded number from
  mismatched artifacts looks exactly like evidence.
- Grading: canonical probe answers against the private key (set equality, so
  order and duplication never decide a grade), checklist evidence resolved
  against real artifacts, and the declared objective gates - runner exit, PHP
  lint of the changed files, post-edit map freshness, target resolvability.
- Gates that shell out (`approved_validation_command`, `agent_loop_verify`)
  report `not_run` unless `--run-commands` is passed, and a required gate that
  did not run fails the verification. Refusing to run a command that arrived in
  a JSON file is allowed; recording it as passed is not.
- The post-edit map is refreshed into a bundle-local `post-edit-map.json` copy,
  never the shared index, so verification observes the repository instead of
  mutating it.
- `agent-result.json` now records the canonical symbol id in `target`, with the
  string the caller typed kept as `requested_target`. The plan and key are keyed
  on the canonical id, and the previous mismatch made every bundle unverifiable.
- Exit codes: 0 verified, 1 verification failed, 2 the bundle could not be read.

## 0.7.0 - 2026-08-05

- Requires `voku/agent-recall-compiler` `^0.8.0`, which emits
  `verification-plan.json` and the verifier-owned `verification-key.json`.
- `agent-loop edit` now writes `agent-result.json` into the edit bundle: the
  structured answer sheet a verifier grades against the private key. It records
  the plan the edit is bound to (`verification_plan_sha256`), the
  `changed_files` observed by diffing a working-tree snapshot taken before the
  runner against one taken after, the commands this process actually invoked
  with their exit codes and stdout hashes, and the runner outcome; and it seeds
  one empty slot per knowledge probe and checklist item so an unanswered probe
  is visibly unanswered instead of silently absent.
- The result file carries no expected answers, scores, verdicts or generated
  learnings by design, and `changed_files_source` separates an empty diff from
  an unreadable repository. `execution.json` gained an `artifacts.agent_result`
  pointer.
- A repository without Git, or a Git invocation that fails, yields an
  unavailable snapshot rather than a failed edit.

## 0.6.8 - 2026-08-04

- Requires `voku/agent-map` `^0.3.0` and `voku/agent-recall-compiler` `^0.7.2`.
- Added `agent-loop map refresh`, which re-analyses only changed or new files
  and patches them into the existing index instead of rebuilding the whole
  scope. Like `map build` it defaults `--root` and `--out` to the dispatcher
  root; previously only `build` did, so a `refresh` reached through the
  dispatcher would have resolved both against the current working directory and
  written the refreshed index somewhere other than where it read it from.
- Documented that `--paths` should stay on directories: PHPStan disables its
  result cache as soon as it is handed individual files, which makes every
  rebuild a cold rebuild.

## 0.6.7 - 2026-08-03

- Added `edit --phpstan-memory-limit`, forwarding an explicit positive limit to
  `agent-map` while rebuilding the PHPStan semantic map.
- Added repeatable `edit --focus=TEXT`, recorded in the execution request and
  compiled into bounded primary-source windows for literal, surgical changes;
  this mode omits optional relation slices.
- Added `edit --runner=mechanical` for an exact scoped literal replacement.
  It requires one match inside the resolved method, verifies the map hash,
  lints and reverts on failure, and records zero model tokens/tool calls.
- Added `edit --runner=auto` as the token-safe edit router. Exact replacement
  proof selects the mechanical PHP runner; otherwise it records
  `escalation_required` without launching a model. External model execution
  remains an explicit `--runner=command` decision.
- Requires `agent-map` 0.2.1, `agent-recall-compiler` 0.7.1, and
  `agent-session` 0.2.2 or newer for the focused edit and workflow behavior.
- Added package-owned historical replay coverage for a public one-line PHP fix.
  It compares `edit --runner=auto` against the committed result and a guarded
  Linux file-wide replacement baseline without invoking a coding agent.
- `init tools` now inventories optional RTK availability so agents can use it
  at the outer shell boundary for compact command output.

## 0.6.6 - 2026-08-03

- Added `agent-loop edit CLASS::METHOD -- INSTRUCTION`. The command builds or
  refreshes the PHPStan-backed `agent-map`, rejects missing, ambiguous,
  conflicted, or stale targets, compiles target-aware recall through
  `agent-recall-compiler` 0.7, and writes one auditable execution bundle.
- Added a safe non-executing `stdout` runner and an explicit shell-free
  `command` runner that receives `prompt.md` on stdin and records stdout,
  stderr, exit code, map digest, recall digest, and artifact hashes.
- Requires `voku/agent-map ^0.2` and `voku/agent-recall-compiler ^0.7`.

## 0.6.5 - 2026-08-02

- `workflow plan` accepts repeatable `--behavior-anchor` values and preserves
  them in the approved work brief. Workflow reports expose those anchors so
  the implementation and review evidence can be checked against the intended
  behavior.
- Documented the dream-maintenance command for governed guidance curation.

## 0.6.4 - 2026-07-28

- Docs-only sync pass: fixed the review-report path documented in
  `README.md` and `agent-loop-review-close/SKILL.md`, which still said
  `.agent-recall/reviews/` after 0.5.0 moved blind-spot/code-review output to
  `<recall-root>/<task-id>/reviews/`. Documented the `agent-loop verify
  --task-id=ID` flag (added in 0.5.0), which had no README coverage. Added the
  `workflow` and `init` namespaces to the README's "Exact available commands"
  reference block, which previously listed every other namespace but those
  two despite claiming to be the complete, verified command surface. Listed
  `init validate`, `init sync-subagents`, `init sync-hooks`, and `init
  scaffold` in the README's "Init" section command list, which only showed
  `doctor`/`status`/`tools`/`install-plan`/`sync-skills`.

## 0.6.3 - 2026-07-23

- Documented `--tag` on `workflow plan` and tag-based document-manifest
  matching in this package's own `agent-loop-workflow` and
  `agent-loop-l2-context` skills, so a project syncing these skill files
  (`init sync-skills`) gets the same guidance that was already in the
  README.

## 0.6.2 - 2026-07-23

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
- Expanded portable guidance to cover RTK at the shell boundary, nested
  Make/Docker output, and host-repository documentation audits for missing RTK
  usage guidance.
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
