# Self-shaping dogfood

`agent-loop` should be able to govern changes to `agent-loop` without inventing a privileged shortcut for its own repository.

The repository therefore keeps a first-party self-shaping scenario that runs the same public workflow used by consumers, while preserving the one packaging distinction that cannot be identical: the root checkout invokes `php bin/agent-loop`, whereas the installed-consumer gate invokes `vendor/bin/agent-loop`.

## Evidence chain

The validated source findings live under `infra/doc/agent-learning/findings/validated/`:

- `finding.2026-08-07.001`: internal workflow orchestration through focused-package CLIs duplicated argv, path, default, and failure knowledge that typed package APIs already owned;
- `finding.2026-08-07.002`: repository-under-development and installed-consumer execution have different binary entrypoints and must be tested separately;
- `finding.2026-08-07.003`: final blind-spot acceptance requires complete close-out evidence and machine-readable status `ok`; process exit success alone may still represent `warn`;
- `finding.2026-08-07.004`: GitHub Actions executes the local self-shape runner; the runner owns the lifecycle and derives the changed-file scope from Git so PLAN and REPORT share one source of truth;
- `finding.2026-08-07.005`: project-specific PHPStan rules must be tested in an isolated PHPStan process because loading `PHPStan\\Testing\\RuleTestCase` into normal PHPUnit polluted the runtime used by agent-map's Composer-based PHPStan discovery;
- `finding.2026-08-07.006`: the self-shape learning decision must agree with durable evidence in the real PR diff, using `findings_recorded` when findings or `MEMORY.md` are changed and `no_durable_learning` otherwise.

`MEMORY.md` contains only the reviewed durable rules. Objective recurrence prevention is pushed lower where possible:

- `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php` prevents `voku\\AgentLoop\\Workflow` from instantiating focused-package CLIs;
- `tools/project-phpstan-rules.sh` proves that rule in a separate PHPStan process without contaminating PHPUnit;
- workflow PHPUnit tests assert persisted session, brief, approval, verification, and close behavior instead of adapter argv;
- `tools/self-shape-dogfood.sh` owns the repeatable repository lifecycle and derives its changed-file scope and learning decision from repository evidence;
- `.github/workflows/ci.yml` invokes that runner and uploads its evidence rather than defining the lifecycle itself;
- `tools/release-set-dogfood.php` remains the separate installed-consumer contract.

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

## Promotion rule

Do not automatically turn every self-run observation into memory. The project follows the normal learning boundary:

```text
runtime observation
  -> candidate / validated finding
  -> human-reviewed durable memory or guidance
  -> executable test / dogfood / PHPStan rule when objective
```

This file documents the chain; it is not another source of runtime truth.
