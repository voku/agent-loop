# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Changed

- Move the governed Map consumer boundary to released `voku/agent-map` ^0.9.0 and `voku/agent-recall-compiler` ^0.13.16. Loop now consumes `parameter_rename_plan@1.0` and the distinct structural `class_move_plan@1.0` without reproducing owner planning semantics, while exact edit/move staging, lint, atomic publication, rollback and deterministic verification remain host-owned.
- Replace the historical Map 0.8 path-candidate refactor dogfood with clean installed-consumer coverage that resolves released Map/Recall packages normally, pins all ten `plan-capabilities`, and exercises method rename, parameter rename and class move through the governed Loop lifecycle. Existing released removal/refactor consumers now exercise Map 0.9.0.

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

- Move ordinary review acknowledgement and Learning disposition to model-owned command templates after explicit Contract approval, while preserving human authority for Contract changes, risk acceptance, destructive/irreversible actions, and genuinely new product intent.

### Fixed

- Preserve host-visible review presentation metadata when review acknowledgement is delegated to the model.

### Validation

- PR #303 passed PHP 8.3/8.4/8.5 CI, diagnostics, governed execution-contract dogfood, installed release-set/refactor lifecycles, deterministic slop review, self-shape, and exact-head workflow validation before merge.


## 0.18.0 - 2026-08-25

### Added

- Add `agent-loop init assets` and `agent-loop init status` to project the owner-shipped agent assets and expose one deterministic installation/health boundary for hosts.
- Add `agent-loop init doctor` to diagnose owner asset drift and environment capability without silently repairing either.
- Add `agent-loop workflow prompt start|continue` and the immutable `WorkflowPromptEnvelope` so embedding hosts can obtain bounded model prompts without scraping human CLI output.

### Changed

- Treat `docs/agents` as the canonical owner source for generated agent guidance while keeping projected repository artifacts explicitly ephemeral.
- Tighten host/runtime ownership so environment observation stays read-only and mutation remains behind the governed Loop edit boundary.

### Fixed

- Preserve exact execution-contract provenance across resume/reopen paths and fail closed when runtime evidence no longer matches the approved Contract.

### Validation

- 0.18.0 release preparation passed the supported PHP matrix, installed release-set dogfood, deterministic slop review, self-shape, and governed execution-contract dogfood.
