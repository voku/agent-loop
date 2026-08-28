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

- Normalize Contract scope paths through the owner API before persisting or comparing them, so semantically equivalent relative paths do not drift during implementation snapshot matching.

### Validation

- PHP 8.3/8.4/8.5, diagnostics, project PHPStan rules, installed release-set/refactor lifecycles, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint, and CodeRabbit were green on the exact merge candidate.

## 0.17.0 - 2026-08-22

### Added

- Introduce owner-normalized workflow path scope semantics through `ApprovedScope`, making Contract scope persistence and later implementation-snapshot matching use the same canonical relative-path rules.

### Changed

- Continue the pre-1.0 owner-boundary cleanup by keeping public workflow projections typed and keeping lower-level stores private to their owners.

## 0.16.6 - 2026-08-17

### Fixed

- `workflow approve` now resumes an existing governed Run after its bound pruneable Session disappears by recreating that exact Run-owned Session ID. It no longer generates a fresh date/random Session and then collides with the Run's durable identity.
- Resume refuses a different active Session for the same task and refuses a surviving non-active bound Session instead of silently rebinding durable Run lineage. New Contract revisions still use the existing supersession path.

## 0.16.5 - 2026-08-16

### Changed

- Add a self-removing release helper for immutable tag creation after exact-head validation.

## 0.16.4 - 2026-08-15

### Fixed

- `commit-msg` now judges the message Git will store instead of the file the hook is handed.

## 0.16.3 - 2026-08-14

### Changed

- Requires `voku/agent-recall-compiler ^0.12.2`.
- Agent-facing prompt primitive docs now explain the guidance-gap journal, `HUMAN_DECISION_REQUIRED` boundary, non-commit default, and the raw-versus-governed future-work reflection boundary.

### Validation

- Clean-consumer prompt dogfood executed the installed `agent-loop prompt guidance-gaps` command and asserted journal, authority, opt-in, blocking, and non-commit semantics against Recall `0.12.2`.
- A caller without evidence can explicitly withhold the judgement with a bounded reason; silent absence still blocks close.
- `workflow close` accepts withholding only from a current `selected=true` selection event, canonicalizes the current selected set, and does not warn by reading a missing `outcomes.jsonl` in a legitimate all-withheld run. An existing unreadable outcome history fails explicitly.
- Self-shape resolves the current Contract revision instead of assuming revision 1, so evidence remains bound after a Contract revision.
- PHP 8.3/8.4/8.5, diagnostics and project PHPStan rules, acceptance/prompt-primitives clean-consumer dogfood, installed release-set dogfood, governed execution-contract dogfood, deterministic slop review, self-shape, AccessLint and CodeRabbit were green on the exact current-base candidate before merge; the exact merge commit also passed main-branch CI.

## 0.16.1 - 2026-08-14

### Changed
