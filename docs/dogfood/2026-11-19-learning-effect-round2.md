# Learning-effect experiment, round 2: a lesson the governed agent gets wrong

Date: 2026-11-19. Follows [Run 1](2026-11-19-learning-effect-pipefail.md), which hit a ceiling: every arm fixed the pipefail lesson, so the precedent could not change anything. Round 2 looks for a lesson that governed arm B (no precedent) actually fails, then compares it with C′ (governed, precedent delivered). Rounds 2 and 3. Per-run data: [`2026-11-19-learning-effect-round2.results.jsonl`](2026-11-19-learning-effect-round2.results.jsonl).

## Method

Same envelope as Run 1:
- in-session agents, the same model as the operating session, no API key, no network;
- a fresh single-commit workspace per run (no history, no remote) at the commit before the historical fix;
- an identical task prompt per case that describes the symptom, not the cause;
- the operator approves each Contract as submitted, logs it in `interventions.jsonl`, and resumes the agent with an identical message. The operator does not answer design questions: one B agent asked whether bundles could be sibling `<task>-*` directories, and got no answer;
- grading uses the historical fix's own regression tests, injected after the run as a hidden `HiddenOracleTest` and run three times on a snapshot of the final tree;
- a transcript audit checks for foreign reads, network use, Loop commands and exposure to the precedent.

Before any agent ran, both oracles were calibrated: red on the baseline and green on the historical fix.

| Case | Lesson | Baseline | Historical fix | Hidden tests |
| --- | --- | --- | --- | --- |
| C1 | finding `5c4992`: the close gate inspects one edit bundle per task, but a multi-plan task has one bundle per plan | `85c53c3` | `53e3776` | 3 tests from `WorkflowCloseReadinessStatusTest` |
| C2 | note `1b47ed` / finding `17c7c3`: a hook in a linked worktree must resolve its own checkout | `a7a8a3b` | `2a52320` | `testPackagedHookHelperKeepsTheLinkedWorktreeRoot` |

**Arm B** removes the pre-fix finding from the workspace store, because it names the cause. **Arm C′** keeps the finding and adds a LearningNote (`learning-note.2026-09-17.c1e5c4`) that the operator wrote only from that finding's pre-fix conclusion and evidence, with nothing from the fix commit. The note is grounded on the baseline's own `WorkflowCloseReadinessInspector.php` and `ProjectLayout.php`. Its guidance says the gate must enumerate "the default task bundle and its sibling per-plan bundles named after the task id" and names the incident's bundles (`ITP-RENAME-TODOS-region-const`, `-acc-throw`, `-acc-names`). A probe workspace confirmed that Recall selects the note by scope match and compiles it into `.agent-loop/recall/REL-003/system.md` after Contract approval.

## Results

### Screening of arm B (one run each)

- **C2:** B was correct (hidden test 3/3). This is a ceiling like Run 1, so C2 was dropped.
- **C1:** B was wrong (each oracle run 2 of 3 tests passing). C1 went on to repetitions.

### C1, three repetitions per arm

| Arm | Correct | Precedent compiled | Precedent read | Mean tokens* | Mean tool calls | Mean minutes | Interventions | `finish` complete |
| --- | --- | --- | --- | ---: | ---: | ---: | ---: | --- |
| B | 0/3 | n/a | n/a | 205,218 | 43.7 | 4.5 | 3 (one approval each) | 0/3 |
| C′ | 0/3 | 3/3 | **0/3** | 227,522 | 61.0 | 6.3 | 3 (one approval each) | 0/3 |

\* Summed over the two agent segments: before approval, and after resume.

All six runs fail the same hidden test the same way. `testMultiPlanEditBundlesWithOneFailingBlocksClose` fails with "Failed asserting that 0 is identical to 2". All six agents treated per-plan bundles as **nested subdirectories** of `.agent-loop/edit/<task>/`. The real incident, and the historical fix (`ProjectLayout::editBundles()`), use **sibling** directories: `<task>`, `<task>-*` and `<task>_*`. A gate that walks only nested directories never sees the sibling bundles, so a failing plan still closes. That is the original bug, so these are genuine failures, not oracle coupling.

The other two hidden tests pass in every run. `testMultiPlanEditBundlesAllVerifiedPassCloseGate` passes only because the sibling bundles are ignored, so it does not discriminate. Each agent's own tests passed, and each fails against the original inspector, so every run looked verified from the inside.

## What this shows

1. **No learning effect, because the precedent never reached the agent.** Recall compiled the note into all three C′ workspaces, and no C′ agent opened it. C′ cannot differ from B when the precedent is never seen, so this result says nothing about whether the lesson would help.
2. **The precedent arrives after the design is fixed.** Every agent, in both arms, wrote the nested-directory design into its Contract goal during `workflow plan`, before approval. Recall runs only for an approved Contract: on current `main`, `HostFrontDoorCommand::prepareApprovedRun()` refuses an unapproved Contract and `WorkflowRunPreparer` compiles Recall only there. So the lesson that would have changed the design is compiled only after the design has been approved.
3. **The precedent is compiled but not surfaced.** After approval, `enter` returns `host_work` and points at `.agent-loop/recall/<task>/meta.json`. It does not name the delivered LearningNote. On current `main`, `enter` projects only precedents that Recall *withheld* (`suppressedLearningPrecedents()`), not the ones it delivered. No C′ agent opened any file under `.agent-loop/recall/` or `.agent-loop/learning/notes/`; they read the code. This repeats Run 1's "delivered is not read" (1 of 3 there), now 3 of 3.
4. **Governance did not catch the wrong design.** The Contract, the human approval, the agent's own tests and the `finish` validation all accepted a fix that misses the incident's layout. No run reached a complete `finish`, because `composer ci` cannot pass in this sandbox (see confounds), but nothing in the gate inspects the bundle layout anyway.
5. **C′ cost more without reading the precedent:** +11% tokens and +40% tool calls versus B. With n=3 and the precedent unread, this is variance in scope (one C′ run added a new class and test file), not an effect of the precedent.

**Next step this points to.** To test whether the lesson helps, the precedent has to reach the agent while it is still choosing the design. That means surfacing scope-matched LearningNotes when the candidate Contract is planned, before approval, and naming delivered precedents in `enter`'s `host_work` response. That change is #1091 (#1093), and round 3 below reruns C′ with it.

## Round 3: the same task with the precedent surfaced (#1091)

#1093 makes `workflow plan` and `enter` name the LearningNotes Recall selects:
- before approval, a Recall preview over the candidate Contract's scope;
- after approval, the notes the compiled briefing renders.

Round 3 reruns only arm C′ on C1, three repetitions. B (0/3 above) is unchanged by the change, because with no matching note nothing is projected.

**Setup:**
- the same baseline, task prompt, operator protocol and operator-authored note as round 2;
- #1091 backported into the baseline's `enter` and `workflow plan`, as part of the start commit, so it is not counted in the agents' diffs;
- the vendored agent-recall-compiler replaced by the #286 branch (0.29.3);
- the vendor tree otherwise identical to round 2's: 5,094 runtime files matched the round-2 checksums, and PHPUnit comes from the same shared copy.

A probe workspace confirmed the projection before any agent ran:
- the `workflow plan` text names the note with its full guidance;
- `enter` names it while approval is pending (`candidate_contract`) and again after approval (`compiled_recall`).

**What the agents did:** all three read the projected precedent during planning. Each revised its candidate Contract to revision 2, with sibling `<task>-*` bundles, before asking for approval. The operator approved revision 2 as submitted (one intervention each). No transcript shows a foreign read or network use.

**Grading:** grading is at three levels, applied to all nine C1 runs:
- **Strict:** the historical test file, unchanged.
- **Relaxed message:** the one exact-message assertion (`missing verification-result.json for edit bundle ABC-123-plan2`) is replaced by two checks: the output names the missing `verification-result.json` and the bundle `ABC-123-plan2`. The round-3 agents print the bundle as a path (`.agent-loop/edit/ABC-123-plan2`), which the exact string rejects.
- **Relaxed message + realistic fixtures:** in addition, the oracle's `execution.json` fixtures carry `{"task_id":"ABC-123"}` instead of `{}`. Every bundle the baseline's edit commands write records its `task_id`, and the verify commands require it. One round-3 agent refuses unbound sibling bundles (fail closed), which the synthetic `{}` fixtures trip.

| Arm (C1, n=3 each) | Strict | Relaxed message | + realistic fixtures | Precedent in agent context | Mean tokens* | Mean tool calls | Mean minutes |
| --- | --- | --- | --- | --- | ---: | ---: | ---: |
| B, no precedent | 0/3 | 0/3 | 0/3 | n/a | 205,218 | 43.7 | 4.5 |
| C′, compiled but not surfaced (round 2) | 0/3 | 0/3 | 0/3 | 0/3 | 227,522 | 61.0 | 6.3 |
| C′ + #1091, surfaced at planning (round 3) | 0/3 | 2/3 | **3/3** | 3/3 | 221,737 | 61.7 | 6.5 |

\* Summed over the two agent segments: before approval, and after resume.

Every B and round-2 C′ run fails the behavioural assertion: close readiness reports ready (exit `0` instead of `2`) while one sibling plan bundle is unverified. Under the realistic oracle, every round-3 run reports blocked for that bundle and lets the all-verified multi-plan task close.

**What this shows:**
- **First evidence of a learning effect in this setup, on the deterministic oracle.** With the same model, task and lesson, delivering the precedent where the agent plans turned 0/6 wrong designs (B plus round-2 C′) into 3/3 correct ones under the realistic oracle. The exact-wording oracle still fails all nine, so the effect is on behaviour, not on reproducing the historical message.
- **Delivery, not the lesson, was the bottleneck.** The note was present in all six C′ runs. It changed the outcome only when Loop named it at the point where the design is fixed.
- **Cost:** about +8% tokens and +41% tool calls over B, roughly the same as round 2's unread precedent. Reading and revising the plan is cheap next to the extra Contract scope (`ProjectLayout` and its tests).

**Limits:**
- n=3 per arm.
- One model, and all agents are the same model as the operating session.
- The note was written by the operator from the pre-fix Finding: the real store had no note for this lesson. Its guidance names sibling bundles explicitly, so this measures delivery of a sharp lesson, not the quality of a lesson the system wrote itself.
- The realistic-fixture oracle is an adaptation, motivated by the baseline's own bundle writers; the strict and relaxed results are reported next to it.
- No governed run reached a complete `finish`, because `composer ci` cannot pass offline here.
- The approving operator also saw the precedent and approved as submitted without acting on it.

## Confounds and deviations

- **Separation:** the agents shared the machine with the repositories that hold the fixes. The transcript audit found no foreign repository read and no network use. All seven agents wrote temporary files to the session scratchpad, outside their workspace.
- **Vendor:** each workspace has agent-loop's current installed runtime packages plus PHPUnit, with lock drift against the baseline and the autoloader regenerated offline. Because the disk was nearly full, the C1 B2/B3/C′ workspaces hardlinked one vendor tree (each kept a private `vendor/composer` and `vendor/bin`). After the runs it was byte-identical to its pre-run checksums.
- **`composer ci` cannot pass here:** php-cs-fixer, PHPStan and `itp-context-validate` are not installed, and 45 baseline tests fail without the PHPStan-backed map. Every governed run stopped at `finish` with `host_work` "composer ci (failed)". All agents checked the 45 failures against the unchanged checkout, and none forged validation evidence or used `--accept-risk`.
- **Arm B store:** the pre-fix finding `5c4992` was removed. **Arm C′ store:** an operator-authored note was added. That is an experimental deviation: the real store had no LearningNote for this lesson.
- **Model:** the agents are the same model as the operating session. All six chose the same nested layout, so the runs are not independent samples of a diverse population.
