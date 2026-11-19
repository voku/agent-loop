# Learning-effect experiment, round 2: a lesson the governed agent gets wrong

Date: 2026-11-19. Follows [Run 1](2026-11-19-learning-effect-pipefail.md), which hit a ceiling: every arm fixed the pipefail lesson, so the precedent could not change anything. Round 2 looks for a lesson that governed arm B (no precedent) actually fails, then compares it with C′ (governed, precedent delivered). Per-run data: [`2026-11-19-learning-effect-round2.results.jsonl`](2026-11-19-learning-effect-round2.results.jsonl).

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

**Next step this points to.** To test whether the lesson helps, the precedent has to reach the agent while it is still choosing the design. That means surfacing scope-matched LearningNotes when the candidate Contract is planned, before approval, and naming delivered precedents in `enter`'s `host_work` response. Re-run C1 B/C′ only after such a change; the B baseline here (0/3) is reusable.

## Confounds and deviations

- **Separation:** the agents shared the machine with the repositories that hold the fixes. The transcript audit found no foreign repository read and no network use. All seven agents wrote temporary files to the session scratchpad, outside their workspace.
- **Vendor:** each workspace has agent-loop's current installed runtime packages plus PHPUnit, with lock drift against the baseline and the autoloader regenerated offline. Because the disk was nearly full, the C1 B2/B3/C′ workspaces hardlinked one vendor tree (each kept a private `vendor/composer` and `vendor/bin`). After the runs it was byte-identical to its pre-run checksums.
- **`composer ci` cannot pass here:** php-cs-fixer, PHPStan and `itp-context-validate` are not installed, and 45 baseline tests fail without the PHPStan-backed map. Every governed run stopped at `finish` with `host_work` "composer ci (failed)". All agents checked the 45 failures against the unchanged checkout, and none forged validation evidence or used `--accept-risk`.
- **Arm B store:** the pre-fix finding `5c4992` was removed. **Arm C′ store:** an operator-authored note was added. That is an experimental deviation: the real store had no LearningNote for this lesson.
- **Model:** the agents are the same model as the operating session. All six chose the same nested layout, so the runs are not independent samples of a diverse population.
