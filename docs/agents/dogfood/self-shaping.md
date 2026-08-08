# Self-shaping dogfood

`agent-loop` should be able to govern changes to `agent-loop` without inventing a privileged shortcut for its own repository.

The repository therefore keeps a first-party self-shaping scenario that runs the same public workflow used by consumers, while preserving the one packaging distinction that cannot be identical: the root checkout invokes `php bin/agent-loop`, whereas the installed-consumer gate invokes `vendor/bin/agent-loop`.

The self-shape job also contains one deliberately narrower preflight: `tools/self-edit-dogfood.php` creates an isolated linked Git worktree and lets `agent-loop edit --runner=mechanical` change a tiny tracked fixture in its own repository. The probe then requires Git-observed `changed_files` evidence for exactly that fixture and proves that no external model was invoked. The worktree is discarded afterwards; the PR checkout is never mechanically rewritten by CI.

That distinction matters. The normal self-shape lifecycle governs the real PR diff. The self-edit probe separately proves that the product can actually execute and observe one edit against itself rather than merely review a diff produced by something else.

## Evidence chain

The validated source findings live under `infra/doc/agent-learning/findings/validated/`:

- `finding.2026-08-07.001`: internal workflow orchestration through focused-package CLIs duplicated argv, path, default, and failure knowledge that typed package APIs already owned;
- `finding.2026-08-07.002`: repository-under-development and installed-consumer execution have different binary entrypoints and must be tested separately;
- `finding.2026-08-07.003`: final blind-spot acceptance requires complete close-out evidence and machine-readable status `ok`; process exit success alone may still represent `warn`;
- `finding.2026-08-07.004`: GitHub Actions executes the local self-shape runner; the runner owns the lifecycle and derives the changed-file scope from Git so PLAN and REPORT share one source of truth;
- `finding.2026-08-07.005`: project-specific PHPStan rules must be tested in an isolated PHPStan process because loading `PHPStan\\Testing\\RuleTestCase` into normal PHPUnit polluted the runtime used by agent-map's Composer-based PHPStan discovery;
- `finding.2026-08-07.006`: the self-shape learning decision must agree with durable evidence in the real PR diff, using `findings_recorded` when findings or `MEMORY.md` are changed and `no_durable_learning` otherwise;
- `finding.2026-08-07.007`: pull-request self-dogfood needs repository history but no write credential, so its token is read-only and checkout credentials are not persisted into the PR-controlled workspace;
- `finding.2026-08-07.008`: workflow session creation/resume preserves one active session per task; restart reuses the active session and an already-corrupt multi-active state fails before further task work.

`MEMORY.md` contains only the reviewed durable rules. Objective recurrence prevention is pushed lower where possible:

- `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php` prevents `voku\\AgentLoop\\Workflow` from instantiating focused-package CLIs;
- `tools/project-phpstan-rules.sh` proves that rule in a separate PHPStan process without contaminating PHPUnit;
- workflow PHPUnit tests assert persisted session, brief, approval, verification, close behavior, and the one-active-session retry invariant instead of adapter argv;
- `WorkingTreeSnapshotter` asks Git whether a path is a working tree instead of assuming `.git` must be a directory, so linked worktrees retain observed-diff evidence;
- `tools/self-edit-dogfood.php` performs one deterministic mechanical edit in an isolated linked worktree and verifies the observed edit evidence;
- `tools/self-shape-dogfood.sh` owns the repeatable repository lifecycle and derives its changed-file scope and learning decision from repository evidence;
- `.github/workflows/ci.yml` runs the isolated self-edit probe and then the normal self-shape runner with read-only/non-persisted repository credentials, uploading both evidence sets rather than granting either write access;
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

## Self-shape lifecycle

The runner performs this sequence against the real diff from the merge-base to `HEAD`:

1. validate the repository-owned `agent-learning` root;
2. derive the actual changed files and the matching learning decision from Git;
3. PLAN the actual changed files with explicit goal, non-goal, base commit, validation contract, and behavior anchor;
4. APPROVE and compile recall;
5. render bounded context;
6. run `composer ci` and record structured validation evidence;
7. run an exploratory deterministic blind-spot review;
8. run `recall log-outcome`;
9. record a checkpoint explicitly evidencing both `log-outcome` and `review blindspots` close-out;
10. record `findings_recorded` or `no_durable_learning` according to the durable evidence in the diff;
11. run the final blind-spot review and require its JSON report status to be exactly `ok`;
12. review `MEMORY.md` promotion state;
13. persist the manifest, run `agent-loop verify`, and render the completion report;
14. CLOSE through the normal workflow gates, persist final status, and require the final projection to be `complete` with `next_action=none`, session `done`, review `ok`, and the expected learning state.

The initial review may warn while the lifecycle is incomplete. The final review may not. The CLI exit code is not the semantic gate because `warn` can still be a successfully executed review command; the runner reads `SELF-SHAPE.blindspots.json` and accepts only `status=ok`. Likewise, successful `workflow close` is not enough by itself: the persisted final projection is checked as machine-readable evidence before the dogfood run prints `PASSED`.

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
