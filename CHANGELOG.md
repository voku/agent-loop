# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

## 0.20.0 - 2026-09-05

### Added

- Add fast-path micro-task flow (`agent-loop quick`): initiate, auto-approve, and enter a bounded surgical micro-task (up to 2 files) in a single command, with 1-shot auto-close enforcement (60-line diff ceiling, scope confinement, auto-recorded reviews/learning).
- Add bounded auto-repair on verification failure (`agent-loop repair`): capture structured diagnostics (PHPStan, PHPUnit, PHP linter, PHP-CS-Fixer) on validation failure, project actionable repair instructions, and enforce a strict 2-attempt budget before human escalation to prevent infinite agent repair loops.
- Add turnkey multi-stage execution runner (`agent-loop pipeline`): automate multi-stage profiles (`surgical`, `standard`, `hardened`) with role-based briefings, handoff envelopes carrying review feedback (e.g. `changes_required` loops back to `build`), and deterministic verification auto-progression.
- Add human/agent workflow front doors under `docs/workflow/` while keeping code authoritative for lifecycle semantics and `README.md` focused on product overview.
- Add end-to-end two-run LearningNote dogfood for #349 through released owner APIs: Task A records a classified validated Finding, closes with the note route only as an optional follow-up, physically prunes its Session working memory, then publishes the note from durable Learning evidence; Task B enters normally and Recall supplies the exact current precedent with source lineage and deterministic scope evidence.
- Add end-to-end dogfood test `tests/Dogfood/AutonomousReplanDogfoodTest.php` proving behavioral closure of #345: an agent facing an invalid implementation premise autonomously triggers `REPLAN` within the approved intent without human interruption, while premise failures requiring scope or goal changes strictly enforce `HUMAN_DECISION_REQUIRED` via superseded unapproved contract revisions.

### Changed

- Require `voku/agent-learning ^0.16.1`, making the released classified-Finding owner API part of Loop's supported dependency boundary instead of relying on Learning-private storage in the return-loop proof.
- Advance the root development alias to `0.20.x-dev`.

## 0.19.0 - 2026-09-04

### Changed

- **Breaking.** Adopt the coordinated pre-1.0 release set: `voku/agent-kanban ^0.4.0`,
  `voku/agent-learning ^0.16.0`, `voku/agent-map ^0.10.0`,
  `voku/agent-recall-compiler ^0.15.0`, `voku/agent-session ^0.7.0`. The root
  development alias moves to `0.19.x-dev`.
- Resolve Recall's bundled operating-prompt manifest through the owner's
  `BundledOperatingPromptManifest::consumer()` instead of deriving
  `skills/agent-recall-consumer/operating-prompts.json` from a reflected source
  location. Recall moved that asset to `resources/skills/`, and the reconstructed
  path failed closed with "Bundled todo-card-handoff manifest not found" - the
  owner's own docblock forbids consumers knowing that layout.
- Dogfood workflows pin the declared minimum release set (Recall `0.15.0`, Map
  `0.10.0`) in checkout refs, Composer path-repository version aliases, and the
  resolved-version assertions that guard them.

### Fixed

- `map history diff` coverage snapshots the complete index. agent-map 0.10.0
  splits the index into symbol definitions plus a companion relations file, so
  copying `php-symbols.json` alone produced an incomplete "before" side and an
  unchanged tree reported spurious `relation_added` events.
- The governed plan-capability contract expects Map 0.10's fourteen plans,
  adding `copy:method_copy_plan`, `move:method_move_plan`,
  `scaffold:class_scaffold_plan` and `scaffold:method_scaffold_plan`.
- Review blind-spot coverage asserts Recall's current report wording, which the
  0.13.16 pin had hidden.

### Added

- Skills shipped by `voku/agent-session` are projected by `init install-assets`
  alongside Loop's own and Recall's. The two siblings are wired asymmetrically on
  purpose: Recall has shipped skills for as long as this projection existed, so
  failing to locate it stays a hard error, while agent-session only ships skills
  from the release that introduced its own `PackageResources`. An older installed
  agent-session therefore contributes nothing instead of being reported as
  breakage, and `resolve()` never hands callers a root that does not exist -
  `RepositorySetupService::expectedSkillEntries()` treats every resolved root as
  mandatory.

### Changed

- `FirstPartySkillRoots::recallSkillEntries()` becomes `siblingSkillEntries()` and
  returns the merged, sorted, de-duplicated contribution of every sibling owner
  package rather than Recall alone.
- The first-party install tests derive the expected source-root count from
  `FirstPartySkillRoots::resolve()` instead of asserting the literal
  `from 2 source root(s)`, so wiring a sibling in no longer breaks assertions
  that were only ever about *extra* roots not leaking in.
- Adapt to sibling package asset moves in `voku/agent-recall-compiler`: resolve Recall consumer skills via `PackageResources::skillsRoot()` with fallback to `resources/skills/` and `skills/`. Update documentation, workflows, and dogfood prompt references to `vendor/voku/agent-recall-compiler/resources/skills/`.
- Require `voku/agent-learning ^0.15.0`, which makes `agent-loop learn proposal-reanchor <target> --by ACTOR --reason TEXT` available. An applied `memory`/`skill` proposal pins its whole target file by hash, so editing any other row of a shared guidance home such as `MEMORY.md` made every applied proof on that file report drift it did not cause, and `agent-loop verify` failed with no way back: retiring answers a curation question nobody asked and re-applying is closed to an applied record. The repair is the owner's, reached through the existing `learn` delegation rather than reimplemented here.

## 0.18.6 - 2026-09-04

### Fixed

- Require `voku/agent-learning ^0.14.2` on the maintained 0.18.x line so released consumers can use Learning 0.14 owner APIs without an unsatisfiable Composer graph. No Loop runtime behavior changes.

### Changed

- **Breaking.** Package-shipped assets moved out of `docs/` and the repository root into `resources/`, so `docs/` is human explanation only and `resources/` is what the package ships. `docs/agents/skills` -> `resources/skills`, `docs/agents/subagents` -> `resources/subagents`, `docs/agents/codex-hooks` -> `resources/hooks/codex`, `docs/agents/claude-hooks` -> `resources/hooks/claude`, `docs/agents/tools` -> `resources/tools`, `docs/agents/project-instructions.md` -> `resources/instructions/project-instructions.md`, `docs/agents/recall-documents.json` -> `docs/recall-documents.json`, `githooks/` -> `resources/githooks/`, `make/agent-loop.mk` -> `resources/make/agent-loop.mk`, and `resources/operating-prompts.json` -> `resources/prompts/operating-prompts.json`. There are no forwarding copies; a stale path fails loudly.
- **Breaking.** The default asset source roots resolved from `.agent-loop/init.json` (`paths.skills_root`, `paths.subagents_root`, `paths.codex_hooks_root`, `paths.claude_hooks_root`, `paths.tools_root`) now default to the same `resources/...` layout instead of `docs/agents/...`. A repository that kept its own assets at the previous default either moves them or names the old location explicitly in `paths`.
- Added `voku\AgentLoop\PackageResources` as the single owner of package-shipped resource locations. Setup, hook, prompt and review code resolve asset paths through it instead of each spelling a physical directory.
- Human documentation moved to predictable category paths: `docs/agents/LIFECYCLE.md` -> `docs/workflow/lifecycle.md`, `docs/agents/INFO_Agents.md` -> `docs/reference/agent-assets.md`, `docs/agents/PROMPT_PRIMITIVES.md` -> `docs/reference/prompt-primitives.md`, `docs/agents/THIRD_PARTY_NOTICES.md` -> `docs/reference/third-party-notices.md`, `docs/agents/project-integrated-phpstan-tools.md` -> `docs/reference/project-integrated-phpstan-tools.md`, the capability matrices to `docs/architecture/`, `docs/agents/policies/` -> `docs/policies/`, and `docs/agents/dogfood/` -> `docs/dogfood/`.

### Fixed

- `agent-loop enter` text output now renders the exact candidate Contract revision and complete goal before its human approval command, matching the structured decision projection.
- Require `voku/agent-learning ^0.14.2` so governed Runs with multi-segment ad-hoc task IDs can record Findings bound to their exact task and Session lineage without weakening those checks.

## 0.18.5 - 2026-09-01

### Added

- `agent-loop workflow plan`: Display the task goal and file scope in CLI output upon planning or revising a candidate contract so human review has immediate goal context before approving.
- `agent-loop finish`: Added `--recall-outcome-draft` support to optionally delegate Recall outcome logging directly during the finish command.
- `make/agent-loop.mk`: Added `agent_init_tools` target to probe and cache CLI tool availability.
- `agent-loop init install-assets`: Added `--config` and `--extra-subagents-root` support. Configured repository skills (`paths.skills_root`) and subagents (`paths.subagents_root`) from `.agent-loop/init.json` are now automatically detected and merged with first-party package guidance instead of being marked as stale and removed.
- `agent-loop init install-assets`: Added `package_skills` and `package_subagents` configuration options in `init.json` (as well as `--no-package-skills` and `--no-package-subagents` CLI flags) to allow repositories with their own adapted skill suites to disable first-party package skills and avoid context budget warnings.
- `agent-loop init sync-subagents`: Added support for multiple `--subagents-root` directories.

## 0.18.4 - 2026-09-01

### Added

- Extend the immutable `WorkflowPromptEnvelope` re-entry projection with the current approved Contract goal and a bounded `continuity_anchor` containing only the newest durable checkpoint from the exact Session identity selected by the current Run manifest.
- Consume the released `agent-map 0.9` plan surface as a governed host, including `parameter_rename_plan@1.0` and `class_move_plan@1.0`, with shared hash-bound transactional application, rollback, current-Map verification, and clean installed-consumer lifecycle proof.

### Changed

- `WorkflowPromptService::continueTask()` renders the approved goal and latest durable checkpoint before current state and canonical next action, while the host-facing envelope schema remains explicit at `1.1` and existing positional construction stays compatible.
- PHP navigation is adaptive rather than universally Map-first or CLI-first: use focused CLI reads for already-localized/literal facts, use `agent-map` for structural and relational questions, prefer an already-fresh Map, and do not pay a cold build merely to satisfy policy.
- Tighten owner boundaries by consuming Session state through its typed handoff projection, routing Recall document manifests only through `ProjectLayout`, and keeping `itp-context` architecture metadata and dogfood helpers in the development graph rather than production autoload.
- Move the supported consumer floor to released `voku/agent-map ^0.9.0` and `voku/agent-recall-compiler ^0.13.16`; installed refactor dogfood exercises the released package set rather than sibling or `dev-main` implementations.
- Review guidance now treats Loop/Recall `review code` / `review first-draft` as the guaranteed default correctness-review capability. Installed `code-review-*` engineering lenses may deepen one dominant concern but their absence does not block an otherwise executable review. Governed close-out remains owned by `finish`; the ungoverned path reaches `review first-draft` before any task-bound status call.

### Fixed

- Make first-party managed-asset provenance portable across checkout/vendor relocation. Manifest v3 persists package-relative `source_reference` values for Loop/Recall-owned assets, resolves them against the currently installed owner root, keeps v1/v2 readable until resync, preserves SHA-256 drift detection, rejects unsafe references fail-closed, and represents the package root itself explicitly as `.`.
- Surface the default Kanban false-green where a linked task is in `DOING` but Loop has neither a Contract nor a governed Run. Custom board topologies remain uninterpreted rather than acquiring a second hidden lifecycle mapping.
- Keep the Learning follow-up command template executable by advertising the conditional `--follow-up-ref` input required by `follow_up_required` close-out.
- Scaffolded workflow state ignores per-Run execution lock files; the locks remain synchronization residue and are not deleted in a way that could break inode-based exclusion.
- Class-move publication may create only the required destination directories inside the Map root and restores them on rollback; post-apply verification binds the exact plan digest and current rebuilt Map instead of comparing pre-mutation provenance to post-mutation identity.

### Validation

- PR #329 restored the complete pre-corruption release history byte-for-byte from the immutable pre-#325 `CHANGELOG.md` blob before this section was re-prepared; no historical release prose is reconstructed from memory.
- The original re-entry slice in PR #324 passed PHP 8.3/8.4/8.5 CI, diagnostics, PHPStan/project rules, installed release-set/refactor lifecycles, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint, and CodeRabbit on exact head `12d75916b3b3ecc1928981b0e9c3e6ca9ff22bea` before merge.
- The complete 0.18.4 release candidate is gated again on exact-head PHP 8.3/8.4/8.5 CI and diagnostics, PHPStan/project rules, installed release-set and refactor lifecycles, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint, and review checks before any tag marker may name the immutable release target.

## 0.18.3 - 2026-08-28

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

## 0.18.2 - 2026-08-26

### Added

- Publish immutable deterministic `WorkflowPromptEnvelope` projections and the read-only `WorkflowPromptService::startTask()` / `continueTask()` embedding boundary, including current Contract/Run/Recall lineage, owner disagreements, canonical next action, and explicit mutation authority without parsing CLI prose or mutating workflow state.
- Track acceptance-criterion observation coverage as durable Contract-side semantics without creating a second Session/execution evidence authority.

### Changed

- After the exact task Contract has been explicitly approved, ordinary review acknowledgement and Learning disposition are model-owned command-template work by default; Contract changes, accepted risk, destructive/irreversible actions, and genuinely new product intent remain human authority boundaries.
- Keep the HTML review workbench visible through neutral `review_presentation` metadata even when review acknowledgement is delegated post-approval, and retain `WorkflowHumanDecisionService` for stricter/manual hosts.

### Fixed

- Restore margin under the bounded agent-discipline context budget after navigation/tooling guidance expansion.
- Keep workflow prompt-envelope runtime guards fail-closed and PHPStan-valid while preserving immutable deep-snapshot digest provenance.

### Validation

- PR #306 passed PHP 8.3/8.4/8.5 CI, diagnostics, PHPStan/project rules, installed release-set/refactor lifecycles, governed execution-contract dogfood, deterministic slop review, and self-shape before merge.
- PR #309 merged the typed prompt-envelope boundary after exact-head lifecycle/PHPUnit/PHPStan verification; the subsequent context-budget and runtime-guard fixes plus acceptance-observation regressions are included in this release target.

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
- `init status` trusts persisted v2 first-party projection source evidence, so a clean installed consumer no longer reports freshly installed skills, subagents, or hook helpers as stale managed entries. The projected set was compared against this repository's skills root alone, so a successful install immediately produced a warning about itself.
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
- `init tools` now inventories optional RTK availability so agents can use it at the outer shell boundary for compact command output.

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
  plan/context/status/report/close` and `agent-loop verify` always resolve the
  same path.
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
  `0.1.*@dev` to pick it up — this repo's own code needed no other change, since
  the card directory is entirely owned and resolved by `voku/agent-kanban`.
- `verify` is now a cross-package consistency check (`AgentLoopVerifier`):
  tasks, board, session/recall linkage with hash-based staleness
  detection, and the learning root, each skipping itself when its inputs are
  absent. The previous board-only check remains available as
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
