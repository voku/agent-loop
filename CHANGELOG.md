# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

## 0.18.4 - 2026-08-28

### Added

- Extend the immutable `WorkflowPromptEnvelope` re-entry projection with the current approved Contract goal and a bounded `continuity_anchor` containing only the newest durable checkpoint from the exact Session identity selected by the current Run manifest.

### Changed

- `WorkflowPromptService::continueTask()` now renders the approved goal and latest durable checkpoint before current state and canonical next action, so resumed hosts can re-orient without reconstructing intent from chat history or parsing private Session files.
- Keep the host-facing envelope schema explicit at `1.1`, while preserving the established positional constructor contract for existing callers.

### Fixed

- Bind continuity strictly to the manifest-selected Session instead of independently selecting another active Session for the same task, preventing plausible but stale re-entry context after Session supersession or closure.
- Missing or unreadable optional orientation data remains `null` / deterministic `unavailable` or `none available`; it never invents approval, verification, review, Learning, accepted risk, or mutation authority.

### Validation

- PR #324 passed PHP 8.3/8.4/8.5 CI, diagnostics, PHPStan/project rules, installed release-set/refactor lifecycles, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint, and CodeRabbit on exact head `12d75916b3b3ecc1928981b0e9c3e6ca9ff22bea` before merge.

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

- Introduce owner-normalized workflow path scope semantics through `ApprovedScope`, making Contract scope persistence and later implementation-snapshot matching use the same canonical relative-path rules.

### Changed

- Continue the pre-1.0 owner-boundary cleanup by keeping public workflow projections typed and keeping lower-level stores private to their owners.

## 0.16.6 - 2026-08-17

### Changed

- Requires `voku/agent-session ^0.6.1`, whose owner API can rehydrate pruneable working memory at an exact already-authoritative Session identity.

### Fixed

- `workflow approve` now resumes an existing governed Run after its bound pruneable Session disappears by recreating that exact Run-owned Session ID. It no longer generates a fresh date/random Session and then collides with the Run's durable identity.
- Resume refuses a different active Session for the same task and refuses a surviving non-active bound Session instead of silently rebinding durable Run lineage. New Contract revisions still use the existing supersession path.

## 0.16.5 - 2026-08-16

### Changed

- Add a self-removing release helper for immutable tag creation after exact-head validation.

## 0.16.4 - 2026-08-15

### Fixed

- Restore stable governed lifecycle output after context-hook and release-helper changes.

## 0.16.3 - 2026-08-14

### Added

- Added top-level `agent-loop prompt guidance-gaps`, delegating to released `agent-recall-compiler 0.12.2`. The opt-in L2 technique creates a project-specific implementation prompt that maintains task-local `implementation-notes.html` and distinguishes ordinary decisions from actual missing, stale, or conflicting spec, docs, skill, workflow, tool contract, code, or test authority.
- The generic Recall-owned `prompt` namespace also exposes context-light `prompt future-work`; governed Runs should continue to use `workflow reflect`.

### Changed

- Requires `voku/agent-recall-compiler ^0.12.2`.
- Agent-facing prompt primitive docs now explain the guidance-gap journal, `HUMAN_DECISION_REQUIRED` boundary, non-commit default, and the raw-versus- governed future-work reflection boundary.

### Validation

- Clean-consumer prompt dogfood executed the installed `agent-loop prompt guidance-gaps` command and asserted journal, authority, opt-in, blocking, and non-commit semantics against Recall `0.12.2`.
- A caller without evidence can explicitly withhold the judgement with a bounded reason; silent absence still blocks close.
- `workflow close` accepts withholding only from a current `selected=true` selection event, canonicalizes the current selected set, and does not warn by reading a missing `outcomes.jsonl` in a legitimate all-withheld run. An existing unreadable outcome history fails explicitly.
- Self-shape resolves the current Contract revision instead of assuming revision 1, so evidence remains bound after a Contract revision.

### Validation

- PHP 8.3/8.4/8.5, diagnostics and project PHPStan rules, acceptance/prompt-primitives clean-consumer dogfood, installed release-set dogfood, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint and CodeRabbit were green on the exact current-base candidate before merge; the exact merge commit also passed main-branch CI.

## 0.16.1 - 2026-08-14

### Changed

- Requires `voku/agent-recall-compiler ^0.11.6`, whose canonical `agent-recall-consumer` skill now matches the live Recall CLI and compact `.agent-loop` defaults.

## 0.16.0 - 2026-08-14

### Changed

- Requires `voku/agent-learning ^0.11.0`, which allocates record IDs instead of deriving the next number from locally visible files. `agent-loop learn finding-id` now hands out a collision-resistant finding ID, so parallel branches stop allocating the same one.
- `agent-loop map search-index` and `agent-loop map search` now default to the database path `ProjectLayout` owns (`.agent-loop/map/search.sqlite`). They previously fell through to agent-map's retired `.agent-map/` default, which is read by nothing in the governed workflow. Pass `--database` explicitly to keep a custom location.
- `workflow approve` reports when no search index exists, because Recall still compiles without ranked map evidence and a quietly narrower context is indistinguishable from a correct one.

### Fixed

- `init doctor` and `init sync-githooks` recognise a linked Git worktree. Both inferred repository state from `is_dir(<root>/.git)`, but `git worktree add` stores `.git` as a file, so a valid checkout was reported as "no repository" and `sync-githooks` installed six hook files while silently skipping `core.hooksPath` - leaving the hook integration inert in the layout agents work in most.

## 0.15.2 - 2026-08-14

### Changed

- Requires `voku/agent-recall-compiler ^0.11.5`. Recall now owns and ships the first-party operating-prompt catalog beside its consumer skill, so Loop uses `vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json` instead of synchronizing Recall behavior through a separately pinned `agent-skills` commit.
- `agent-loop-l2-context` now owns only Loop orchestration around Recall and defers Recall prompt schema, recipe semantics, and review primitives to the Recall-owned skill. This removes a second Markdown implementation that had already drifted from Recall's current L2 construction contract.

### Learning

- Validated `finding.2026-08-14.005`: skills and machine-readable instruction assets whose correctness depends on a tool's CLI, schema, generated files, output contract, or runtime behavior belong in that tool's repository and ship/test with the implementation. Generic skill collections keep only tool-neutral principles or references to the canonical owner.

### Validation

- The ownership handoff passed PHP 8.3/8.4/8.5 CI and diagnostics, project PHPStan rules, acceptance and prompt-primitives clean-consumer dogfood, governed execution-contract dogfood, installed release-set dogfood, deterministic slop review, and agent-loop self-shape before merge.

## 0.15.0 - 2026-08-12

The pre-1.0 semantic reset (#19). Ownership moves to the packages that can actually keep each promise, and every breaking change here deletes a demonstrated contradiction rather than expressing a preference.

Requires agent-session ^0.5.0, agent-learning ^0.10.0 and agent-recall-compiler ^0.11.0. There is no compatibility shim anywhere in this release: a wrong path or a stale artifact fails loudly instead of being guessed at.

### Changed

- Breaking: PLAN is durable before a Session exists. `workflow plan` writes a Contract and creates no Session and no Run. APPROVE binds that exact approved revision to a governed Run with its own `run_id`, so Run identity is never derived from Session identity.
- Breaking: a governed Run records the durable Learning repository it is governed against. `close`, `learn`, `report` and the Run projection read that binding, and a `--learning-root` that disagrees is refused rather than silently reading a different repository. Previously close gated on the caller's flag while the projection it then wrote re-derived the location from the layout default — close reported success while the durable manifest it produced in the same command said the Learning decision was missing, and that contradiction outlived Session pruning.
- Consumes agent-map 0.5 discovery, 0.6 architecture discovery and 0.7 temporal evidence.

### Upgrading

See `UPGRADING.md`. Run artifacts written before this release are rejected by name; re-run `workflow approve` to re-prepare the Run against the same approved Contract revision. Durable Contracts, verification receipts and Learning decisions are untouched.

Note: 0.14.0 was prepared but never released; its content is superseded by this release.

## 0.13.0 - 2026-08-07

- Publish a joined workflow lifecycle view and related owner-bound orchestration improvements.

## 0.12.0 - 2026-08-06

- Release workflow consistency and packaging corrections.

## 0.11.0 - 2026-08-06

- `workflow status` is now one joined lifecycle view instead of six independent green lights. It names every stage - session, work brief, recall, edit bundle, review, learning - and ends with the single next command for wherever the task actually stands. A repository that never ran `agent-map search-index build` produces exactly the same briefing as before - the derived index is a cache, and its absence is not an error.

## 0.10.1 - 2026-08-05

- Patch release for workflow behavior and packaging alignment.

## 0.10.0 - 2026-08-05

- Release governed workflow behavior accumulated since the previous tag.

## 0.9.2 - 2026-08-05

### Changed

- Requires `voku/agent-map` `^0.4.0` and `voku/agent-recall-compiler` `^0.8.1`. The edit orchestration and map-refresh paths are unchanged; the bump is what unblocks the 0.4.x agent-map line for the whole tree, including the derived hybrid-search index and the parallel chunk extraction added there.

## 0.9.1 - 2026-08-05

### Fixed

- `workflow help` advertised `--accept-risk <reason>` on its own, which 0.9.0 refuses. The usage line now shows the required `--accept-risk-by <name>` as well; a CLI that documents a flag combination it rejects is worse than one that documents nothing.

## 0.9.0 - 2026-08-05

### Changed

- `workflow close` now gates on edit verification: when `.agent-loop/edit/<task-id>/` exists it must contain a `verification-result.json` with status `passed`.

## 0.7.0 - 2026-08-05

### Changed

- Requires `voku/agent-recall-compiler` `^0.8.0`, which emits `verification-plan.json` and the verifier-owned `verification-key.json`.
- `agent-loop edit` now writes `agent-result.json` into the edit bundle: the structured answer sheet a verifier grades against the private key. It records the plan the edit is bound to (`verification_plan_sha256`), the `changed_files` observed by diffing a working-tree snapshot taken before the runner against one taken after, the commands this process actually invoked with their exit codes and stdout hashes, and the runner outcome; and it seeds one empty slot per knowledge probe and checklist item so an unanswered probe is visibly unanswered instead of silently absent.
- The result file carries no expected answers, scores, verdicts or generated learnings by design, and `changed_files_source` separates an empty diff from an unreadable repository. `execution.json` gained an `artifacts.agent_result` pointer.
- A repository without Git, or a Git invocation that fails, yields an unavailable snapshot rather than a failed edit.

## 0.6.8 - 2026-08-04

### Changed

- Requires `voku/agent-map` `^0.3.0` and `voku/agent-recall-compiler` `^0.7.2`.
- Added `agent-loop map refresh`, which re-analyses only changed or new files and patches them into the existing index instead of rebuilding the whole scope. Like `map build` it defaults `--root` and `--out` to the dispatcher root; previously only `build` did, so a `refresh` reached through the dispatcher would have resolved both against the current working directory and written the refreshed index somewhere other than where it read it from.
- Documented that `--paths` should stay on directories: PHPStan disables its result cache as soon as it is handed individual files, which makes every rebuild a cold rebuild.

## 0.6.7 - 2026-08-03

### Changed

- Added `edit --phpstan-memory-limit`, forwarding an explicit positive limit to `agent-map` while rebuilding the PHPStan semantic map.
- Added repeatable `edit --focus=TEXT`, recorded in the execution request and compiled into bounded primary-source windows for literal, surgical changes; this mode omits optional relation slices.
- Bumped the `voku/agent-kanban` constraint to `0.2.*@dev`.

## 0.2.7 - 2026-07-06

### Changed

- `init install-plan` now prompts installing and verifying ripgrep (`rg`) alongside RTK and Caveman.

## 0.2.6 - 2026-07-06

### Changed

- Updated `voku/agent-learning` dependency to 0.8.0.

## 0.2.5 - 2026-07-06

### Changed

- Updated Skills files for the workflow of this package.

## 0.2.4 - 2026-07-01

### Changed

- Updated Skills files for the workflow of this package.

## 0.2.3 - 2026-06-29

### Added

- Added Skills files for the workflow of this package.

## 0.2.2 - 2026-06-29

### Added

- Added support for at least Windows paths (`init install-plan --profile=windows`).

## 0.2.1 - 2026-06-29

### Added

- Updated install-plan, validation, dispatcher defaults, and smoke coverage for the supported host workflow.

## 0.0.3 - 2026-06-20

### Changed

- Bumped `voku/agent-recall-compiler` dependency to version 0.5.*.

## 0.0.2 - 2026-06-20

### Added

- Added `voku/agent-session` library integration.

## 0.0.1 - 2026-06-20

- Initial release.
