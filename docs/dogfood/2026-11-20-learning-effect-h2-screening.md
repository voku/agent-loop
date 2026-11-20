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

## Confounds and deviations

- **Vendor:** both workspaces use the round-2/H1 runtime vendor plus PHPUnit, with lock drift against the baselines. H2b additionally has PHPStan 2.3.6 linked in, because the runtime vendor has no PHPStan; the oracle needs it. The vendors are hardlinked.
- **Model:** the agents are the same model as the operator session.
- **H2a prompt:** it omits the candidate list's optional clause about keeping the mismatch reporting for genuinely inconsistent Runs, because that clause hints at the trap.
