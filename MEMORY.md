# Repository memory

This file contains reviewed, durable rules for `voku/agent-loop`. Raw observations belong in `infra/doc/agent-learning/findings/`; repeatable behavior belongs in tests, dogfood runners, or static rules. A row here is deliberately short because memory is an index into executable truth, not a replacement for it.

## Durable repository rules

| Subject | Durable rule | Canonical home |
| --- | --- | --- |
| Internal package boundaries | Workflow orchestration calls focused-package typed PHP APIs when they exist. User-facing package CLI delegation stays at the Dispatcher boundary; do not reconstruct focused-package argv inside `voku\\AgentLoop\\Workflow`. | `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php` and workflow tests |
| Dogfood entrypoints | Repository self-dogfood executes `php bin/agent-loop`; installed-consumer dogfood separately proves `vendor/bin/agent-loop`. They are different packaging contracts and both remain covered. | `tools/self-shape-dogfood.sh` and `tools/release-set-dogfood.php` |
| Self-review close-out | An exploratory blind-spot review may warn while work is open. Before close, log recall outcome, record an explicit `review blindspots` checkpoint, then require the final blind-spot review to be clean. | `tools/self-shape-dogfood.sh` |
| CI ownership | GitHub Actions executes the repository dogfood runner; it does not duplicate the governed lifecycle or maintain a second hard-coded changed-file list. The runner derives PR scope from Git. | `tools/self-shape-dogfood.sh` and `.github/workflows/ci.yml` |
| Learning promotion | Findings are evidence, not durable memory. Promote only reviewed lessons; when a rule is objectively detectable, back the memory entry with a test, dogfood gate, or static-analysis rule. | `docs/workflow/learning-boundary.md` and `infra/doc/agent-learning/` |

## Archived task learning

| Archived on | Task | Summary | Archive reason | Durable lesson candidate | Promoted to |
| --- | --- | --- | --- | --- | --- |
| 2026-08-07 | `SELF-SHAPE` / PR #28 | Shaped workflow/session boundaries while `agent-loop` governed and reviewed its own change. | The task-specific evidence is captured by validated findings and CI artifacts; only stable rules belong above. | Prefer typed package APIs for internal orchestration, keep repository and consumer dogfood entrypoints distinct, and make final self-review evidence-complete before close. | `MEMORY.md`; `docs/agents/dogfood/self-shaping.md`; `phpstan/Rules/NoFocusedPackageCliInWorkflowRule.php`; `tools/self-shape-dogfood.sh` |
