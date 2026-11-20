# Learning-effect holdout H1: a system-written lesson on a later task

Date: 2026-11-20. This follows [rounds 2 and 3](2026-11-19-learning-effect-round2.md), where a precedent surfaced at planning time turned 0/6 wrong designs into 3/3 correct ones. Those rounds used a note the operator wrote. The question here: does the same effect appear with a note the Learning system wrote itself, applied to a different, later task? Per-run data: [`2026-11-20-learning-effect-holdout-h1.results.jsonl`](2026-11-20-learning-effect-holdout-h1.results.jsonl).

**Result: no measurable effect.** Agents without the precedent (governed arm B) already did what the lesson asks, 3/3, so the experiment hit the ceiling again. Two more findings came out of it, and both matter more than the null result:

1. **The real note could not have reached this task:** its scope names the incident's files, not the home of the decision.
2. **A harness defect:** pre-approval delivery failed in two of the three C′ runs.

## Case selection

- **Lesson.** Finding `43dc53` (2026-08-14, task GH-18): four private copies of one path-resolution decision agreed on the default branch and drifted on the environment-variable override branch. No gate exercised that branch. The Learning system later wrote note `a5fcbe` (2026-10-17) from it: "When collapsing a duplicated decision onto its owner, put the regression on the branch the old gate never exercised (for example an environment-variable override), not only on the default branch where the copies already agreed."
- **Why not the GH-18 fix itself:** the finding was recorded in the same commit as the fix (`01edde10`), so the precedent would describe the task. That is leakage, not prior evidence.
- **Holdout task:** PR #612 (2026-09-25, base `34891d35`). It makes `ManagedAssetTargetCatalog` the single owner of the skill and subagent target paths that the two sync commands still duplicated. This is a later task of the same shape, and the lesson (the finding) already existed in the store.
- **Prompt:** #612's stated goal, minus its hint about which tests to write. "Make the catalog the owner of the target paths and subagent suffixes that the sync commands use, remove the duplicated tables from the sync commands, keep behaviour unchanged, and add automated regression tests for the change."

## Oracle and calibration

#612 was behaviour-preserving: its two new tests pass on the baseline too (3/3), so they cannot grade correctness here. The lesson is about where the regression goes, so the primary oracle is a **mutation check on the agent's own tests**:

- The mutant renames every environment variable `ManagedAssetTargetCatalog` reads to an unset one. The overrides stop working and the defaults stay intact. It is a bug that exists only on the override branch.
- A run counts as **covering the override branch** when at least one test in a file the agent added or changed passes without the mutant and fails with it.
- Also recorded:
  - #612's own tests on the final tree, which show the override behaviour is preserved;
  - whether both sync commands delegate to the catalog;
  - the usual transcript audit.

Calibration before any agent ran:

| Tree | Result |
| --- | --- |
| Baseline `34891d35`, full suite | Mutant **not detected** (identical failure sets with and without it) |
| Historical fix `27a34931`, through the grader | Hidden 3/3, own tests green, mutant **killed** (2 tests), delegated |
| Fix sources + a test of the default branch only (negative control) | Hidden 3/3, own tests green, mutant **not killed** |

## Arms

- **Both arms:**
  - baseline `34891d35`;
  - #1091's precedent projection backported into `enter` and `workflow plan`;
  - Recall 0.29.4;
  - identical prompt and operator protocol (one Contract approval each, as submitted).
- **B:** the store as it really was at the baseline. Finding `43dc53` is present; there is no note.
- **C′:** note `a5fcbe` added with its real, system-written context and guidance. Two deviations:
  - its scope was widened to `src/Init/` (see finding 1);
  - the verification sentence and example that name PR #612 were removed, because they describe this task.

## Results

| Arm (n=3) | Own tests cover the override branch | #612 tests on final tree | Delegated to catalog | Precedent seen before approval | Mean tokens* | Mean tool calls | Mean minutes |
| --- | --- | --- | --- | --- | ---: | ---: | ---: |
| B | **3/3** (7–14 killing tests) | 3/3 | 3/3 | n/a | 203,035 | 35.0 | 5.6 |
| C′ + #1091 | 3/3 (8–9 killing tests) | 3/3 | 3/3 | 1/3 | 208,503 | 37.3 | 5.7 |

\* Summed over the two agent segments: before approval, and after resume.

All six agents removed both duplicated tables, delegated to the catalog and preserved behaviour. Each wrote 17–29 tests that pin every host's defaults and every environment override, and each checked sync output against the catalog. Without any precedent, two of the three B Contracts already named the environment-variable overrides in their acceptance criteria. No run reached a complete `finish`, because `composer ci` cannot pass offline here. No foreign reads, and no network use.

## What this shows

1. **A ceiling, not an effect.** The lesson's behaviour is the default for this model on this task: when the owner already encodes per-host overrides, testing them is the obvious move. The precedent had nothing to change. This null result says nothing about whether the note helps on a task where the override branch is less visible.
2. **The system's own note could never have reached this task (deterministic).** With `a5fcbe`'s real scope (`.github/workflows/ci.yml`, `src/Init/InitStatusCommand.php`, `src/Init/InitSyncHooksCommand.php`), `workflow plan` for this task's files returns no precedent. The note is scoped to the files of the incident it came from, not to the decision it is about, which lives in `src/Init/` and `ManagedAssetTargetCatalog`. A lesson about *how to collapse a duplicated decision* is relevant wherever the next duplicate lives. Exact-path scope cannot express that, so a real recurrence of this lesson would have gone undelivered.
3. **Harness defect: pre-approval delivery failed in 2 of 3 C′ runs.** At this baseline, `workflow plan` already has a JSON output path, and the round-3 backport only patched the text path. C′1 and C′2 called `plan --format=json` and never saw the note. C′3 saw it through `enter` after planning. Round 3's baseline only had text output, so it was not affected. This does not change the outcome here, because B already met the oracle. A rerun on another holdout must patch both paths and check the JSON delivery in its probe.

## Next steps

- Choose holdouts where B demonstrably fails before spending C′ runs: screen B first (one run), as round 2 did, then run C′.
- Owner follow-up for finding 2: learning-note scope should be able to name the decision's home (for example a directory or symbol owner) rather than only the incident's files. Agent-learning owns the scope semantics; this needs a proposal there, not a Loop change.

## Confounds and deviations

- These are the same as rounds 2–3: in-session agents of the same model as the operator session, a shared machine, and `composer ci` unable to pass offline.
- The vendor is the round-2 runtime set plus PHPUnit. All six workspaces hardlinked one tree, which was byte-identical after the runs (12,461 files checked).
- The grader's exposure marker (`a5fcbe` in tool output) missed C′3's exposure, because the agent saw the note's title, not its id. Exposure in the table comes from reading the transcripts.
