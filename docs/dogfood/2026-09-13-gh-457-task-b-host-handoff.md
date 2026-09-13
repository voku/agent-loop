# GH-457 Task B: real-host L2-to-L1 handoff

## Result

This is a bounded real-host continuation of issue [#457](https://github.com/voku/agent-loop/issues/457), not proof that the whole learning thesis is closed.

- **VERIFIED:** an active owner-published LearningNote was selected by Recall, appeared in the generated L2, and was used to construct and bind the exact L1 that the current Codex host then executed.
- **VERIFIED:** Loop blocked host mutation until that L1 was bound to the current approved Contract and Recall bundle.
- **WITHHELD:** that precedent materially improved the host's engineering decision. Both the issue and the user request independently required the same anti-overclaim behavior, so this run has no honest counterfactual for causal value.

## Governed identity

| Item | Value |
| --- | --- |
| Source issue | `voku/agent-loop#457` — open at re-grounding |
| Task B | `GH-457-Task-B`, Contract revision 1 |
| Approval | Lars Moelleken, 2026-09-13T08:45:17Z |
| Run | `run:GH-457-Task-B:9328ab0348cd8314` |
| Session | `2026-09-13-gh-457-task-b-r1-7478f421` |
| Scope | This document only |
| Acting host | Current local Codex session |

The closed, unmerged [PR #461](https://github.com/voku/agent-loop/pull/461) remains deterministic-preflight evidence only. It intentionally stopped before an L1 or real host; it is not used as Task-B success evidence.

## Trace

| Edge | Evidence | State |
| --- | --- | --- |
| Task A observation | `finding.2026-09-12.457001` | VERIFIED validated Finding |
| Durable precedent | `learning-note.2026-09-12.457001`, pattern `workflow.closed_loop_requires_real_precedent_handoff` | VERIFIED active owner state |
| Learning -> Recall | `learning-precedent.learning-note.2026-09-12.457001` in Task-B `facts.json`; `evidence_state=current`; match reason `tag_match` | VERIFIED |
| Recall -> L2 | `compilation.GH-457-Task-B.2026-09-13-084517.7b131090`; bundle `a8e508353114f274b8d04298053acc579451c595e5497df036d95c7f99d941b7`; `system.md` contains the precedent and the L2 `execution-dispatch` recipe | VERIFIED |
| L2 -> L1 | owner-bound `.agent-loop/recall/GH-457-Task-B/execution-contract.md`; SHA-256 `59982134d4ad547e3e7e7205341c0445b43a788aefd258b2672e15a108829f2f` | VERIFIED |
| L1 -> host | The current Codex host read the generated L2, authored the required five-section L1, bound it as `created_by=codex`, re-read the owner-bound document, and then created this report | VERIFIED host-run evidence; no independent host telemetry |

## Observed Task-B decision and attribution

The L1 instructed the host to avoid framework work and to write only this evidence report. The host did so: it did not revive PR #461's harness, add a Runner dependency, modify product code, promote a LearningNote, or create a deterministic rule.

That restraint is a real observed decision, but it is **not** evidence that the selected LearningNote caused it. Issue #457, the approved Contract, and the user request all independently require the same restraint. The honest guidance-outcome classification is therefore **WITHHELD**, rather than `DEMONSTRATED_VALUE`, `NO_DEMONSTRATED_VALUE`, `irrelevant`, or `not_used`.

The selected precedent did provide an executable evidence checklist: preserve the Note, compilation, L1 hash, host handoff, and observed decision; do not upgrade prompt inclusion into usefulness. That is exposure and compatible reasoning, not causal attribution.

## Dream, human authority, and deterministic enforcement

Before Task-B outcome logging, `agent-loop learn dream` observed 11 evaluated guidance records, one judged selection outcome, no warnings, no review decisions, and 100% completeness for the already-recorded outcome. It explicitly states that immutable history and structural validity cannot prove a human candidate should be accepted.

No Dream decision, proposal, promotion, retirement, or deterministic rule is created by this Task-B slice. Human authority was limited to the named approval of this exact Contract.

The existing `constraint.workflow.recovery.next-action-must-advance` is a separate active lineage from `proposal.2026-08-20.c2a001`. It is neither evidence of this LearningNote's causal value nor tested by this report. This Task B must not pretend to complete #457's later promotion or different-manifestation stages.

## Remaining edge and stop rule

The missing behavioral edge is an independently specified later engineering task where the prior precedent is relevant but the task request does not already prescribe the same decision. That task needs a concrete wrong-work alternative, selected-precedent/L1 evidence, and an observable decision that differs for a reason traceable to the precedent. If no such attribution can be isolated, record `WITHHELD`; do not add framework code to manufacture it.

Validation and closeout evidence are appended only after their commands run. This report is non-authoritative evidence; Loop and the owning packages remain authoritative for lifecycle, Recall, Learning, Dream, and promotion state.

## Validation

- `git diff --check -- docs/dogfood/2026-09-13-gh-457-task-b-host-handoff.md`: passed before the repository gate.
- `composer ci`: passed with exit code 0 on 2026-09-13. PHPUnit reported 1,223 tests and 8,118 assertions, with one warning and one skipped test; the repository's PHPStan, project-rule dogfood, and skill-contract checks also passed.
