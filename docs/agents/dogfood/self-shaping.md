# Self-shaping dogfood

`agent-loop` should be able to govern changes to `agent-loop` without inventing a privileged shortcut for its own repository.

The repository therefore keeps a first-party self-shaping scenario that runs the same public workflow used by consumers, while preserving the one packaging distinction that cannot be identical: the root checkout invokes `php bin/agent-loop`, whereas the installed-consumer gate invokes `vendor/bin/agent-loop`.

## Evidence chain

The durable source findings live under `infra/doc/agent-learning/findings/`:

- `finding.2026-08-07.001`: internal workflow orchestration through focused-package CLIs duplicated argv, path, default, and failure knowledge that typed package APIs already owned;
- `finding.2026-08-07.002`: repository-under-development and installed-consumer execution have different binary entrypoints and must be tested separately;
- `finding.2026-08-07.003`: the final blind-spot review must run after recall outcome close-out and an explicit review checkpoint, not before them;
- `finding.2026-08-07.004`: candidate finding that CI should invoke a local self-shape runner and let that runner derive changed-file scope from Git.

`MEMORY.md` contains only the reviewed durable rules. Objective recurrence prevention is pushed lower where possible:

- `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php` prevents `voku\\AgentLoop\\Workflow` from instantiating focused-package CLIs;
- workflow PHPUnit tests assert persisted session, brief, approval, verification, and close behavior instead of adapter argv;
- `tools/self-shape-dogfood.php` owns the repeatable repository lifecycle;
- `.github/workflows/ci.yml` invokes that runner and uploads its evidence rather than defining the lifecycle itself;
- `tools/release-set-dogfood.php` remains the separate installed-consumer contract.

## Self-shape lifecycle

The runner performs this sequence against the real diff from the merge-base to `HEAD`:

1. validate the repository-owned `agent-learning` root;
2. PLAN the actual changed files with explicit goal, non-goal, base commit, validation contract, and behavior anchor;
3. APPROVE and compile recall;
4. render bounded context;
5. run `composer ci` and record structured validation evidence;
6. run an exploratory deterministic blind-spot review;
7. log recall outcome close-out;
8. record a checkpoint containing the explicit `review blindspots` marker;
9. record the learning decision;
10. require the final blind-spot review to exit clean;
11. review `MEMORY.md` promotion state;
12. persist the manifest, run `agent-loop verify`, and render the completion report;
13. CLOSE through the normal workflow gates and persist final status.

The initial review is allowed to return the documented warning exit while the lifecycle is incomplete. The final review is not. A warning at final review is a failed dogfood run, not a success with nicer wording.

## Promotion rule

Do not automatically turn every self-run observation into memory. The project follows the normal learning boundary:

```text
runtime observation
  -> candidate / validated finding
  -> human-reviewed durable memory or guidance
  -> executable test / dogfood / PHPStan rule when objective
```

This file documents the chain; it is not another source of runtime truth.
