# Consolidation reconciliation — 2026-08-14

A handoff described a large backlog of agent-* workflow consolidation work as
outstanding. This note records what the repository actually contained when that
handoff was replayed, so later work starts from evidence instead of from the
brief.

The reconciliation itself followed the repository's own
[existing-work preflight](../../resources/skills/agent-loop-task-start/SKILL.md): inspect
overlap, falsify the strongest existing candidate, and repair or extend it
rather than write a competing implementation.

## Already implemented at `52bc5f1`

| Handoff item | Reality |
| --- | --- |
| Real issue replay is the integration test | Implemented. `tests/fixtures/real-upstream-adaptation/simple-php-code-parser-99.json`, `RealSimplePhpSelectiveAdaptationFixtureTest`, and `docs/agents/dogfood/no-production-change.md` freeze a real upstream-adaptation task whose correct outcome is no production change. |
| Map → Recall → Context evidence loss | Implemented and regression-covered. The Simple-PHP-Code-Parser #105 case is frozen as `finding.2026-08-14.004`; the deterministic fix is `WorkflowRankedMapContextExpander` with `WorkflowContextRankedMapExpansionTest` and the seed-cap boundary in `WorkflowRankedMapTestEvidenceCapTest`. Neither widened search nor raised a budget. |
| Contract / Run / Session / Recall / Learning ownership | Implemented. `TaskContractStore`, `GovernedRunStore`, `agent-session`, `RecallOutputRoot`, `agent-learning`; the Run manifest projects all five owners with per-owner `observation_mode`. |
| Learning root selected once | Implemented on the candidate branch, not on `main`. See below. |
| REVIEW / LEARN / REFLECT separation | Implemented. `review blindspots` / `workflow learn` / `workflow reflect` are three commands; reflection is gated to `ready_to_close`/`complete` and returns a prompt, not a lifecycle transition. |
| Prompt controls | Implemented as prompts, not lifecycle states: `resources/operating-prompts.json`, `OperatingPromptPrimitivesTest`, `PromptPrimitiveSkillSurfaceTest`, `tools/prompt-primitives-dogfood.php`. |
| Source vs installed dogfood | Implemented as two contracts: `tools/self-shape-dogfood.sh` (`php bin/agent-loop`) and `tools/release-set-dogfood.php` (`vendor/bin/agent-loop`). |

Nothing in the list above needed to be rebuilt.

## The Learning-root split-brain, and who had already fixed it

At `52bc5f1` the split-brain was real: `ProjectLayout` resolved the Learning root
to `.agent-loop/learning`, `.agent-loop/init.json` declared no `learning_root`,
the durable findings lived in `infra/doc/agent-learning/`, and
`tools/self-shape-dogfood.sh` compensated by passing `--learning-root` to eight
separate workflow calls.

Issue #96 already framed this exactly, and two pull requests already implemented
it:

- **#98** (`codex/issue-96-learning-root-authority`) — migrates the store to
  `.agent-loop/learning`, declares `paths.learning_root` once in
  `.agent-loop/init.json`, removes `--learning-root` from every workflow command,
  and distills six new PHPStan rules with failing fixtures. All 19 checks green.
- **#99** (`agent/issue-96-reconcile-learning-and-release`) — an independent
  implementation of the same issue with a different rule set. Gates, tests, slop
  review and self-shape all failing.

Falsification of #98 was run locally rather than trusted from CI: `composer ci`
(442 tests, PHPStan level 8 clean, seven project rule fixtures asserted) and
`tools/self-shape-dogfood.sh` both pass on its head commit. It is therefore the
surviving candidate, and this work continues on top of it instead of beside it.
#99 is superseded; three of its rules were genuinely orthogonal and the two that
detect real current-tree defects were carried across.

## What this work added on top

1. **Git working-tree detection.** `init doctor` and `init sync-githooks`
   inferred repository state from `is_dir($root . '/.git')`. In a linked
   worktree — the layout agents work in most — `.git` is a file, so a valid
   checkout was reported as "no Git repository" and `sync-githooks` installed six
   hook files while silently skipping `core.hooksPath`. Reproduced, fixed through
   `GitWorkTree`, regression-covered by a test that fails against the previous
   implementation, and guarded by `NoGitDirectoryShapeAssumptionRule`.
2. **A detector for the tooling-isolation rule.** The reviewed "tooling test
   isolation" memory row was enforced only by shell convention;
   `NoInProcessPhpstanRuleTestCaseRule` now fails analysis on an in-process
   `RuleTestCase`.
3. **An honest review-evidence record.** MEMORY claimed the self-shape runner
   required a blind-spot status of exactly `ok`. The runner never read the report,
   the run closed at `warn`, and the stated rule was unreachable anyway. The gate
   already lives in `WorkflowCloseCommand::checkReviewGate()`, so no second gate
   was added: the harness records the residual status as evidence and the memory
   row now points at the code that enforces it.

## Standing judgements

- A closed issue is not runtime evidence and an open PR is not correctness
  evidence. Both were checked against a local run before being believed.
- `run_id` was not spread further. The self-shape Run manifest already joins
  approval, contract revision + sha256, recall compilation id, session,
  verification receipt and the learning decision (which carries `run_id`), and
  the join survives `session prune`. No missing lineage join was observed, so
  none was added.
