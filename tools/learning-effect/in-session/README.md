# In-session learning-effect harness (as run, 2026-11-19/20)

These are the scripts used for learning-effect round 2/3, holdout H1, holdout H2 and the H2b `coding-simplicity` pilot. They are kept for provenance and reuse; they are development tooling only (no production autoload, no CI gate).

They are copies of the as-run scripts with a few corrections made after review (listed under "Changes since the runs" below). Behaviour that results in the recorded numbers is unchanged.

- **Records:**
  - `docs/dogfood/2026-11-19-learning-effect-round2.md`
  - `docs/dogfood/2026-11-20-learning-effect-holdout-h1.md`
  - `docs/dogfood/2026-11-20-learning-effect-h2-screening.md`
- **Run 1 harness** (sandboxed real `claude -p` host): `../prepare.py`, `../run-arm.sh`, `../grade.py`.

"In session" means the agents were sub-agents of one operator session (same model, no API key, no network). The operator approved each Contract as submitted and logged it.

## Hard-coded paths (edit before reuse)

The scripts were written for one container. Before reusing them, replace these constants:

| Script | Constant | As run | Meaning |
| --- | --- | --- | --- |
| `prepare_ws.py` | `EXP2` | `<scratch>/exp2` | directory that receives `runs/<name>/` |
| `prepare_ws.py` | `SHARED` | `<scratch>/exp/shared-vendor` | a vendor dir that contains PHPUnit (`phpunit`, `sebastian`, `theseer`, `phar-io`, `myclabs`, `staabm`) and its `composer/installed.json` |
| `prepare_ws.py` | `LOOP` | `/home/user/agent-loop` | an agent-loop clone with full history (`git fetch --unshallow` or a deep fetch) |
| `prepare_ws.py` | env `EXP_VENDOR` | `<scratch>/exp2/vendor-round2` | runtime vendor copied into each workspace |
| `prep_h1.py`, `prep_h2.py` | `EXP2`, `EXP3`/`H2` | relative to the script | expect `prepare_ws.py` in `../exp2/`. Here all scripts sit in one directory, so set `EXP2 = Path(__file__).parent` |
| `prep_h1.py` | `/home/user/agent-recall-compiler` | Recall clone | used by `overlay.py` to put a Recall version into the vendor |
| `snaptest.py` | env `SNAP_VENDOR` | a workspace's `vendor/` | vendor used to run PHPUnit on a snapshot |
| `snaptest.py`, `oracle_h2b.py`, `fp_h2b.py`, `size.py`, `grade_h1.py`, `behavioural_oracle.py`, `mutant_run.py` | `LOOP` | `/home/user/agent-loop` | source of historical commits |
| `mutant_run.py` | `VENDOR` | a deleted round-3 workspace | point at any workspace `vendor/` |
| `grade_pilot.py` | `T` | `<scratch>/../tasks` | directory holding sub-agent transcripts (`<agent-id>.output`) |

## What each script does

| Script | Use | Input | Output |
| --- | --- | --- | --- |
| `prepare_ws.py <name> <baseline-sha>` | fresh single-commit workspace at a baseline (no history, no remote) with a private vendor | baseline SHA | `runs/<name>/repo`, `manifest.json` |
| `prep_h2.py <name> <baseline> <finding-id>…` | **arm B**: workspace with the lesson record(s) removed, plus CHANGELOG lines naming them; fails if the id still appears anywhere | baseline, lesson ids | workspace + manifest deviation |
| `prep_h1.py <name> B\|C` | H1 arms; **C′** adds a LearningNote (scope widened, task-naming text removed, evidence re-hashed). Copy this pattern for any C′ arm | — | workspace |
| `backport_1091.py <repo>` | backports agent-loop#1091 (precedent projection in `enter`/`workflow plan`) into old baselines. Patches `enter` (JSON and text) and `workflow plan` (JSON and text). **The H1 runs used the earlier text-only plan patch**; that gap caused 2 of 3 missed deliveries (see the H1 record). The JSON path was added afterwards and verified: `plan --format=json` on the H1 probe workspace returned `learning_precedents` (`candidate_contract`, `selected`) | workspace repo | patched files |
| `overlay.py <vendor> '<json>'` | replaces a voku package in a vendor dir with a git ref of a local clone | vendor, `{"pkg": [clone, ref, version]}` | updated vendor |
| `snaptest.py <commit\|dir> <test> <filter> [--overlay-tests-from <commit>] [--repeat N]` | runs PHPUnit on a snapshot. Use `--overlay-tests-from <fix-commit>` to run the historical (hidden) tests against an agent's tree | — | `passes/N` |
| `oracle_h2b.py <commit\|dir> <vendor>` | example **PHPStan-diagnostic oracle**: adds a hidden fixture, counts diagnostics by identifier | — | count + locations |
| `fp_h2b.py <commit\|dir> <vendor>` | example **negative probe**: adds a case the rule must NOT flag | — | flagged / `no` |
| `size.py` (module) | production/test line counts of a workspace diff (CHANGELOG/docs excluded) | — | dict |
| `grade_pilot.py` | grades every run in `runmap.tsv` (oracle, false-positive probe, lines, skill read) | `runmap.tsv` | JSON lines |
| `grade_h1.py`, `mutant_run.py` | H1 mutation oracle: do the agent's own tests kill a mutant that exists only on the override branch | — | JSON |
| `behavioural_oracle.py [--realistic]` | C1 behavioural close-gate oracle with optional realistic `task_id` fixtures | — | pass/fail |
| `audit_transcripts.py <runmap.tsv>` | isolation and skill-access audit (network, forbidden paths, skill files, Skill tool) | `name\ttranscript` lines | JSON lines |

## Protocol lessons that cost real runs

1. **Screen arm B (one run) before any C′ run.** Five of six lessons were ceilings; screening saved 12 runs in H2.
2. **Calibrate every oracle first:** it must fail on the baseline and pass on the historical fix. Also try one *plausible wrong fix* and confirm it fails.
3. **Add negative fixtures.** Include cases the change must NOT affect. H2b's hidden oracle missed a real false positive (`LevelsTestCase`).
4. **Leakage check:** remove the lesson, every record naming its id, and CHANGELOG lines naming it. Then `grep -r <id>` must return nothing.
5. **Vendor:** hardlink one master vendor (`cp -al`), but give each workspace a private `vendor/composer` and `vendor/bin`. PHPStan reached through a symlink loads two autoloaders, which is a fatal error.
6. **Approvals:** the operator approves the Contract as submitted, appends one line to `<run>/interventions.jsonl`, and resumes with the standard message. Side questions from the agent are not answered.
7. **`finish` never completes offline:** `composer ci` cannot pass with this vendor. Grade with the hidden oracle, never with the agent's own claim.

## Changes since the runs

Review of the first version found real defects. These fixes do not change any recorded result:

- `backport_1091.py`: also patches the `workflow plan --format=json` payload. The as-run version only patched the text path.
- `overlay.py`: extracts the archive to a staging directory and replaces the installed package only after it succeeds, so a bad ref no longer leaves an empty vendor package.
- `prep_h1.py`: finds `overlay.py` next to itself.
- `size.py`: lists untracked files NUL-delimited, so names with spaces work.
- `grade_h1.py`: every PHPUnit run starts without a stale `junit.xml`. A non-zero exit with no report counts as an execution failure, not as green. Grading results are merged into `result.json` instead of replacing it.
- `behavioural_oracle.py`: creates `result.json` when it is missing.
- `fp_h2b.py`: a PHPStan analysis failure prints `ANALYSIS_FAILED` and is no longer reported as a clean "no".
- `grade_pilot.py`: records `levels_false_positive: "analysis_failed"` and `skill_read: "transcript_unavailable"` as separate values, not as false.
- `audit_transcripts.py`: recognises `git -C <dir> fetch` and other forms with global options; the workspace exemption uses path containment instead of a string prefix; forbidden paths are classified before any exemption.
