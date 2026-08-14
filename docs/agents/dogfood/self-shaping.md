# Self-shaping dogfood

`agent-loop` should be able to govern changes to `agent-loop` without inventing a privileged shortcut for its own repository.

The repository therefore keeps a first-party self-shaping scenario that runs the same public workflow used by consumers, while preserving the one packaging distinction that cannot be identical: the root checkout invokes `php bin/agent-loop`, whereas the installed-consumer gate invokes `vendor/bin/agent-loop`.

The self-shape job also contains one deliberately narrower preflight: `tools/self-edit-dogfood.php` creates an isolated linked Git worktree and lets `agent-loop edit --runner=mechanical` change a tiny tracked fixture in its own repository. The probe then requires Git-observed `changed_files` evidence for exactly that fixture and proves that no external model was invoked. The worktree is discarded afterward; the PR checkout is never mechanically rewritten by CI.

That distinction matters. The normal self-shape lifecycle governs the real PR diff. The self-edit probe separately proves that the product can actually execute and observe one edit against itself rather than merely review a diff produced by something else.

The self-shape lifecycle is intentionally observational. It may exercise a transition, generate review input, and preserve evidence, but it must not upgrade those actions into stronger claims than the evidence supports. In particular, CI cannot manufacture independent human approval or an independent correctness review merely because the corresponding workflow transition exists.

## Evidence chain

The validated source findings live under `.agent-loop/learning/findings/validated/`:

- `finding.2026-08-07.001`: internal workflow orchestration through focused-package CLIs duplicated argv, path, default, and failure knowledge that typed package APIs already owned;
- `finding.2026-08-07.002`: repository-under-development and installed-consumer execution have different binary entrypoints and must be tested separately;
- `finding.2026-08-07.003`: final blind-spot acceptance requires complete close-out evidence and machine-readable status `ok`; process exit success alone may still represent `warn`;
- `finding.2026-08-07.004`: GitHub Actions executes the local self-shape runner; the runner owns the lifecycle and derives the changed-file scope from Git so PLAN and REPORT share one source of truth;
- `finding.2026-08-07.005`: project-specific PHPStan rules must be tested in an isolated PHPStan process because loading `PHPStan\\Testing\\RuleTestCase` into normal PHPUnit polluted the runtime used by agent-map's Composer-based PHPStan discovery;
- `finding.2026-08-07.006`: the self-shape learning decision must agree with durable evidence in the real PR diff, using `findings_recorded` when findings or `MEMORY.md` are changed and `no_durable_learning` otherwise;
- `finding.2026-08-07.007`: pull-request self-dogfood needs repository history but no write credential, so its token is read-only and checkout credentials are not persisted into the PR-controlled workspace;
- `finding.2026-08-07.008`: workflow session creation/resume preserves one active session per task; restart reuses the active session and an already-corrupt multi-active state fails before further task work;
- `finding.2026-08-08.001`: linked Git worktrees store `.git` as a file; edit evidence must ask Git rather than infer repository validity from metadata shape, and self-edit dogfood now proves the real mechanical edit path in an isolated worktree;
- `finding.2026-08-08.002`: self-dogfood evidence must be observational rather than ceremonial: real PR intent and changed scope, measured validation duration, explicit approval-fixture semantics, lossless raw-diff evidence, and a boundary between generated review input and independent correctness judgment;
- `finding.2026-08-08.003`: an upstream source recheck is not an adaptation audit; mechanism-level ALREADY/ADAPT/DEFER/REJECT mapping plus concrete local ownership/evidence is required before claiming external coding-agent ideas are represented.

`MEMORY.md` contains only the reviewed durable rules. Objective recurrence prevention is pushed lower where possible:

- `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php` prevents `voku\\AgentLoop\\Workflow` from instantiating focused-package CLIs;
- `tools/project-phpstan-rules.sh` proves that rule in a separate PHPStan process without contaminating PHPUnit;
- workflow PHPUnit tests assert persisted session, brief, approval, verification, close behavior, and the one-active-session retry invariant instead of adapter argv;
- `WorkingTreeSnapshotter` asks Git whether a path is a working tree instead of assuming `.git` must be a directory, so linked worktrees retain observed-diff evidence;
- `tools/self-edit-dogfood.php` performs one deterministic mechanical edit in an isolated linked worktree and verifies the observed edit evidence;
- `tools/self-shape-dogfood.sh` owns the repeatable repository lifecycle, derives changed-file scope from Git, records the real PR goal when CI provides it, preserves a complete raw diff, measures validation duration, and separates generated review input from external correctness review;
- `.github/workflows/ci.yml` runs the isolated self-edit probe and then the normal self-shape runner with read-only/non-persisted repository credentials, passing only PR metadata already present in the event payload and uploading the evidence rather than granting either step write access;
- `tools/release-set-dogfood.php` remains the separate installed-consumer contract.

## Self-edit preflight

The self-edit probe intentionally does less than the governed lifecycle and more than a unit test:

1. create a detached linked worktree at the PR `HEAD`;
2. expose the already-installed Composer dependency tree without installing a second package set;
3. invoke the real `agent-loop edit` command against `SelfEditProbe::value`;
4. route through the mechanical runner with one exact replacement;
5. require PHP lint success from the runner;
6. require `agent-result.json` to report `changed_files_source=git_status_diff`;
7. require exactly `tests/fixtures/self-shape/SelfEditProbe.php` as the observed changed file;
8. require the execution artifact to prove the mechanical runner was used with zero model tokens/tool calls and `external_model_invoked=false`;
9. copy bounded JSON evidence into `build/` and delete the worktree.

It does not run `edit verify`: verifier-owned knowledge answers must not be copied from the private key merely to make a self-test green. The installed release-set work tracks full mechanical edit verification separately. Self-dogfood is not an excuse to let the product grade its own answers.

## Pull-request input contract

The GitHub Actions wrapper passes three pieces of event metadata into the runner:

- `SELF_SHAPE_GOAL`: the pull-request title;
- `SELF_SHAPE_APPROVER`: the pull-request author login;
- `SELF_SHAPE_PR_NUMBER`: the pull-request number.

They are passed as data, never evaluated as shell code. The runner also accepts explicit `--goal=`, `--approver=`, and `--pr-number=` overrides for local dogfood.

`build/self-shape-input.json` records the resulting goal, planner identity, approval fixture identity, base/head commits, changed files, and raw-diff path. Its approval section deliberately contains:

```json
{
  "evidence_kind": "ci_pr_author_fixture",
  "independent_human_review": false
}
```

The workflow still exercises its APPROVE transition because the gate mechanics themselves are part of the dogfood contract. The PR author identity is a harness fixture for that transition, not evidence that a separate human reviewed and approved the generated Contract revision. Actual review/merge policy remains outside that claim.

The planner and approval fixture must be different identities. A self-shape run that tries to use the planner as its own approver fails before PLAN.

## Lossless review evidence

The workflow skills tell agents to keep raw evidence lossless even when human-facing review context is bounded. Self-shape now proves that split directly:

- `build/self-shape-raw.diff` is the complete `git diff --no-ext-diff --binary <base> HEAD --` output;
- `review code SELF-SHAPE` generates the bounded L2 code-review prompt from workflow/recall artifacts;
- `build/self-shape-review-evidence.json` stores SHA-256 hashes for both artifacts;
- the same evidence JSON records `correctness_review.status=external_required`.

Generating a review prompt is not the same event as performing an independent correctness review. CI therefore preserves the complete input and identifies the missing external judgment instead of converting “prompt exists” into “review passed”. Pull-request review remains a separate merge input.

## Self-shape lifecycle

The runner performs this sequence against the real diff from the merge-base to `HEAD`:

1. validate the repository-owned `agent-learning` root;
2. derive the actual changed files and the matching learning decision from Git;
3. preserve the complete raw Git diff before any summary or bounded prompt is generated;
4. persist `self-shape-input.json` with base/head, real PR goal when available, changed files, planner, and approval-fixture boundary;
5. PLAN the actual changed files with explicit goal, non-goal, base commit, validation contract, and behavior anchor;
6. APPROVE with the distinct CI approval fixture and checkpoint that this exercises mechanics rather than proving independent human review;
7. render bounded context;
8. run `composer ci`, measure elapsed wall-clock milliseconds, and record that observed duration with the validation result;
9. run an exploratory deterministic blind-spot review;
10. run `recall log-outcome`;
11. record a checkpoint explicitly evidencing both `log-outcome` and `review blindspots` close-out;
12. record `findings_recorded` or `no_durable_learning` according to the durable evidence in the diff;
13. run the final blind-spot review and require its JSON report status to be exactly `ok`;
14. generate the L2 code-review prompt, hash it together with the complete raw diff, and explicitly leave correctness review as `external_required`;
15. review `MEMORY.md` promotion state;
16. persist the manifest, run `agent-loop verify`, and render the completion report;
17. CLOSE through the current deterministic workflow gates, persist final status, and require the final projection to be `complete` with `next_action=none`, session `done`, review `ok`, and the expected learning state.

The initial review may warn while the lifecycle is incomplete. The final deterministic blind-spot review may not. The CLI exit code is not the semantic gate because `warn` can still be a successfully executed review command; the runner reads `SELF-SHAPE.blindspots.json` and accepts only `status=ok`. Likewise, successful `workflow close` is not enough by itself: the persisted final projection is checked as machine-readable evidence before the dogfood run prints `PASSED`.

The deterministic close currently models the gates the product can verify itself. `self-shape-review-evidence.json` prevents that deterministic completion from being misreported as an independent correctness approval. The separate pull-request review/merge decision remains intentionally outside the self-issued evidence chain.

The CI wrapper is deliberately less privileged than the code it tests: the pull-request self-shape job declares `contents: read` and checks out with `persist-credentials: false`. Neither the self-edit probe nor the lifecycle runner needs a repository write credential. The linked worktree is local process state, not a hidden push path.

## Promotion rule

Do not automatically turn every self-run observation into memory. The project follows the normal learning boundary:

```text
runtime observation
  -> candidate / validated finding
  -> human-reviewed durable memory or guidance
  -> executable test / dogfood / PHPStan rule when objective
```

Independent review findings follow the same path. A review comment is evidence, not truth: it becomes durable only after the repository reproduces or validates the claim and the resulting change survives the normal CI/self-shape/release-set gates.

This file documents the chain; it is not another source of runtime truth.
