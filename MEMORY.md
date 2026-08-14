# Repository memory

This file contains reviewed, durable rules for `voku/agent-loop`. Raw observations belong in `.agent-loop/learning/findings/`; repeatable behavior belongs in tests, dogfood runners, or static rules. A row here is deliberately short because memory is an index into executable truth, not a replacement for it.

## Durable repository rules

| Subject | Durable rule | Canonical home |
| --- | --- | --- |
| Internal package boundaries | Workflow orchestration calls focused-package typed PHP APIs when they exist. User-facing package CLI delegation stays at the Dispatcher boundary; do not reconstruct focused-package argv inside `voku\\AgentLoop\\Workflow`. | `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php` and workflow tests |
| Workflow session invariant | Governed workflow orchestration has at most one active session per task. Start/plan may reuse that session; if more than one active session already exists, fail before further task work. Retries after partial failure must not allocate another active session. | `WorkflowStartCommand`, `WorkflowPlanCommand`, and workflow tests |
| Dogfood entrypoints | Repository self-dogfood executes `php bin/agent-loop`; installed-consumer dogfood separately proves `vendor/bin/agent-loop`. They are different packaging contracts and both remain covered. | `tools/self-shape-dogfood.sh` and `tools/release-set-dogfood.php` |
| Pull-request dogfood credentials | A PR self-shape job needs repository history, not write access. Keep its `GITHUB_TOKEN` at `contents: read` and use checkout with `persist-credentials: false` so PR-controlled code does not inherit a persisted repository credential. | `.github/workflows/ci.yml` and `.agent-loop/learning/findings/validated/finding.2026-08-07.007.json` |
| Self-review close-out | An exploratory blind-spot review may warn while work is open. Before close, run `recall log-outcome`, record a checkpoint that evidences both outcome close-out and `review blindspots`, then require the final machine-readable blind-spot status to be exactly `ok`. CLI exit 0 alone is not sufficient because `warn` is a successful command state. | `tools/self-shape-dogfood.sh` |
| Tooling test isolation | Project-specific PHPStan rule fixtures run in a separate PHPStan process. Do not load `PHPStan\\Testing\\RuleTestCase` into agent-loop's normal PHPUnit process while `agent-map` also performs Composer-based PHPStan discovery there. | `tools/project-phpstan-rules.sh` and `phpstan/project-rule-test.neon` |
| CI ownership | GitHub Actions executes the repository dogfood runner; it does not duplicate the governed lifecycle or maintain a second hard-coded changed-file list. The runner derives PR scope from Git. | `tools/self-shape-dogfood.sh` and `.github/workflows/ci.yml` |
| Learning decision consistency | The self-shape learning decision follows the durable evidence in the actual PR diff. If project findings or `MEMORY.md` are changed, record `findings_recorded`; otherwise record `no_durable_learning`. Do not close with a learning state that contradicts the repository evidence produced by the task. | `tools/self-shape-dogfood.sh` and `.agent-loop/learning/findings/validated/finding.2026-08-07.006.json` |
| Learning promotion | Findings are evidence, not durable memory. Promote only reviewed lessons; when a rule is objectively detectable, back the memory entry with a test, dogfood gate, or static-analysis rule. | `docs/workflow/learning-boundary.md` and `.agent-loop/learning/` |

## Archived task learning

| Archived on | Task | Summary | Archive reason | Durable lesson candidate | Promoted to |
| --- | --- | --- | --- | --- | --- |
| 2026-08-07 | `SELF-SHAPE` / PR #28 | Shaped workflow/session boundaries while `agent-loop` governed and reviewed its own change, then incorporated independent security and data-integrity review findings. | The task-specific evidence is captured by validated findings and CI artifacts; only stable rules belong above. | Prefer typed package APIs for internal orchestration, preserve one active session per governed task, keep PR dogfood credentials read-only/non-persistent, keep repository and consumer dogfood entrypoints distinct, isolate tooling tests, make final self-review evidence-complete with machine-readable status `ok`, and keep the learning decision consistent with the durable evidence actually produced. | `MEMORY.md`; `docs/agents/dogfood/self-shaping.md`; `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php`; workflow tests; `.github/workflows/ci.yml`; `tools/project-phpstan-rules.sh`; `tools/self-shape-dogfood.sh` |
