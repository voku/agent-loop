# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Fixed

- A governed Run is no longer sealed by its own first `finish`. Work can legitimately
  continue afterwards - the closing Run's review gate can itself demand a follow-up
  change - but two independent guards made that unrecoverable:
  - `WorkflowRunPreparer::prepareSession()` threw whenever the bound Session existed
    and was not active, while the very next branch already rehydrated a Session that
    had been pruned away entirely. A merely closed Session carries strictly more
    information than a pruned one, so it now reopens through
    `SessionStore::reopen()` (which stays narrow: only a Session closed as `done`,
    and never while another open Session exists for the task). Requires
    `voku/agent-session` ^0.6.3.
  - `RunVerificationReceiptStore::record()` refused any receipt whose implementation
    snapshot differed from the stored one, so `workflow close` failed forever on
    evidence that no longer matched the code. A newer implementation of the *same*
    Run and the *same* approved Contract now supersedes the previous receipt and
    carries the replaced attestations in a `supersedes` chain (receipt schema 1.2),
    so nothing that was attested is lost. A different Run or Contract is still
    refused, and re-recording an identical receipt stays idempotent.

## 0.18.1 - 2026-08-25

### Added

- Add the read-only `WorkflowTransparencyService` / `TaskTransparencyProjection` boundary so hosts can answer what the Contract approved and excluded, which repository paths changed since the Contract baseline and which of those are outside approved scope, what implementation snapshot is current, what context was skipped or omitted, and what the exact current-or-stale review report found. Every section keeps its authority class; nothing in the projection can declare implementation complete or an acceptance criterion satisfied.
- Add `agent-loop workflow transparency <task-id> [--format text|json]` for inspecting that projection without a host.
- Add typed `WorkflowContextCommand::coverage()` and `WorkflowReviewReportReader::detail()`, so context skipped/omitted facts and exact review findings are consumable without parsing rendered context lines or review artifacts.

### Changed

- Contract scope matching now lives in one owner object (`ApprovedScope`) instead of being answered privately inside `WorkflowReportCommand`. The report's `outside_approved_scope` semantics are unchanged.

### Fixed

- Keep the historical public edit replay portable to standard PHP memory limits by
  passing its explicit 512M semantic agent-map budget through the real edit path.

## 0.18.0 - 2026-08-25

### Added

- Publish the hardened external-execution authority contract: changed Git candidates, workspace artifacts, deterministic validation evidence, and human-owned Attention transitions are accepted only through current owner-validated evidence bound to the exact Task, Run, Contract revision, execution plan, stage, attempt, and candidate lineage.
- Add a bounded `ExecutionEnvironmentObservation` / `ExecutionEnvironmentTool` API for optional runners. Host, tool, network, and remote-write facts are projected as explicitly untrusted runtime data; arbitrary environment variables, binary paths, credentials, provider policy, and workflow authority remain outside the boundary.
- Add the typed `WorkflowHumanDecisionService` / projection boundary for non-CLI adapters to record only the currently-authorized contract approval, exact review acknowledgement, or Learning disposition through existing owner stores.

### Changed

- Compiled Recall output superseding is now implemented by `voku/agent-recall-compiler ^0.13.13`; Loop retains orchestration timing and mount-point ownership without carrying a duplicate superseding implementation.
- Dogfood helpers and architecture-validation classes used only by development gates live outside production autoload; `voku/itp-context` is a development-only evidence dependency.
- Advance the `dev-main` Composer branch alias to `0.18.x-dev`.

### Fixed

- Include the 0.17.2 host-work ordering correction: mutation-authorized governed Runs expose implementation work before finish-owned validation and review, so pre-change review evidence cannot block the approved mutation.

### Validation

- The release target contains the merged execution-authority, owner-boundary, bounded-environment, human-decision, and host-work convergence regressions and must independently pass PHP 8.3/8.4/8.5 CI, PHPStan/project rules, diagnostics, installed release-set and refactor lifecycles, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint, and review checks before tagging.

## 0.17.2 - 2026-08-24

### Fixed

- Mutation-authorized governed runs now return a `host_work` next action before
  finish-owned validation and blind-spot review. This prevents `finish` from
  producing a pre-change review report and requiring its acknowledgement before
  the approved implementation can begin.

## 0.17.1 - 2026-08-23

### Changed

- Require `voku/agent-recall-compiler ^0.13.11`, making the Recall-owned `execute-plan-with-blind-spot-check` L1 recipe part of the supported Loop release set instead of depending on opportunistic Composer resolution.
- Document selection of the Recall-owned blind-spot-first execution recipe without copying its semantics into Loop, and move prompt-primitives installed-consumer dogfood to exact released Recall 0.13.11.

### CI

- New release tags must point at a commit whose own `CHANGELOG.md` already contains that release section; existing historical tags remain idempotent when their marker still names the exact immutable target.

### Validation

- PR #273 must pass the repository's PHP 8.3/8.4/8.5 CI, PHPStan/project rules, installed release-set and prompt-primitives dogfood, execution-contract/self-shape checks, and other required branch protection checks before merge.
- Prompt-primitives dogfood installs exact `agent-recall-compiler 0.13.11`, proves its distributed catalog contains `execute-plan-with-blind-spot-check`, and proves `agent-loop workflow plan` accepts the Recall-owned L1 recipe without a private copy.
- The `dev-main` alias was independently corrected to `0.17.x-dev` on `main` by commit `0905d82171c9334def2ef08beb52285a82fb3ac8` before this release; it is a release precondition, not a change claimed by PR #273.

## 0.17.0 - 2026-08-23

### Added

- Publish the typed governed external-execution protocol for optional process hosts: immutable execution profiles and plans, `ExecutionGateway`, bounded stage bundles, provenance-bound stage results and handoffs, typed Attention, exact-stage projections, and idempotent result acceptance. External runners may execute candidate work, but only agent-loop accepts governed transitions and owns final deterministic verification.
- Add fixed-contract transactional consumers for released agent-map rename/removal plans, including method, property, class-constant, class/function/property/class-constant rename families and dedicated installed-consumer lifecycle dogfood.
- Add the governed host front door, human-review artifacts, review acknowledgements, proportional-governance measurement, recovery/convergence evidence, and repository-owned host policy/runtime projection surfaces.

### Changed

- The governed lifecycle now exposes explicit `manual`, `surgical`, `standard`, and `hardened` execution profiles while preserving `manual` as the default. Profile selection is Contract-bound and frozen once its governed Run exists.
- Execution state uses per-task serialized acceptance and deterministic submission identities so retries are idempotent and concurrent submissions cannot discard already accepted history.
- Repository workflow guidance, quick-start, lifecycle, manifest and host activation surfaces now converge on owner APIs, canonical next actions, bounded evidence, and recovery-safe close semantics.

### Fixed

- Contract revision/supersession clears stale execution-profile selection before replacement Run preparation; persisted candidate revisions survive projections; StageResult normalization no longer breaks idempotent replay; and workflow close can refresh a complete manifest without re-entering stale execution-plan preparation.
- Refactor consumers fail closed on stale provenance, mismatched source hashes/ranges, unsupported plan roles and non-atomic publication; dedicated post-apply verification proves current Map evidence and target absence.

### Validation

- PR #270 passed PHP 8.3/8.4/8.5 CI, PHPStan, project PHPStan rules, deterministic slop review, governed execution-contract dogfood, self-shape, installed release-set and refactor lifecycles, AccessLint and CodeRabbit on exact head `f29be4faf0fccb4fabf4646bb77bea7cc8bfb665` before squash merge to `1f46648d4fb928aa256d5b6be6ac0f80f1a77d0e`.
- The accumulated post-0.16.6 release set includes the released agent-map 0.8.x fixed contracts and the matching transactional agent-loop consumers, each proven through clean installed Composer consumers rather than sibling-checkout assumptions.


## 0.16.6 - 2026-08-17

### Changed

- Requires `voku/agent-session ^0.6.1`, whose owner API can rehydrate pruneable working memory at an exact already-authoritative Session identity.

### Fixed

- `workflow approve` now resumes an existing governed Run after its bound pruneable Session disappears by recreating that exact Run-owned Session ID. It no longer generates a fresh date/random Session and then collides with the Run's durable identity.
- Resume refuses a different active Session for the same task and refuses a surviving non-active bound Session instead of silently rebinding durable Run lineage. New Contract revisions still use the existing supersession path.

### Validation

- The regression binds a Run to historical Session `2001-02-03-abc-123-r1-deadbeef`, removes working memory, reruns `workflow approve`, and proves both Run ID and Session ID stay unchanged. A second regression proves a conflicting active Session cannot steal that Run.
- PHP 8.3/8.4/8.5, PHPStan, project PHPStan rules, diagnostics, acceptance/prompt candidate dogfoods, installed release-set, execution-contract, slop review, self-shape, AccessLint and CodeRabbit were green on the exact merge candidate.


## 0.16.5 - 2026-08-16

### Added

- `learn finding-export` exposes package-targeted validated Learning findings as deterministic JSON while `agent-learning` keeps Finding validation, lifecycle, and filtering authority.

### Changed

- Requires `voku/agent-learning ^0.13.0` and `voku/agent-recall-compiler ^0.13.1`; Recall regression hunting now treats its numeric floor as a bounded probe budget rather than a defect quota.
- Package-owned projected guidance uses the resolved Composer tool root for agent-loop and Recall paths, and the review/close guidance has one owner instead of duplicated close sequences.

### Fixed

- Claude hooks now share one package-owned runtime probe, no-op below PHP 8.3 before Composer loads, and only consider the known root or `tools/agent-loop` autoload locations instead of scanning arbitrary tool projects.
- `init status` trusts persisted v2 first-party projection source evidence, so a clean installed consumer no longer reports freshly installed skills, subagents, or hook helpers as stale.
- Task-start and guidance-maintenance examples now preserve persisted-task preflight and behavior anchors without duplicating per-client projection validation commands.

### Validation

- PHP 8.3/8.4/8.5, diagnostics, acceptance/prompt candidate dogfoods, installed release-set, execution-contract, slop review, and self-shape were green on the merged owner candidates.
- `voku/httpful#34` replayed the exact merged candidate through an isolated PHP 8.3 tool project, regenerated package-owned Claude assets, passed `init validate`, and asserted skills, subagents, and hooks are current with no stale-managed warnings.

## 0.16.4 - 2026-08-16

### Changed

- `init status` now opens with an `Activation:` section and closes with the exact
  commands that finish the setup. It was the entry point the projected router
  sends a fresh agent to, but it only ever reported *source* presence: a
  repository where nothing was projected into any host - so no running agent
  could read a single skill - printed `[OK] skills-root: ... (16 skill(s))`,
  `[INFO] ... no manifest`, and exited 0, which is indistinguishable from a
  healthy setup. It now reports the resolved CLI path, whether skills are
  projected into a host at all, and whether `core.hooksPath`/`commit.template`
  are active. None of those lines claims an agent *consumed* anything: a
  projected skill is readable by the host, which is not the same as a session
  having used it.
- Activation commands are resolved against the repository they are printed in,
  by the new `RepositoryActivation`. Every one of them used to be written for a
  hypothetical consumer project. In agent-loop's own checkout `vendor/bin/agent-loop`
  does not exist - Composer does not link the root package's own binaries - so the
  first step of the projected router failed with "No such file or directory", and
  the `init sync-githooks` that `init doctor` recommended would have installed a
  second, untracked `.githooks/` beside the tracked `githooks/` sources instead of
  maintaining them.
- The router source keeps a `{{agent_loop_cli}}` placeholder that
  `init sync-instructions` resolves per repository, and names what to do when the
  CLI itself is missing and where to read the compiled Recall briefing after
  `workflow approve`.
- `init install-assets` now also activates the local Git integration when the
  repository declares one in `.agent-loop/githooks.json`, so hook and commit-template
  activation stops being a separate optional step that only `init doctor` mentioned.
  `--skip-git-config` installs the hook files and leaves Git configuration alone.
- `init doctor` no longer owns a second copy of the local Git integration checks;
  it renders the shared ones, including the remediation command that works here.

### Fixed

- `init sync-githooks --adopt-existing` now adds the execute bit to an adopted
  hook, keeping its content untouched. Adoption recorded a file as managed without
  checking the one property that decides whether Git can run it at all.
- The generated `githooks/lib/agent-loop-hooks.env` now pins `AGENT_LOOP_BIN` to the
  resolved CLI path. `pre-commit` and `commit-msg` fall back to
  `vendor/bin/agent-loop`, so in a repository whose own root package is agent-loop
  every commit aborted with "vendor/bin/agent-loop: No such file or directory" the
  moment the hooks were activated.
- `githooks/agent-map-refresh.sh` is shipped executable. `post-checkout` and
  `post-merge` `exec` it directly, so every checkout in a repository that adopts
  the package's own hook sources ran into "Permission denied".
- `init status` no longer reports the Recall consumer skills that
  `init install-assets` re-exports from `agent-recall-compiler` as stale managed
  entries. The projected set was compared against this repository's skills root
  alone, so a successful install immediately produced a warning about itself.

### Fixed

- `commit-msg` now judges the message Git will store instead of the file the
  hook is handed. At hook time that file still carries Git's comment block, the
  `commit.template` `init sync-githooks` installs, and the editor's leading blank
  lines. Matching forbidden patterns against it meant the template's own
  `WHY: [FILL]` guide line tripped the rule it exists to explain - blocking every
  commit with text the committer could not remove - and a message starting one
  blank line low was reported as having an empty header.
- What Git will store is now read from `commit.cleanup` and
  `core.commentString`/`core.commentChar` rather than assumed. New
  `GitCommitCleanup` owns the question. Assuming `strip` and `#` was wrong in both
  directions: under `commit.cleanup=whitespace` Git stores the commentary a
  stripping rule would have skipped, and under `core.commentChar=;` a `#` line is
  content Git keeps while the `;` line is the one it drops. `verbatim` and
  `scissors` are modelled too. The configured comment string is used verbatim,
  trailing space included: `core.commentString = "; "` makes `; comment` a comment
  and leaves `;not-a-comment` as content Git stores.
- Which of `core.commentChar` and `core.commentString` applies is now decided by
  the running Git rather than by a fixed preference, from a single
  `git config --list -z` read in Git's effective order. Before Git 2.45,
  `core.commentString` is reported by `git config` and then ignored by Git, so
  reading it predicted a cleanup that never happens. From 2.45 the two are aliases
  of one setting, so neither name outranks the other and whichever comes last
  wins. Values are taken byte for byte, trailing space included.
- `core.commentChar`/`core.commentString` set to `auto` is refused with the
  configuration to fix, rather than guessed. Git resolves `auto` while preparing
  the buffer and then writes its own help lines with the character it chose, so
  the file the hook receives always contains that character at the start of a
  line and cannot be scanned to recover the decision - a replay concludes the
  opposite of the truth. Modes that keep commentary never consult it and are
  unaffected.
- `default` resolves to `strip`, because the hook cannot observe whether an editor
  ran; that boundary is documented and pinned by a test, and an explicit
  `commit.cleanup` is honoured exactly.
- Pre-commit checks no longer silently skip staged files whose names Git munges.
  Line-oriented `--name-only` C-quotes any pathname containing a non-ASCII byte,
  a tab, a double quote, a backslash or a newline - and `core.quotePath=false`
  suppresses only the non-ASCII half of that. Such a name matched no `*.php`
  pattern, named no file on disk, and dropped out of the batch while the hook
  still exited 0. The path list is now read with `-z`, the form Git provides for
  machine consumption.
- A `phpstan` check declared with `"level": "max"` no longer becomes `--level=0`.
  The level was read with `(int)`, so the strictest setting silently produced the
  weakest analysis; a level the factory cannot read is now a configuration error.
- `memory validate` accepts Markdown rows whose first or last cell is empty, and
  treats an escaped `\|` as cell content. The row splitter trimmed *runs* of
  pipes, so such a row lost a column and the whole MEMORY file was rejected as
  malformed.
- Checklist evidence must now resolve to a file inside the bundle or the project.
  A trailing `is_file($reference)` accepted any readable path on the machine, so
  `/etc/hostname` counted as evidence and a required `human_review` item passed
  on an artifact belonging to no task.
- Probe answers keep numeric evidence ids as strings; PHP's integer-like array
  key coercion had been emitting them into the graded report as JSON numbers.

### Validation

- 86 new tests, each confirmed red before its fix and green after. The
  commit-message and pre-commit tests drive a real repository and a real
  `git diff --cached` rather than a stub.
- The cleanup modes are verified by committing for real under each setting and
  asserting the prediction equals what Git stored, so the assumption underneath
  the validator is checked against Git rather than asserted in a comment.
- The comment-string selector is covered deterministically across Git generations
  without needing several Git binaries; only the tests that assert Git's own
  handling of `core.commentString` are gated on Git 2.45.

## 0.16.3 - 2026-08-14

### Added

- Added top-level `agent-loop prompt guidance-gaps`, delegating to released
  `agent-recall-compiler 0.12.2`. The opt-in L2 technique creates a
  project-specific implementation prompt that maintains task-local
  `implementation-notes.html` and distinguishes ordinary decisions from
  actual missing, stale, or conflicting spec, docs, skill, workflow, tool
  contract, code, or test authority.
- The generic Recall-owned `prompt` namespace also exposes context-light
  `prompt future-work`; governed Runs should continue to use `workflow reflect`.

### Changed

- Requires `voku/agent-recall-compiler ^0.12.2`.
- Agent-facing prompt primitive docs now explain the guidance-gap journal,
  `HUMAN_DECISION_REQUIRED` boundary, non-commit default, and the raw-versus-
  governed future-work reflection boundary.

### Validation

- Clean-consumer prompt dogfood executed the installed
  `agent-loop prompt guidance-gaps` command and asserted journal, authority,
  opt-in, blocking, and non-commit semantics against Recall `0.12.2`.
- Dogfooding the technique found the missing Loop prompt-primitives
  documentation surface and the raw-versus-governed future-work ambiguity
  before release.
- PHP 8.3/8.4/8.5, PHPStan and project rules, acceptance/prompt candidate
  dogfoods, installed release-set, execution-contract, self-shape,
  deterministic slop review, AccessLint and CodeRabbit were green on the
  exact feature candidate before merge.

## 0.16.2 - 2026-08-14

### Changed

- Requires `voku/agent-learning ^0.11.1` and `voku/agent-recall-compiler ^0.12.1`. The default Dream report now exposes selected-versus-judged guidance completeness, and the installed Recall consumer skill teaches the released outcome-honesty contract instead of treating compiler placeholders as feedback.
- Candidate clean-consumer dogfood resolves the declared minimum Recall release from the package contract rather than relying on a synthetic path-repository version.

### Fixed

- Self-shape no longer logs Recall's pre-filled `unknown` rows as though the runner had judged guidance it never read. A caller without evidence can explicitly withhold the judgement with a bounded reason; silent absence still blocks close.
- `workflow close` accepts withholding only from a current `selected=true` selection event, canonicalizes the current selected set, and does not warn by reading a missing `outcomes.jsonl` in a legitimate all-withheld run. An existing unreadable outcome history fails explicitly.
- Self-shape resolves the current Contract revision instead of assuming revision 1, so evidence remains bound after a Contract revision.

### Validation

- PHP 8.3/8.4/8.5, diagnostics and project PHPStan rules, acceptance/prompt-primitives clean-consumer dogfood, installed release-set dogfood, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint and CodeRabbit were green on the exact current-base candidate before merge; the exact merge commit also passed main-branch CI.


## 0.16.1 - 2026-08-14

### Changed

- Requires `voku/agent-recall-compiler ^0.11.6`, whose canonical `agent-recall-consumer` skill now matches the live Recall CLI and compact `.agent-loop` defaults.
- `agent-loop init install-assets` now installs both Loop-owned workflow skills and the installed Recall package's canonical skill tree, including `operating-prompts.json`, without requiring callers to know or pass the dependency's skill root.
- Agent-facing task-start, L2-context, investigation, learning-closeout, and review-close guidance now follows the current PLAN -> APPROVE workflow, `ProjectLayout` path ownership, Run learning decision, and accepted-risk boundaries.

### Validation

- Agent-facing regressions reject retired or configurable hard-coded paths, normalize wrapped command examples, and verify documented `workflow` / `init` subcommands against the live CLI help.
- Candidate dogfood derives the minimum Recall release from `composer.json`; clean-consumer tests inspect the installed Recall skill contents rather than only checking that a file exists.
- PHP 8.3/8.4/8.5, PHPStan and project rules, acceptance/prompt-primitives candidate dogfood, installed release-set dogfood, governed execution-contract dogfood, deterministic slop review, and self-shape were green on the current combined tree before merge.
## 0.16.0 - 2026-08-14

### Changed

- Requires `voku/agent-learning ^0.11.0`, which allocates record IDs instead of
  deriving the next number from locally visible files. `agent-loop learn
  finding-id` now hands out a collision-resistant finding ID, so parallel
  branches stop allocating the same one.
- `agent-loop map search-index` and `agent-loop map search` now default to the
  database path `ProjectLayout` owns (`.agent-loop/map/search.sqlite`). They
  previously fell through to agent-map's retired `.agent-map/` default, which is
  read by nothing in the governed workflow. Pass `--database` explicitly to keep
  a custom location.
- `workflow approve` reports when no search index exists, because Recall still
  compiles without ranked map evidence and a quietly narrower context is
  indistinguishable from a correct one.

### Fixed

- `init doctor` and `init sync-githooks` recognise a linked Git worktree. Both
  inferred repository state from `is_dir(<root>/.git)`, but `git worktree add`
  stores `.git` as a file, so a valid checkout was reported as "no repository"
  and `sync-githooks` installed six hook files while silently skipping
  `core.hooksPath` - leaving the hook integration inert in the layout agents
  work in most.
- The self-shape runner detects recorded findings by identity across every
  findings state directory. It watched `findings/validated/` only, so
  consolidating a finding - the normal end of its lifecycle - made a change
  whose subject was recording learning look as though it had recorded none.

### Added

- Two project PHPStan rules with failing fixtures and exact expected
  diagnostics: `NoGitDirectoryShapeAssumptionRule` rejects inferring repository
  state from the shape of `.git`, and `NoInProcessPhpstanRuleTestCaseRule` gives
  the reviewed tooling-isolation rule a detector instead of a shell convention.
- A frozen real-task replay suite recording where decisive evidence was lost
  along map discovery, Recall selection and context projection. Its assertions
  fail if a future change "fixes" a replay by widening search, changing ranking
  or raising the context budget.
- `composer review:slop-baseline` refreshes the line-shifted slop baseline next
  to the check it serves.

### Learning

- The Learning pipeline had never been run in this repository: 23 validated
  findings and no proposals, so `finding -> proposal -> reviewed decision ->
  durable guidance` had never been walked once. 28 findings are now
  consolidated into 15 proposals; 6 candidates await a named human approver,
  and 9 were acknowledged as `NO_DURABLE_LEARNING` with recorded reasons.
- Validated `finding.2026-08-14.008`: a derived state path needs one owner for
  reading and writing. The search index was read by `workflow approve` and
  written by nothing, so ranked evidence silently never reached the governed
  context. Same task, same ranking, same budget: 17 context lines with the
  decisive API absent, 55 with it present.
- Validated `finding.2026-08-14.015`: read a diff for what it removed. An
  invariant that held by construction needs an explicit assertion once its
  construction changes, a random source is injected rather than sampled by a
  test, and a process call is not a drop-in for a filesystem check.

### Validation

- PHP 8.3/8.4/8.5 tests and gates, project PHPStan rule fixtures out of
  process, deterministic slop review, governed execution-contract dogfood,
  installed release-set dogfood, prompt-primitives clean-consumer dogfood, and
  agent-loop self-shape, all green before merge.
- Evidence conservation was measured on real consumer replays in
  `voku/simple-php-code-parser` and `voku/anti-xss` rather than on synthetic
  prompts.

## 0.15.2 - 2026-08-14

### Changed

- Requires `voku/agent-recall-compiler ^0.11.5`. Recall now owns and ships the
  first-party operating-prompt catalog beside its consumer skill, so Loop uses
  `vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json`
  instead of synchronizing Recall behavior through a separately pinned
  `agent-skills` commit.
- `agent-loop-l2-context` now owns only Loop orchestration around Recall and
  defers Recall prompt schema, recipe semantics, and review primitives to the
  Recall-owned skill. This removes a second Markdown implementation that had
  already drifted from Recall's current L2 construction contract.

### Learning

- Validated `finding.2026-08-14.005`: skills and machine-readable instruction
  assets whose correctness depends on a tool's CLI, schema, generated files,
  output contract, or runtime behavior belong in that tool's repository and
  ship/test with the implementation. Generic skill collections keep only
  tool-neutral principles or references to the canonical owner.

### Validation

- The ownership handoff passed PHP 8.3/8.4/8.5 CI and diagnostics, project
  PHPStan rules, acceptance and prompt-primitives clean-consumer dogfood,
  governed execution-contract dogfood, installed release-set dogfood,
  deterministic slop review, and agent-loop self-shape before merge.


## 0.15.1 - 2026-08-14

### Added

- `workflow status <task-id> --expect <state>` turns the read-only Run
  projection into an exact, CI-assertable lifecycle check without changing the
  existing status exit semantics when no expectation is supplied.
- Prompt review now has an installable falsification path end to end: the Loop
  review namespace exposes Recall's context-light `review first-draft`, governed
  `review code <task-id>` includes the same first-draft lens with task artifacts,
  and installed `agent-skills` can supply project-grounded L2 recipes such as
  `adversarial-review` through their copied `operating-prompts.json` manifest.

- `voku/itp-context` is a runtime dependency, and `src/Context/ArchitectureRules.php`
  declares four rules with the `Rule` attribute on the symbols they constrain:
  `ProjectLayout` owns every state path, Workflow calls typed package APIs,
  generated evidence is never approval, and external evidence tools stay
  optional. Each names the check that catches the next violation, each was
  violated at least once by a change that passed review, and `composer ci` runs
  `context:validate`.
- A `slop-scan` CI job reviews the candidate against `slop-baseline.json` using
  `slop-scan.config.json`, so the gate fails on findings a change adds rather
  than on the repository's existing history. `composer review:slop` runs the
  same check locally. It installs from `tools/slop-scan` because 0.1.4 cannot
  co-resolve with `agent-map` and 0.1.5 is untagged.

### Changed

- Requires `voku/agent-recall-compiler ^0.11.4`, whose released prompt surface
  preserves acceptance/scope/evidence boundaries and provides the first-draft
  review primitive. Prompt, acceptance, execution-contract, release-set, and
  installed-asset dogfood now use the same released Recall floor and the same
  pinned first-party `agent-skills` catalog instead of testing stale prompt
  combinations.
- `voku\AgentLoop\Cli\OptionTokens` owns argv option parsing. Twelve commands
  carried a private copy of the same loop, ten byte for byte — including one
  added in this release. `slop-scan` reported the cluster; the extraction
  removed 13 duplicated methods across 12 files. `value()` now resolves to the
  first non-empty occurrence, which differs from the old single-value copies
  only for a repeated option whose first value is empty — an input none of
  those commands accept.

### Fixed

- Task-scoped `verify` now requires the exact task Markdown or durable Contract
  when task files exist, and filters
  `agent-kanban` failures to that task while retaining board-wide failures, so a
  missing task cannot pass and unrelated task-local drift cannot block it.
- Run projection now resolves board context through `agent-kanban`'s canonical
  config/metadata/inference rules. The metadata-only board created by `init
  scaffold` is therefore linked instead of reported as unconfigured, and the
  scaffold no longer writes stale derived `Source` or `Done count` metadata.
- Agent-facing guidance, hook guards, edit help, and dogfood fixtures use the
  canonical `.agent-loop/map` paths and current Contract/Learning commands.

- The isolated tool projects pin `config.platform.php` to this package's lowest
  supported PHP. Their lock files had been resolved on 8.4 and pulled
  `symfony/string` 8.1, which requires PHP >= 8.4.1, so CI on 8.3 could not
  install the tooling at all. A test now fails when a tool project stops
  pinning, or pins a PHP this package does not support.
- `RuntimeException` raised for invalid dogfood JSON now carries the
  `JsonException` as `previous`, and three `@param mixed $value` annotations
  that only repeated the native signature are gone. Both were `slop-scan`
  findings.

### Added

- The real-issue acceptance model in `docs/agents/dogfood/real-issue-acceptance.md`:
  candidate pre-screen, freeze, three separate evidence planes (`agent-map`
  structure, `voku/itp-context` architecture intent, `voku/slop-scan` candidate
  delta), regression before implementation, project-native gates as the
  correctness authority, and a per-tool usefulness ledger in `LEARN`. It maps
  onto the existing governed phases and adds no lifecycle state.
- `init tools` probes the external evidence tools `itp-context` and `slop-scan`
  beside `rg`, `git`, `php`, `composer` and `docker` — none of which this
  package installs either. A project-local installation (`vendor/bin` or an
  isolated `tools/` project) is preferred over an ambient PATH build, the
  reported path is the one to invoke, and an absent tool is information rather
  than a warning. `agent-loop-l2-context` and `agent-loop-code-review` route to
  the planes when the inventory reports them.
- `RealIssueEvidenceToolBoundaryTest` keeps the installation boundary
  executable: `voku/itp-context` and `voku/slop-scan` are invoked from isolated
  tool projects, not declared as dependencies of this package. The
  Simple-PHP-Code-Parser conflict that used to force this (`^0.22` against
  `^0.21`) is resolved by `slop-scan` 0.1.5; the boundary now rests on the PHP
  8.3+ floor, on keeping agent tooling out of consumers' dependency trees, and
  on neither tool having earned a place inside a gate yet.

- `init sync-tools` installs the isolated evidence tool projects from
  package-owned templates in `docs/agents/tools/`, following the existing
  `sync-*` contract: managed entries in a target manifest, `--dry-run`,
  `--force` and `--adopt-existing`, stale managed entries removed, unmanaged
  targets refused. It writes project files and never runs Composer — that
  reaches the network and picks versions, so the command names it instead.
- Isolated tool projects `tools/itp-context/` and `tools/slop-scan/`, pinned by
  their committed lock files, so the documented installation boundary is
  executed rather than described. `tools/slop-scan/slop-scan.php` works around
  `voku/slop-scan` 0.1.4 resolving its autoloader at a path that exists only in
  a standalone checkout.

### Fixed

- `init tools` reported the `agent-map` index from the pre-consolidation
  `.agent-map/php-symbols.json` while `ProjectLayout` had moved it to
  `.agent-loop/map/php-symbols.json`, so a built index was reported as never
  built and a configured `state_root` was ignored. The probe now asks the
  layout owner for both the location and how to display it.

## 0.15.0 - 2026-08-12

The pre-1.0 semantic reset (#19). Ownership moves to the packages that can
actually keep each promise, and every breaking change here deletes a
demonstrated contradiction rather than expressing a preference.

Requires agent-session ^0.5.0, agent-learning ^0.10.0 and
agent-recall-compiler ^0.11.0. There is no compatibility shim anywhere in this
release: a wrong path or a stale artifact fails loudly instead of being guessed
at.

### Changed

- **Breaking:** PLAN is durable before a Session exists. `workflow plan` writes
  a Contract and creates no Session and no Run. APPROVE binds that exact
  approved revision to a governed Run with its own `run_id`, so Run identity is
  never derived from Session identity.
- **Breaking:** a governed Run records the durable Learning repository it is
  governed against. `close`, `learn`, `report` and the Run projection read that
  binding, and a `--learning-root` that disagrees is refused rather than
  silently reading a different repository. Previously close gated on the
  caller's flag while the projection it then wrote re-derived the location from
  the layout default — close reported success while the durable manifest it
  produced in the same command said the Learning decision was missing, and that
  contradiction outlived Session pruning.
- **Breaking:** repository-local workflow state lives under `.agent-loop/`, and
  `ProjectLayout` is the only thing that resolves a state path. `state_root`
  moves the whole tree; `sessions_root`, `learning_root` and `recall_root`
  override one branch each, via `.agent-loop/init.json`.
- **Breaking:** `workflow status`, `context` and `report` render from the
  durable Contract and owner artifacts instead of Session-held state; the
  pre-Contract workflow shortcuts and `workflow start` are removed.
- **Breaking:** the CLI exposes only pruneable Session commands. Durable
  approval and Learning close-out are owned by `agent-loop` and
  `agent-learning`.
- `close` reports which gate failed. Gate details were collected and then
  discarded, so a caller saw `gates failed` with every gate printed `[OK]`, and
  the Contract-binding refusal reached only STDERR.

### Added

- `agent-loop init paths [--format=text|json]` reports where this project keeps
  its workflow state, so an agent can ask instead of assuming `.agent-loop/`.
- L2 execution contracts are governed end to end, and the gate proving it now
  requires anchors only the current Contract could supply rather than the
  recipe template's own scaffolding.
- Consumes agent-map 0.5 discovery, 0.6 architecture discovery and 0.7 temporal
  evidence.

### Upgrading

See `UPGRADING.md`. Run artifacts written before this release are rejected by
name; re-run `workflow approve` to re-prepare the Run against the same approved
Contract revision. Durable Contracts, verification receipts and Learning
decisions are untouched.

Note: 0.14.0 was prepared but never released; its content is superseded by this
release.

## 0.13.0 - 2026-08-07

- Pre-commit checks are declared by type instead of by command line:
  `php-lint`, `phpcs`, `phpcbf`, `php-cs-fixer`, and `phpstan` render the standard
  tool invocation from the package, so a repository configures its rule set
  (`standard`, `config`, `level`, `memory_limit`) rather than another wrapper
  script. `php-lint` runs per file because `php -l` takes exactly one path.
  `type: command` stays the escape hatch for anything else, and an unknown type
  fails with the list of known ones instead of running a broken command.
- `init sync-subagents --agent=claude` renders repo-managed subagent roles into
  `.claude/agents/*.md` (override with `CLAUDE_AGENTS_DIR`), and `--agent=all`
  now includes Claude. `install-assets --agent=claude` therefore installs the
  bundled investigator, surgical-builder, and code-reviewer roles as well;
  repository hooks remain Codex-only.

- `init sync-hooks --agent=claude` installs a host-owned hook bundle for Claude
  Code. Claude registers hooks inside `settings.json` rather than in a hooks
  file, so the sync owns exactly one key: it merges `hooks`, writes every other
  setting back unchanged, and records `settings.json#hooks` in the target
  manifest. That manifest entry is what makes the unmanaged-target refusal,
  `--force`, `--adopt-existing`, and a later stale-removal behave the same way
  they do for file-based targets. `CLAUDE_CONFIG_DIR` overrides the target
  directory; the source root defaults to `docs/agents/claude-hooks` and is
  configurable through `claude_hooks_root` or `--hooks-root`.
- `init validate --kind=hooks --agent=claude` validates such a bundle. Claude has
  no required event - a bundle may register only `PreToolUse` guardrails - but a
  hook command must still call a script inside `.claude/hooks/`, so a bundle
  cannot point at an unmanaged path.
- Hook reading and validation moved into a client-agnostic `HooksDefinition`.
  `CodexHooksDefinition` keeps its public API and now delegates, which is why the
  Codex contract (required `SessionStart`, `SubagentStart`, `PreToolUse`, commands
  under `.codex/hooks/`) is unchanged.
- `agent-loop githooks pre-commit` and `agent-loop githooks commit-msg` implement
  the hook logic every PHP repository was re-writing: skip merge commits, list the
  staged files, drop the excluded ones, batch them, stop at the first failing
  check - and for the message: header pattern, leftover template placeholders, a
  required section that must contain something, and a nudge when that section is
  short and vague. The project-specific half (check commands, commit convention)
  is data in `.agent-loop/githooks.json`; without that file both hooks are a
  no-op, so installing them cannot break a repository that has not configured
  them yet.
- `init sync-githooks` installs the package-owned Git hooks into a host
  repository and points `core.hooksPath` (and optionally `commit.template`) at
  them. The hooks themselves are generic - `post-merge` and `post-checkout` keep
  the agent-map index in step with the working tree - while the project-specific
  part (container service, image, workdir, user, index paths) is rendered next to
  them as `lib/agent-loop-hooks.env` instead of being copied into a new shell
  script per repository. Hooks the host owns, such as `pre-commit` and
  `commit-msg` in the same directory, are never read, rewritten, or removed: only
  the installed entries enter the target manifest.
- The container lookup those hooks need (inside the container, through compose,
  through a matching image, or plain host execution) now lives once in
  `githooks/lib/agent-loop-hooks.sh`, together with a path mapper so a hook can
  forward Git's temporary index for `git commit --only`.
- Shipped `make/agent-loop.mk`. A host repository includes it instead of
  maintaining one Make wrapper per client, and overrides `AGENT_LOOP_BIN`,
  `AGENT_LOOP_CONFIG`, or `AGENT_LOOP_SYNC_FLAGS` when it needs its own
  entrypoint. The asset content stays in the host repository; only the commands
  live in the package. A contract test fails if the include calls an `init`
  subcommand this package does not implement.

## 0.12.0 - 2026-08-07

- `workflow manifest <task-id>` projects the run manifest v1 contract: the one
  place that names the kanban card, session, work brief revision and approval,
  map and search-index state, recall compilation and output hashes, edit bundle,
  verification, review, and learning decision as a single related run. It is
  read-only by default; `--write` persists the projection atomically, and
  `--format=json` is the stable machine surface. Consumers previously had to
  reconstruct that relationship from directory names and per-package
  conventions. The manifest describes the owning artifacts, it does not replace
  any of them. See `docs/architecture/run-manifest-v1.md`.
- `workflow plan`, `workflow approve`, and `workflow close` refresh the manifest
  at their transitions, so the projection is current without a separate command.
  A refresh failure is reported as its own `[FAIL]` with the state that did
  change and the `workflow manifest <task-id> --write` recovery step,
  rather than being folded into the command's normal exit code.
- `workflow status` is now rendered from that same projection and accepts
  `--format text|json`. The joined view and the manifest can no longer disagree,
  because there is only one projection left to disagree with.
- `workflow approve` is safe to rerun. It detects that the current brief
  revision is already approved and resumes at recall compilation instead of
  approving twice, which previously turned a failed compile into a state that
  could only be fixed by hand. Before a newly approved revision is compiled, the
  superseded recall output is archived rather than overwritten, so a failed
  recompile leaves the previous revision's evidence intact instead of a half-
  written canonical directory.
- The package now ships its own reviewed agent behavior and no longer points
  agents at RTK. Installed skills: `agent-loop-discipline` (concise human-facing
  communication, smallest correct change, bounded context, and the rule that raw
  evidence is never compressed or rewritten), `agent-loop-investigate`,
  `agent-loop-surgical-edit`, `agent-loop-code-review`,
  `agent-loop-simplify-review`, `agent-loop-simplify-audit`, and
  `agent-loop-dogfood`, plus three bounded roles - investigator, surgical
  builder, code reviewer - for clients that expose a repository-local role
  format. The mechanisms adapted from the MIT-licensed Caveman and Ponytail
  projects, reviewed at fixed commits, are credited and mapped one by one in
  `docs/agents/THIRD_PARTY_NOTICES.md`; the reasoning and the rejected
  alternatives are in `docs/agents/dogfood/2026-08-07-first-party-discipline.md`.
- `agent-loop init install-assets --agent=<agent|all>` installs those assets
  from the Composer package with `--dry-run`, `--force`, and `--adopt-existing`.
  Nothing is downloaded and nothing is configurable: the assets are immutable
  and package-owned, which is what makes "the agent read the guidance we
  shipped" a checkable claim. `init install-plan` is correspondingly now an
  offline plan for those assets instead of a set of third-party installer
  commands, and `init tools` no longer probes for `rtk`.
- Codex gets native support rather than a translated approximation: roles are
  rendered as Codex role TOML with the `name`, `description`, and
  `developer_instructions` its config layer requires, and the bundled PHP hooks
  supply discipline context on `SessionStart` and `SubagentStart` and a
  `PreToolUse` policy on `Bash`. `init status`, `init validate`, and `init
  sync-subagents` report and check those manifests.
- Codex hook command validation rejected nothing after the hook path. A command
  that matched `php .codex/hooks/context.php` was accepted with arbitrary
  trailing shell content still attached. Only the exact
  `--event=SessionStart|SubagentStart` suffix is accepted now.
- Added the installed release-set gate (`tools/release-set-dogfood.php`), which
  installs the `agent-*` packages as a clean Composer consumer and runs the
  lifecycle against that installation - catching an installed package that loads
  a sibling checkout or a nested `vendor/` tree, which package-local tests
  structurally cannot see. `composer ci` also runs the new
  `composer dogfood:discipline` behavioral gate. Documented in
  `docs/testing/installed-release-set-gate.md` and
  `docs/architecture/supported-release-set.md`.
- Dependency constraints are unchanged.

## 0.11.0 - 2026-08-06

- `workflow status` is now one joined lifecycle view instead of six independent
  green lights. It names every stage - session, work brief, recall, edit bundle,
  review, learning - and ends with the single next command for wherever the task
  actually stands. Six packages each reporting their own status left the reader
  to join six state machines by hand, which is where the surprises lived.
- `workflow plan --ephemeral` (and `session start --ephemeral`, from
  `voku/agent-session` 0.3.0) declares a session an experiment. `agent-loop
  verify` skips ephemeral sessions instead of failing the repository-wide gate
  for a throwaway, and `workflow status` shows it as an experiment and asks for
  it to be closed.
- Added `docs/agents/LIFECYCLE.md`: the cross-package contract for DISCOVER →
  PLAN → APPROVE → PREPARE → EXECUTE → VERIFY → REVIEW → LEARN → CLOSE, with the
  owning package, inputs, outputs, failure state and recovery command for each
  transition - written from real runs, including the parts that are still
  uneven.
- The dispatcher raises a too-small `memory_limit` to 512M, because several
  commands read a large map index and die on a default 128M process. It is a
  floor and never a ceiling: `MemoryLimit` interprets PHP's shorthand, so
  `-d memory_limit=4G` stays 4G. A plain `(int)` cast reads `2G` as `2` and would
  have clamped a deliberately raised limit down to 512M - breaking exactly the
  heavy commands the floor exists for. Unlimited (`-1`) and unparseable values
  are left untouched.
- The binary resolved its autoloader by preferring the package's own `vendor/`
  directory. When one is present next to an installed copy - a path repository, a
  mirrored checkout, a stale local install - that autoloader wins and silently
  loads *its* dependencies instead of the project's. Found by a release-set smoke
  test that reported `Undefined property Session::$ephemeral` against an
  installed version that plainly had it. The outer autoloader is now tried first.
- Requires `voku/agent-session` `0.3.*`.

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
