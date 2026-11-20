# Learning-effect holdout H2: screening two later tasks (both ceilings)

Date: 2026-11-20. This follows [holdout H1](2026-11-20-learning-effect-holdout-h1.md), which hit a ceiling. H1's first next step was to screen arm B (governed, no precedent) on a candidate holdout before spending any C′ (precedent) runs. This record applies that rule to the two best remaining candidates. Per-run data: [`2026-11-20-learning-effect-h2-screening.results.jsonl`](2026-11-20-learning-effect-h2-screening.results.jsonl).

**Result: both candidates are ceilings.** One B run each produced a correct design on the hidden oracle, so no C′ runs were spent: six per candidate, twelve in total.

## Candidate search

A read-only search went through the five active LearningNotes, the twelve validated Findings and their histories in agent-loop. It looked for a lesson recorded *before* a later commit that repeats the lesson in different code, where that commit's own tests fail on its parent.

- Already used: the pipefail, worktree-hook, close-gate and H1 lessons.
- Five candidates were left. Only two were strong enough to screen.
- Fourteen were rejected. In every rejected case the trap was either obvious, or there was no lesson recorded before the task.

| | H2a | H2b |
| --- | --- | --- |
| Lesson | finding `dd4221` (2026-09-15): an unapproved superseding Contract revision should route to its approval decision, not to Run repair | finding `481001` (2026-09-14): the in-process PHPStan test-case rule overfit one syntax; resolve the semantic parent |
| Holdout task | the fix in 2daacc88 (PR #792, 2026-10-18), baseline fd298958 | the rule change in 0866edaf (2026-09-15), baseline 5f54b84e |
| Trap | route every unapproved Contract to approval, even when the Run is *newer* than the Contract | add `TypeInferenceTestCase` to a deny-list of direct parents, missing indirect subclasses |
| Hidden oracle | 2daacc88's `RunManifestProjectorTest`: the supersession test plus the Run-ahead guard | PHPStan rule diagnostics, counted by identifier, on 0866edaf's fixture (a direct subclass, an abstract base and an indirect child) |
| Oracle calibration | baseline 1/2 tests, fix 2/2 | baseline 2 diagnostics, fix 5; the deny-list wrong fix scores 4 |

## Arm B, one run each

The protocol is the same as in H1:
- a fresh single-commit workspace;
- a task prompt that describes the symptom only;
- the lesson removed from the store. For H2b, the one CHANGELOG line naming the lesson id was also removed;
- one operator approval of the Contract as submitted, then resume.

| Run | Hidden oracle | What the agent built | Tokens* | Tool calls | Minutes |
| --- | --- | --- | ---: | ---: | ---: |
| H2a-B1 | **pass 3/3** | attached `workflow approve` as the repair only when a *candidate* Contract is newer than the Run; kept a human-decision repair `decision_required`; added an end-to-end `enter → approve → enter` test | 239,311 | 55 | 5.8 |
| H2b-B1 | **pass 3/3** (all 5 expected locations) | walked the resolved parent chain and rejected any `PHPStan\Testing\` ancestor; added its own indirect-subclass fixture and a per-location test | 193,055 | 44 | 5.6 |

\* Summed over the two agent segments: before approval, and after resume.

- **Neither agent took the trap.** Both chose the general design (parent chain, direction check) before approval, in their Contract text.
- **No run reached a complete `finish`.** H2a stopped because `finish` needs a commit. H2b stopped because `composer ci` cannot pass with this offline vendor; the same failures occur on the untouched baseline.
- **Transcript audit:**
  - no read of the real repositories, the candidate list or the other runs;
  - no network use;
  - both agents wrote temporary logs to the session scratchpad, outside their workspace, as in earlier rounds.

## What the rounds together suggest

Six lessons have now been tested against a governed agent without the precedent:

| Lesson | Arm B without the precedent |
| --- | --- |
| pipefail capture | correct (ceiling) |
| linked-worktree hook root | correct (ceiling) |
| duplicated-decision branch coverage (H1) | correct (ceiling) |
| pending supersession routing (H2a) | correct (ceiling) |
| in-process rule matches the ancestor (H2b) | correct (ceiling) |
| close gate sees sibling `<task>-*` bundles (C1) | **wrong, 0/3** |

Only C1 left room for a learning effect, and it is the only lesson whose content cannot be derived from the code:
- The sibling-bundle naming is a project convention, visible only in the incident's runtime layout.
- Every ceiling lesson encodes engineering judgement that the model already applies when it reads the code.

**Hypothesis** (n=6, one model, not established): measurable learning value concentrates in lessons that record *non-derivable project facts*: conventions, runtime layouts, external constraints, history. Lessons that restate good practice add little.

If this holds, two things follow:
- Holdout selection should start from that property, instead of from "a later task in the same area".
- Learning could ask, when a Finding is written, whether its conclusion is recoverable from the code alone.

## Next steps

- Look for holdouts whose lesson is a non-derivable fact, and screen B first again.
- The current store has none left that predates a later recurrence.
- #457 stays open: there is still no positive behavioural comparison for a note the system wrote itself.

## Follow-up: coding-simplicity pilot on H2b

**Why this pilot.** A review afterwards found avoidable logic in H2b-B1:
- Its rule matches any ancestor in the `PHPStan\Testing` namespace instead of `PHPStanTestCase`, "so a new PHPStan testing base is covered without a rule change".
- That wrongly rejects `PHPStan\Testing\LevelsTestCase` subclasses. Those run PHPStan through `exec()` out of process, which is exactly the pattern the rule allows.
- A fixture confirms it: the agent's rule flags it, the historical rule does not.
- The hidden oracle cannot see this.

agent-loop already adapts Ponytail's implementation rules as `voku/agent-skills` `coding-simplicity` (see [`upstream-capability-matrix.md`](../architecture/upstream-capability-matrix.md)). None of the screening workspaces had it installed.

**Design:**
- Six fresh H2b workspaces with the same baseline, lesson removal and prompt as H2b-B1.
- Both arms get one extra neutral line in the prompt: "repository-projected agent skills, if any, are under `.claude/skills/`".
- **S arm:** has the skill at the agent-loop pin `c9e3b296`, placed at `.claude/skills/coding-simplicity/SKILL.md`.
- **B arm:** has no skills.
- **Scoring:** the hidden oracle (5 expected locations), the `LevelsTestCase` false-positive probe, a classification of the detection design, and line counts.

| Run | Skill | Detection design | Surface the task did not need | Rule code lines* | Oracle | `LevelsTestCase` false positive | Production + / test + |
| --- | --- | --- | --- | ---: | --- | --- | --- |
| H2b-B1 (screening) | no | `PHPStan\Testing` namespace prefix | prefix detection, declared-parent fallback | 42 | pass | **yes** | 46 / 124 |
| P-B1 | no | exact `PHPStanTestCase` ancestor | namespace scan to name the base in the message | 38 | pass | no | 41 / 36 |
| P-B2 | no | exact ancestor plus an explicit class list | list, declared-parent fallback | 42 | pass | no | 58 / 56 |
| P-B3 | no | exact `PHPStanTestCase` ancestor | none | 29 | pass | no | 39 / 52 |
| P-S1 | read | exact `PHPStanTestCase` ancestor | none | 27 | pass | no | 22 / 42 |
| P-S2 | read | exact `PHPStanTestCase` ancestor | none | 27 | pass | no | 33 / 109 |
| P-S3 | read | `PHPStanTestCaseTrait` ancestor | none | 29 | pass | no | 31 / 62 |

\* Non-comment, non-blank lines of the final rule file. The historical fix is a single ancestor check.

**What this shows:**
- **Correctness is identical on what was measured:** the hidden oracle plus the `LevelsTestCase` probe. All six pilot runs pass both. The screening run's defect occurred 1 time in 4 runs without the skill, which is too rare to test whether the skill prevents it.
- **Measurement gap:** the hidden oracle did not cover the screening run's false positive. Only the probe added afterwards caught it. So "passes the oracle" does not cover inputs outside its fixtures, and broader-than-needed logic can hide real misclassifications from it. A future holdout should include negative fixtures (cases the rule must *not* flag) in its oracle, not only the historical positives.
- **The skill arm produced only minimal designs.** All three S runs read the skill and chose a single exact-ancestor (or container-trait) check. In 2 of 3 B runs, and in the screening run, the agent added detection or naming surface the task did not need.
- **Production code was smaller with the skill:** 22–33 added lines, against 39–58 without. The rule files themselves: 27–29 code lines, against 29–42.
- **Test lines went the other way:** 42–109 with the skill, against 36–56 without. This fits the skill's verification floor, but with n=3 it may be noise.
- **Cost:** tokens are the same, about 225k per run in both arms.
- **Reading:** this is a small, consistent signal (n=3 per arm, one task, one model) that the existing skill reduces speculative surface. It supports the `ADAPT` decision for `coding-simplicity`. It does not justify a new prompt, gate or benchmark, and it shows no correctness gain.

**Not measured:** whether real hosts load `coding-simplicity` in governed runs, outside this explicit pointer. Existing records cannot answer this. CI and the Codelight composition dogfood only prove projection and routing, and no recorded real host run shows the skill being loaded on a coding task. The capability matrix tracks per-host skill consumption as `DEFER`.

## Real-host activation probe (normal projection, no pointer)

**Question:** under the normal workflow, does a host load `coding-simplicity` by itself?
- The pilot above pointed agents at `.claude/skills/`, so it measured "read when pointed to".
- This probe (P-N1) uses the H2b workspace with the lesson removed, plus real projection: `bin/agent-loop init install-assets --agent=claude --extra-skills-root=<voku/agent-skills@c9e3b296>/skills --skip-git-config`.
  - That put 42 skills into the gitignored `.claude/skills`, including `coding-simplicity`.
  - The projected `agent-loop-discipline` and `agent-loop-surgical-edit` skills route to it.
  - AGENTS.md says to "use repository-managed skills and subagents when their descriptions match the task".
- The prompt was the original H2b task with **no skills pointer**. There was one operator approval, as submitted.

**Three claims, kept separate:**

| Claim | Evidence source | P-N1 |
| --- | --- | --- |
| **Available**: the skill is in the catalog the model is shown | the host's skill-listing attachment in the transcript | **no**. A sub-agent host gets its catalog from the parent session, not from the workspace. The pilot catalogs never listed it either |
| **Loaded**: the SKILL.md was read for use | tool calls touching `.claude/skills/…` or the Skill tool (`audit_transcripts.py`). A read can also be source editing, and the pilot agents loaded via `cat`, not the Skill tool | **no**. No skill file and no Skill call, before or after approval |
| **Applied**: the skill changed the implementation | design and line counts against the arms, plus the transcript linking choice to rule | n/a |

**Outcome:**
- Hidden oracle: 5/5 expected locations.
- `LevelsTestCase` probe: not flagged. The agent explicitly allowed it, along with plain PHPUnit cases.
- It is the largest rule of any run: 62 rule code lines, +90 production lines, against 27–29 and 22–33 in the skill arm and 29–42 and 39–58 without it.
- Part of that surface is legitimate: rejecting direct `PHPStanTestCaseTrait` users (P-S3 keyed on the same trait) and the explicit out-of-process allowance.
- Part of it is speculative: a separate error for unresolvable `PHPStan\Testing\` parents, and nested-trait walking.
- The transcript audit shows no network use, no forbidden reads, and one scratch file outside the workspace.

**Reading** (n=1, one task, one model):
- **NOT_LOADED** for projected files plus instruction routing, *without* catalog listing. Projection plus AGENTS.md routing did not make the agent open the skill.
- The catalog-listing case is the one a real host started in the repository would have. It remains untested, because this harness cannot start a host inside the workspace.
- Writing into this host's own `.claude/skills` to test it was refused as a change to the operator's harness configuration.

**Existing evidence structures cannot record "skill X was loaded":**
- `recall-log.draft.json` outcome rows exist only for *selected Learning guidance* (memory, constraint), and package skills are never selected.
- `also_prescribed_by` accepts only the category `skill`.
- Session evidence is free text or command and exit-code records.
- The only typed runtime evidence for skill loading is PR #727's provider-free Codex proof (`host_runtime_skill_consumption`). It is limited to **explicit** selection of one package skill.

**Smallest verified gap:** implicit selection of projected first-party skills is unobserved for every host, and P-N1 shows it does not happen through file projection plus routing alone.

**Options:**
- (a) **Do nothing** (recommended): keep the capability-matrix `DEFER`. No decision currently depends on the activation rate.
- (b) Make the always-on bootstrap name `coding-simplicity` for coding tasks. This conflicts with the matrix row "`REJECT` session/subagent reinjection" and is an owner decision.
- (c) Only if a concrete decision needs it: one real-host run started inside a projected repository, observed with the #727 pattern (skill body in the captured request). No Loop or Session schema change.

## Confounds and deviations

- **Vendor:** both workspaces use the round-2/H1 runtime vendor plus PHPUnit, with lock drift against the baselines. H2b additionally has PHPStan 2.3.6 linked in, because the runtime vendor has no PHPStan; the oracle needs it. The vendors are hardlinked.
- **Model:** the agents are the same model as the operator session.
- **H2a prompt:** it omits the candidate list's optional clause about keeping the mismatch reporting for genuinely inconsistent Runs, because that clause hints at the trap.
- **Pilot deviations:**
  - The S arm got the skill by file placement plus the neutral pointer line, not by `init` projection.
  - Approvals were given as submitted, one per run; each agent's side questions were left unanswered.
  - Several agents wrote temporary logs to the session scratchpad. P-B3 used `/dev/shm`.
  - The transcript audit found no network use and no reads of other runs, the candidate list or the real repositories.
  - No run reached a complete `finish`, because `composer ci` cannot pass with the offline vendor.
