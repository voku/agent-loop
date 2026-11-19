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
- Network: `run-arm.sh` disables WebFetch/WebSearch and runs every session under [`sandbox-settings.json`](../../tools/learning-effect/sandbox-settings.json). The Bash sandbox is on with an empty network allowlist, `failIfUnavailable`, no unsandboxed fallback, and denials instead of prompts. Bash and its child processes (git, composer, php) get no outbound network, while the Claude Code process still reaches the model. Before each arm, a probe session under the same settings runs `curl https://github.com`. The arm runs only if the Bash tool result shows a non-zero exit and no 2xx/3xx status (`network-probe.jsonl`, `network-probe-results.txt`). Otherwise it is refused and recorded. The settings file's SHA-256 goes into `run.json`. Linux needs `bwrap` and `socat`.
- Disk: the Read/Glob/Grep tools are not network-sandboxed and can read the whole disk, and the case clones hold the historical repairs. Run each arm in a disposable container that contains only its `<out>` directory, never on the machine holding the case clones.

## Frozen envelope per run

Same host CLI version and model ID, the same task text, `--permission-mode acceptEdits`, the same `--settings` sandbox file, allowed tools `Bash Read Edit Write Glob Grep`, at most 80 turns and a 45-minute wall clock (`run-arm.sh`). Each run gets a new, independent session and a new workspace. For B/C, a genuine human decision (for example Contract approval) is made by the operator, never auto-approved, and logged as one line in `interventions.jsonl`.

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

### Run 1 (2026-11-19, in-session agents, one repetition per cell)

Executed inside the Claude Code session, with no API key. Each run was a fresh agent started by the session (same model as the session) with no memory of the conversation. Every run got the identical instruction: work only in your workspace, no network, follow the repository's agent instructions, stop for human decisions, do not commit. As operator, I approved each governed Contract as submitted (one logged intervention per B/C′ run) and resumed the agent with an identical message. Per-run data: [`2026-11-19-learning-effect-pipefail.results.jsonl`](2026-11-19-learning-effect-pipefail.results.jsonl).

| Case | A native | B governed, no precedent | C′ governed, precedent delivered |
| --- | --- | --- | --- |
| `agent-learning` | correct (oracle 3/3) | correct (3/3) | correct (3/3) |
| `agent-recall-compiler` | correct (adapted 3/3; strict 0/3, helper script) | correct (3/3) | correct (3/3) |
| `agent-map` | correct (adapted 3/3; strict 0/3, helper script) | correct (adapted 3/3) | correct, graded manually (see below) |

| Arm | Repeated mistake | Mean tokens | Mean tool calls | Mean minutes | Human interventions | Precedent |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| A | 0/3 | 85,525 | 13.7 | 1.9 | 0 | n/a |
| B | 0/3 | 192,339 | 42.0 | 4.9 | 3 (one approval each) | absent (0/3 compiled) |
| C′ | 0/3 | 185,622 | 38.0 | 4.7 | 3 (one approval each) | compiled 3/3, read 2/3 |

**What this shows:**
- **Ceiling effect.** All 9 agents identified the `git show | grep -q` SIGPIPE/pipefail cause and fixed it by capturing the output before matching. None repeated the mistake. In every governed run the diagnosis came during planning, before Contract approval and so before Recall could show the precedent. With this model and task, the precedent cannot change the outcome, and the experiment cannot show a learning effect in either direction.
- **C′ vs B: no measurable difference.** Tokens are −3%, tool calls −10%, time −4%; with n=1 per cell that is noise.
- **Governance cost without a quality gain on this task.** B and C′ used about 2.2× the tokens, about 3× the tool calls and about 2.5× the time of A, and each needed a human approval. No governed run reached `finish`. Four were blocked by `composer ci` failing on the experiment's shared vendor directory (an environment confound, below). One stopped at the human review sign-off, and one at the independent correctness review.
- **Cross-repository delivery is blocked by design (deterministic, no agent needed).** With the unmodified note, Recall selects `learning-note.2026-11-19.54b941` as relevant to `.github/workflows/release-tag.yml`, then fails closed: "Compilation blocked: LearningNote … references missing repository evidence" (`tests/ReleaseTagWorkflowTest.php` exists only in agent-loop). The whole governed run then stops at `enter`. C′ therefore uses the same note re-pinned to the workspace's own baseline `release-tag.yml`. That is an experimental deviation, not something the system does by itself. This is the mechanism behind the original cross-repository repeat: a lesson's provenance is repository-local, so it cannot reach a sibling repository.
- **Delivered is not read.** In `agent-map` C′ the precedent was compiled into the context, but the agent never opened it.

**Grading notes.** The historical oracle runs the extracted inline workflow script inside its own temporary repository. Four agents moved the check into a helper script under `.github/scripts/`, which the oracle cannot see. For those, an adapted oracle rewrites the helper call to its absolute path (and a one-line `run:` into block form) and runs the identical test. In `agent-map` C′ the agent deliberately moved the target check before the working-tree check. It still tags the valid release and still refuses a target without the section, but with the target-specific message instead of the "CHANGELOG.md has no release section" message the historical test pins. Graded correct by hand. The own-test mutation check (revert the workflow only) is not meaningful when the fix lives in a new helper, so it is `n/a` there.

**Confounds and deviations, all recorded:**
- **Separation.** Agents shared this machine with the repositories holding the historical fixes. The transcript audit found no read of another repository or another run's workspace and no network use. Seven runs wrote temporary files to the session scratchpad.
- **Shared vendor directory.** One PHPUnit-capable vendor copy was linked into all workspaces, since Composer could not download here. Several agents ran `composer dump-autoload` on it mid-experiment, changing it for the runs still in progress, and it never matched agent-map's or agent-recall-compiler's own dependencies. That is why `composer ci` failed there and `finish` could not close.
- **Loop install.** A local copy of the agent-loop runtime (vendored development files and git histories stripped, after they were found to contain the fixed workflows) was used through a `tools/agent-loop` wrapper. The seeded store dropped agent-loop's applied and approved proposals, which target agent-loop files and fail validation elsewhere. C′ also needed the note's source proposal `cbcf82`.
- **Governance behaviour differed.** Two governed agents recorded their own passing `composer ci` evidence with `session validation record`, after Composer's root check made `finish` record a failure. One signed the blind-spot review report itself; another refused to sign the equivalent report for its own work.
- **Model.** The agents are the same model as the operating session, and the task may be familiar from training.

**Next useful experiment.** The precedent can only matter on a task the native agent gets wrong. Before spending more runs on this lesson, find a lesson where arm A fails. Otherwise this design measures only governance overhead.

### Calibration

CI calibration (all three cases) proves the oracle separates baseline from repair. Calibration success is not a learning-effect result.

## Limitations

- One lesson, three closely related cases. The repairs are near-identical, so this measures one precedent's transfer, not learning in general.
- The lesson may already be familiar to the model from training. A strong A arm is informative, not a failure of the setup.
- B and C need the agent to actually enter Loop for Recall to run. Exposure is measured rather than assumed.
- The oracle extracts the inline `run: |` script. A correct fix that moves the script elsewhere is `ungradeable` and graded with the adapted oracle or by hand (see Run 1).
