# Trial: risk-based reduction of redundant validation and review (T10 follow-up)

Date: 2026-11-21. Input: the T10 runs R6–R8 ([record](2026-11-21-recall-map-real-use.md)). The question was which full validations and reviews in a governed Run are redundant, and which can be dropped without hiding a defect class. A blanket rule such as "test-only means light" was ruled out from the start.

## 1. Measured: the same validation runs twice on the same content

R8 (`OBS-A958DD`, standard profile, in-place, no Runner):

| Time | Who | Command | Bound to |
| --- | --- | --- | --- |
| 21:03–21:06:29 | `finish` (`WorkflowValidationRunner`) | `composer ci`, 193.6 s, passed | Session `ValidationEvidence`, implementation snapshot `sha256:7f393da1…` (Contract scope files) |
| 21:07:19–21:14:05 | investigate, build, two fresh reviews | – | candidate `git-tree-v1:686ff255…:12afd7d7…` |
| about 21:14–21:17:33 | `verify` (`ExecutionGateway::runDeterministicStage`) | `composer ci` again, passed, **in place** (no export) | `ExecutionEvidence` on the same candidate |

The operator recomputed both identities on the closed workspace. The scope snapshot is still `7f393da1…`, and the whole tree is still `12afd7d7…`. Both runs validated the same content with the same command in the same checkout. The second run, about 3 min 15 s, observed nothing the first had not. R7's record shows the same order (`finish` after an edit, then `verify`). Its workspace is gone, so identity there is not re-proved.

The host's own two `composer ci` runs before `finish` (R8, and R6/R7) are a separate cost. `WorkflowValidationRunner` already skips a command that passed on the current snapshot, but evidence recorded through the host's ad hoc runs never reaches the Session, so those runs count for nothing.

## 2. What the two runs check differently

Removing either run is safe only where the remaining run checks at least what the removed one did.

| Property | `finish` validation | `verify` |
| --- | --- | --- |
| Content identity | Contract **scope** files only (`ImplementationSnapshot`) | the **whole** tree, including untracked files and excluding `.agent-loop` state (`CandidateWorkspaceIdentity`) |
| A command that mutates during the run | refused if it changes scope files | refused if it changes **anything** in the checkout (in place) |
| Foreign files present | ignored | validation runs on an exported copy |
| Workspace | always the project root | the root or a Runner candidate worktree |
| Cross-package consistency check | no | yes (`agent-loop verify`) |
| When it runs | before the reviews (fails fast) | after both fresh reviews (about 170k reviewer tokens in R8) |

Consequences:

- `verify` cannot simply reuse a `finish` pass. A change outside scope between the two runs would go unseen, for example `composer.json`, `phpstan.neon.dist`, or a test helper. Whether a file is in scope is the Contract author's choice. R8 happened to list `phpstan.neon.dist`, but a Contract that leaves out a configuration file the host edits anyway would validate content that neither the snapshot nor the Contract names.
- `finish` cannot simply defer to `verify` either. A failing validation would then surface only after both reviews had been paid for, which loses the fail-fast property.

## 3. Proposed rule (not implemented; needs its own Contract)

When `finish` runs a declared command, it also records the whole-tree candidate identity before and after the run, as a Loop-owned receipt: `{run_id, contract_revision, command, base_commit, tree, exit}`. The run must leave the whole tree unchanged. `verify` may then skip re-running that command, recording `ExecutionEvidence` that names the reused receipt, **only if all of these hold**:

1. The Run, Contract revision and declared command list match.
2. `git-tree-v1:<base>:<tree>` from the receipt equals the accepted candidate (`bundle->candidateRevision`), and the candidate still holds in the workspace now.
3. `verify` runs in place in the project root. That means no foreign files (no export) and no Runner candidate worktree.
4. The receipt passed, and the whole tree was unchanged across the run.

The cross-package consistency check still runs every time, because it is cheap. Everything else falls back to today's full run. Provenance stays explicit: the evidence says "reused", never "ran".

**Residual risk, stated rather than hidden.** Ignored files such as `vendor/` and tool caches are in neither identity. A dependency change between the two runs without a `composer.lock` change would go unseen. Today the second run catches it only by accident, and neither path checks it on purpose. The rule also gives up a second run as an accidental flakiness detector. Both are acceptable only if the owner accepts them explicitly. If not, add the lock and installed-package digest to the receipt.

Expected saving: one full validation per governed in-place Run whose candidate did not change between `finish` and `verify`. In R8 that is about 3 min 15 s of 4 runs. The host's ad hoc runs remain, and are a host-guidance question, not a gate question.

## 4. Review depth: no reduction supported

| Run | Change | Fresh-review result |
| --- | --- | --- |
| R7 | test-only (two test files) | 2 real observations → #1123 (fixed, released in agent-learning 0.22.0) |
| R8 | one PHPStan rule, a baseline, a fixture | 5 real rule limits → #1128 |

Both "small" tasks produced real defects in fresh review. That includes R7, which a "test-only = light" rule would have skipped. On this evidence, no review stage should be dropped by change type. The cost is real, about 86k–88k tokens and about 1 minute per reviewer. The data supports lowering it by **shrinking each review prompt**, not by skipping reviews. Today every stage prompt carries the full 34 KB Recall briefing, which contained 0 selected guidance in all three runs. That belongs to Loop/Recall prompt composition (#174), and should be measured as a change in review findings, not assumed.

## Decision needed

The rule in section 3 changes evidence authority in `verify`, so it needs its own approved Contract. Section 4 is a negative result and changes nothing.
