# T10: Recall and Map in real use (R6, R7; R8 not run)

Date: 2026-11-21. This follows the T10 protocol in [the plan](../plans/2026-11-20-next-steps.md) (section T10).

**Result in one line:** Recall delivered a correct but generic 15 KB briefing to both tasks, and it changed no decision. It arrived after the hosts had already chosen scope and acceptance. Map provided nothing. The real cost and friction came from the lifecycle, not from context: a validation failure caused by the environment sealed the snapshot in both runs. That is now fixed as `finish --revalidate` in #1122.

## Setup

- **Tasks:** two real #1082 tasks, verified open on `main` `6f2e25e` on 2026-11-21.
- **Workspaces:** each a fresh `git worktree` at `origin/main`, with the vendor directory locked at Recall 0.29.7.
- **Hosts:** a sub-agent per task (Claude Code, `general-purpose`). Each got the issue row verbatim, the repository's own instructions, and one authority rule: stop at any human decision or fresh-context stage. No hint about Map, Recall or a lesson.
- **Fresh-context review stages:** separate reviewer sub-agents (`agent-loop-code-reviewer`, `agent-loop-blindspot-reviewer`), launched by the operator.
- **Contract approvals (D7):** given by the owner in the operator session. The owner answered once for R6 and authorized R7/R8 within their #1082 rows. The operator recorded `--by voku` only after that answer, because the auto-mode classifier had refused an earlier attempt to record an approval without one.
- **Bias:**
  - The operator wrote the issue-row prompts.
  - The R6 host was restarted once after a container restart and received a two-line state summary.
  - R7's issue row itself names the MEMORY row "Workflow session invariant", so the lesson Recall could deliver was already in the task text. Any Recall effect on R7 is therefore confounded, the same way as #457 lane A.

## Per task

| | R6 `2026-10-09.c2f7eb` (small, obvious) | R7 `2026-08-19.5e1f07` (prior knowledge) |
| --- | --- | --- |
| **Outcome** | `tests/Ci/ComposerScriptsShapeTest.php` (new) | `tests/WorkflowRunPreparerActiveSessionTest.php` (new), `tests/NonAuthoritativeManifestCacheTest.php` (+31/−4) |
| **Correct?** | Yes, checked by the operator independently. The test passes on the real `composer.json`. When the real entry is changed to `@putenv AGENT_LOOP_VERIFY_RELEASE_PIN_SHAS=1 OTHER=2`, it fails with a message naming that entry. | Yes, checked by the operator independently. Both tests pass. With the refusal in `WorkflowRunPreparer::activeSession()` disabled, the new test fails. Two fresh reviews passed. |
| **Governed state** | Contract approved. Validation passed after `--revalidate`, and the review report was acknowledged. **Stopped at pipeline stage 1**: the auto-mode classifier refused the sub-host's `pipeline submit` ("Undermining Oversight"). The operator did not run it for the host. | **Closed** (`complete: true`): standard profile, five stages, `follow_up_required` → #1123. |
| **Recall: provided** (`selection-report.json`) | 0 of 11 evaluated guidance items selected. `system.md` 14,839 B: task, scope, acceptance, the whole `MEMORY.md`, the pre-1.0 ADR. | 0 of 11 selected. `system.md` 15,697 B: the same blocks, plus navigation facts for `NonAuthoritativeManifestCacheTest`. The MEMORY row that matters is included, as one of about 20. |
| **Recall: received** | The host Read `system.md` and `validation-plan.md`. The briefing is also inline in stage prompts. | The briefing was inline in the investigate and build stage prompts, and the host read it there. |
| **Recall: used / impact** | None. The task needed no prior knowledge. | **None demonstrated.** The host made "every choice from reading the source before Recall existed". Recall compiles at `enter`, which comes after `plan` and approval, so scope and acceptance were already fixed. The lesson was also in the task text (see Bias). |
| **Map** | Nothing provided. The index was not built (`enter`: map `missing`). 0 calls. | Same. 0 calls. Navigation facts came from Recall's own source scan. |
| **Elapsed time** | Host 1: 35 s to the Contract stop. Approval wait: about 1 h 36 min, which includes operator work on other lanes and a container restart. Host 2: 654 s to the validation block. Operator fix and `--revalidate`: 3 min. Host 2 resumed for 86 s, until the classifier refusal. | 19:29 → 19:53 (24 min wall), including the approval answer. Host: 787 s in total. Reviewers: 50 s and 47 s. `verify`: 167 s. |
| **Tokens** (as the harness reported each sub-agent; cumulative per resumed agent) | 77,319 + 117,339 | 147,065 (host) + 86,123 + 86,825 (reviewers) |
| **`composer ci` runs** (about 3–4.5 min each) | 2 by the host, 1 by finish (refused as root in 82 ms), 1 by `--revalidate` | 1 by the host, 1 by finish (refused as root in 67 ms), 1 by finish after an edit, 1 by `verify` |
| **Human effort** | One approval answer, which also covered R7 and R8 | Covered by the R6 answer. No other human step. |
| **Proportionate?** | **No.** For one new test file the default `standard` profile asks for five stages, two of them fresh-context reviews. It also started at the base commit after the test had already been written and validated. | **No, for the same reason.** Three full `composer ci` runs for a test-only change. The two reviews did produce two real observations (below). |

Counter (`audit_transcripts.py`, a hit finder, checked by hand):

| Run | `map_cli` | `loop_cli` | `recall_artifact_read` (Read tool) | `plain_search` |
| --- | --- | --- | --- | --- |
| R6 host 1 | 0 | 3 | 0 | 2 |
| R6 host 2 | 0 | 16 | 1 | 9 |
| R7 host | 0 | 16 | 0 | 23 |

Hand check: R6 host 2 reported two Recall reads. The second was a Bash read, which the counter documents as a miss. The `map_cli` 0 is real, because no Map index existed. `plain_search` is not usable for claims.

## Friction, classified by owner

| Observed | Evidence | Owner | Disposition |
| --- | --- | --- | --- |
| **Environmental validation failure seals the snapshot.** `finish` ran `composer ci` as root, Composer refused, and the failure was stored against the unchanged snapshot. `finish` never re-runs it, and `repair` and the next action only say "change the implementation". | Both runs, independently. R6 stopped and refused to game the gate. R7 made an edit "partly to re-trigger validation". | Loop | **Fixed:** `finish --revalidate` (#1122, `8ae129f`). Verified on R6's stuck workspace: without the flag it stays blocked; with it `composer ci` re-ran and passed. |
| **Main silently drops review observations.** The R7 blindspot reviewer passed two `--observation` values, `main` accepted the submit with exit 0, and the stored state has none. | `.agent-loop/runs/OBS-5E1F07/execution-state.json` | Loop | Fixed by #1121 plus the unknown-option refusal in #1122. |
| **Setup activated Git hooks without approval.** R7's host obeyed `main`'s `host-status` action `init install-assets --agent=claude`. In a worktree, that set `core.hooksPath` and `commit.template` in the config shared by all checkouts. | Shared `.git/config`. The operator's next commit was refused by the then-untrusted hook. | Loop | The #1118 split in #1122. Open follow-up: sync writes the shared worktree config. |
| **Learning rejects a task id Loop accepted, at the very end.** `OBS-5E1F07` fails agent-learning's Finding pattern. | `finish --finding-observation` refused: "missing task references" | Learning / Loop | #1123 |
| **Ephemeral Sessions count as active.** An exploration Session blocks governed `prepare()`. | R7 host, both reviewers | Loop / Session | #1123 |
| Recall arrives after plan and approval, so it cannot inform scope or acceptance. | Both hosts | Recall delivery (#174) | Recorded. No change without a second, unconfounded case. |
| The default `standard` profile is disproportionate for test-only Contracts, and the pipeline starts at the base after the work exists. | Both runs | Loop policy (owner decision) | Recorded as a decision, not changed. Choosing a profile is the owner's policy. |
| Small CLI surprises: `workflow approve --help` is parsed as a task id; `session validation record --help` gives no usage text; an accepted review acknowledgement exits 1–2; `pipeline status` calls `pipeline stage` `host_work`. | Host reports | Loop | Recorded only. |
| The classifier refused `pipeline submit` for the R6 host but allowed the same command for the R7 host. | R6 host 2 | Host environment | Not a product defect. Recorded. |

## What this does and does not show

- It shows:
  - two real tasks completed correctly, with independently checked acceptance;
  - Recall's selection is generic (0 selected guidance, the whole of MEMORY.md);
  - the costs that mattered were lifecycle costs: an evidence dead end, repeated full validation, and an unapproved Git activation.
- It does **not** show that Recall or Map are useless:
  - Map was never built, as the AGENTS rule "do not pre-build Map" intends.
  - R7's lesson was already in the task text.
- R8 (`a958dd`, a multi-file change, owner-first through Recall typed path accessors) was **not run**. It needs a Recall release before a Loop change, and this session ran out of room for an honest observation of it.
- Step 2 (an A/B): this record names an anomaly (Recall arrives after the decisions it could inform), but it rests on one confounded case. The next honest step is an unconfounded task: a lesson that is not in the issue text, delivered before `plan`. Building a benchmark is not that step.
