# TDD test-quality dogfood: required evidence

This protocol targets a real gap: an implementation and tests may agree while both disagree with the approved requirement. It does **not** introduce a Loop stage, owner, or mandatory mutation tool.

## Ownership

- `agent-skills/testing-best-practices`: portable oracle-independence and resilience principles.
- `agent-recall-compiler`: RED/GREEN/REFACTOR operating recipe and guaranteed first-draft falsification framing.
- `agent-loop`: selected-Contract/Run binding, existing fresh correctness review, candidate identity, feedback, and verification.
- Consumer project: actual framework-specific tests and behavior. Do not project private consumer assertions into Loop.

## Discriminating experiments

Use a real consumer test seam with approved intended behavior, then inspect its original test and implementation independently of the generator's rationale.

1. **Pre-GREEN test challenge:** identify a different correct implementation that should satisfy the test and one plausible wrong implementation that must fail. A test that mirrors production code, or checks only visible button copy, is insufficient for an action/navigation requirement.
2. **Baseline:** execute the focused test against the original behavior, record command, environment, exit status, and candidate hash.
3. **Benign change:** in a disposable isolated checkout, modify an incidental translation or HTML wrapper only when this is *not* part of the intended contract. The behavioral test should still pass.
4. **Behavioral fault:** independently disable required navigation or bypass a relevant authorization guard. The focused test should detect the contract violation.
5. **Restoration:** restore both temporary changes. Re-run the baseline; no mutations or artifacts may contaminate the governed candidate.
6. **Review:** run the current independent correctness reviewer on the exact candidate; distinguish source-backed findings, observed executable results, and UNKNOWN/NOT_RUN claims.

A secure data-export consumer case is a useful second scenario: include one allowed export and one unauthorized cross-region export. A UI-label-only assertion cannot establish either outcome.

## Post-implementation test disposition

The existing correctness review also evaluates **tests as deliverables**, not just the code they helped produce:

- **Regression tests:** keep focused, independent assertions about approved behavior, invariants, authorization, and failure handling in the consumer's normal test suite/CI. Promote useful implementation-time checks when they meet that standard.
- **Work probes:** allow quick exploratory scripts, assertions, and temporary mutations during implementation, but do not mistake their passing result for a durable test oracle. Repair brittle or self-confirming checks rather than automatically promoting them.
- **Diagnostic evidence:** retain a *bounded, sanitized* record of useful probes (purpose, command, observed outcome, important misses/limitations, exact candidate) when it explains what the agent checked or failed to check. Do not blindly delete all probes or commit transient mutations, credentials, or private reasoning.

Review the existing checks before replacing or deleting anything. Do not introduce a mandatory new agent, stage, permanent archive, or CI gate solely for this classification. Prefer the existing review and feedback loop; only durable regression tests become normal CI obligations.

## Claim boundaries

- A receipt containing a recipe id, prompt text, or `PASS` label proves only transport/schema checks actually exercised.
- A fresh review context improves independence of reasoning but cannot validate a test oracle by itself.
- A killed synthetic mutant is bounded evidence for that fault shape, not universal test quality.
- No fabricated review approvals, no forced coverage percentage, and no stage/policy changes.
- Prefer the existing Loop `correctness-review -> build` feedback transition when evidence shows inadequate tests.
- If the Recall owner change has not been released to the installed consumer, mark test-quality prompt delivery **NOT_YET_PROVEN**, even if the baseline TDD recipe still compiles.

## Required CI/report artifacts

Record exact tested commit/tree, installed Recall package version, focused test command, benign probe command/outcome, behavioral fault command/outcome, restoration status, and reviewer verdict. A missing capability or skipped probe remains UNKNOWN rather than being counted as a pass.
