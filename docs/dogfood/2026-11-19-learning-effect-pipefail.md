# Learning effect: does a learned precedent prevent a repeated mistake? (pipefail)

Sibling of [GH-1031](2026-11-15-gh-1031-oracles.md): same method (frozen real baselines, hidden injected oracle, CI calibration before any model run), different question. GH-1031 compares governance stacks. This compares **the same governed agent with and without one learned precedent**. The success metric is changed agent behaviour, not the number of persisted LearningNotes.

## Why this lesson

`voku/agent-loop` fixed a release-tag defect on 2026-09-19 (`897bf06`, finding `95209d`, now LearningNote `learning-note.2026-11-19.54b941`). Under `set -o pipefail`, `git show <target>:CHANGELOG.md | grep -Fq ...` can report a present section as missing: `grep -q` exits at the first match, `git show` dies with SIGPIPE, and the pipeline fails.

The same construct then shipped, unfixed, in three sibling repositories, and was repaired there on 2026-11-19. At those baselines none of the three had Loop governance, a learning store or a router block, so nothing could deliver the lesson to work in them. These are real repeated mistakes with real repairs, which makes them natural replicates.

## Cases

| Case | Vulnerable baseline | Historical repair (grading control only) | Baseline `CHANGELOG.md` | Real symptom on baseline (`git show \| grep -Fq` false) |
| --- | --- | --- | ---: | ---: |
| `agent-learning` | `399815a` | `e9da152` | 75,009 B | 97/100 |
| `agent-recall-compiler` | `87d8cbb` | `c587acc` | 87,653 B | 14/100 |
| `agent-map` | `3b36beb` | `2c43031` | 35,237 B | 86/100 |

The real symptom is flaky; an agent that "reproduces" it once may see it pass. The hidden oracle is deterministic: each repair's own `tests/ReleaseTagWorkflowTest.php` runs the real workflow script against a real Git repository with a ~260 KB changelog. Calibrated locally on 2026-11-19, baseline failed 5/5 and repair passed 5/5 for every case, and every baseline failure is the intended assertion (`Release target … does not contain the 1.2.3 changelog section`), not a setup error. CI repeats this calibration through the full kit (`.github/workflows/learning-effect-pipefail-calibration.yml`).

Full SHAs, versions and patterns are in [`tools/learning-effect/cases.json`](../../tools/learning-effect/cases.json).

## Arms

| Arm | Starting state (`tools/learning-effect/prepare.py`) |
| --- | --- |
| **A** native | The repository at the baseline, as a fresh single-commit repo. The repository's own `AGENTS.md`/docs stay as they were. |
| **B** governed, no precedent | A + released `voku/agent-loop` (exact version, isolated `tools/agent-loop` install, which Loop resolves via `RepositoryActivation`) + `init install-assets --agent=<host>` + agent-loop's learning store with **every** artifact mentioning the lesson removed. |
| **C** governed, with precedent | B + exactly two files: the LearningNote `54b941` and its source Finding `95209d`. |

B and C carry the same unrelated notes, so Recall must *select* the precedent among distractors. The note is seeded unchanged. Its repository evidence points at agent-loop paths that don't exist in the sibling repository, and whether Recall still selects it is part of what is measured. Copying it in is an experimental condition, not a propagation mechanism.

## Isolation and leakage controls

- The workspace is built from the exact baseline tree with no remote and no history, so the repair can't be fetched or read from `git log`. The baseline commit uses a fixed identity and date, so it has the same SHA in every arm.
- The task text ([`task.md`](../../tools/learning-effect/task.md)) is identical in every arm and describes only the observed symptom: the error message and the fact that the section exists. It does not mention pipes, pipefail, SIGPIPE, buffers or the fix.
- B's learning store must contain nothing matching `cases.json` `leak_pattern`, and C's only the two precedent files. `prepare.py` fails closed otherwise. Governance assets that mention the terms generically (for example the investigate skill's "preserve `set -o pipefail`") are identical in B and C and are recorded in the manifest.
- The hidden oracle lives only in the case source clone outside the workspace. It is injected under a different class name into a copy of the final tree.
- Grading executes candidate-written code (the workflow script under the oracle and the agent's own tests). Grade only in a disposable environment such as CI, never on a workstation holding credentials.
- `run-arm.sh` disables WebFetch/WebSearch. The repositories are public, so a run with network-capable shell access could still look upstream; the transcript is checked for it.

## Frozen envelope per run

Same host CLI version and model ID, the same task text, `--permission-mode acceptEdits`, allowed tools `Bash Read Edit Write Glob Grep`, at most 80 turns and a 45-minute wall clock (`run-arm.sh`). Each run gets a new, independent session and a new workspace. For B/C, a genuine human decision (for example Contract approval) is made by the operator, never auto-approved, and logged as one line in `interventions.jsonl`.

Schedule: 3 cases × 3 arms × 3 repetitions = 27 runs, in an order shuffled with a recorded seed (`shuf --random-source=<(yes <seed>)`).

## Measures (`tools/learning-effect/grade.py`)

| Measure | Definition |
| --- | --- |
| correctness | Hidden oracle passes 3/3 on the final tree. An oracle that can't parse a restructured workflow is reported `ungradeable` and graded by hand, never counted as a pass. |
| repeated mistake | The final workflow still feeds `git show` into an early-exiting consumer (`grep -q/-l/-m`, `head`). Comments are ignored. |
| construct written | Number of Edit/Write tool calls that wrote that construct during the run, from the transcript. |
| regression-test validity | The agent's added or changed tests pass on its tree and fail when only the workflow is reverted to the baseline (x/3). |
| unnecessary edits | Changed paths outside `release-tag.yml`, `tests/`, `CHANGELOG.md`; Loop runtime state excluded. Also total lines changed. |
| tool calls, tokens, turns, duration, cost | From the Claude Code `stream-json` result event. Shown as `null` when the host doesn't report them. |
| human interventions | Lines in `interventions.jsonl`. |
| precedent exposure / Loop use | Whether the note or finding ID reached the agent, and how many `agent-loop enter/finish/status` calls ran. A C run without exposure is reported as such, not as evidence against the precedent. |

The question is answered by comparing C with B on repeated mistakes and correctness, with A as the native reference. Every run is recorded, including failed, timed-out and refused ones.

## Procedure

```bash
kit=tools/learning-effect
python3 $kit/prepare.py --case agent-learning --arm C --out runs/al-C-1 \
  --source ../agent-learning --learning-source .
tools/learning-effect/run-arm.sh runs/al-C-1 <model-id>
python3 $kit/grade.py --out runs/al-C-1 --source ../agent-learning \
  --ledger docs/dogfood/2026-11-19-learning-effect-pipefail.results.jsonl
```

## Results

| Case | A | B | C |
| --- | --- | --- | --- |
| `agent-learning` | NOT_RUN | NOT_RUN | NOT_RUN |
| `agent-recall-compiler` | NOT_RUN | NOT_RUN | NOT_RUN |
| `agent-map` | NOT_RUN | NOT_RUN | NOT_RUN |

No model run exists yet; every quality, token and behaviour comparison is UNKNOWN. Calibration success is not a learning-effect result.

### Execution blockers (2026-11-19, Claude Code cloud session)

1. **No model credential.** The `claude` CLI 2.1.328 is installed, but the container has no API key or OAuth token for a nested headless session.
2. **No Composer GitHub access.** `composer install` fails with `Could not authenticate against github.com` for dist and source downloads, so a workspace's PHPUnit and the governed arms' Loop install can't be built here. CI has access, and the calibration workflow exercises those steps there.

What has been validated locally: arm preparation with Composer skipped (identical baseline SHA and task hash across arms; B and C differ by exactly the two precedent files; no remote or history in the workspace) and the grader on three simulated outcomes (untouched: incorrect, mistake present; historical repair: correct, no mistake, own test catches the reverted workflow 2/2; repair plus a stray file: one unnecessary edit).

To run: use a machine with an authenticated `claude` CLI and normal Composer access, clone the three case repositories next to agent-loop, and follow the procedure.

## Limitations

- One lesson, three closely related cases. The repairs are near-identical, so this measures one precedent's transfer, not learning in general.
- The lesson may already be familiar to the model from training. A strong A arm is informative, not a failure of the setup.
- B and C need the agent to actually enter Loop for Recall to run. Exposure is measured rather than assumed.
- The oracle extracts the inline `run: |` script. A correct fix that moves the script elsewhere is `ungradeable` and graded manually.
