<!-- agent-loop:project-instructions:begin -->
## agent-loop workflow router

This repository uses `voku/agent-loop` for governed coding work. Keep this always-on router small; detailed procedures live in the installed skills and CLI help. If host integration is uncertain, run `bin/agent-loop init host-status --format=json` and obey its `next_action_kind` / `next_action` until no repository-owned action remains; `runtime_boundary` describes host/user scope and is not authority to mutate it. Troubleshooting only: use `bin/agent-loop init status` to inspect broader setup and `init sync-instructions` when managed instruction projection itself needs repair.

Before the lifecycle CLI is runnable, recover only the minimum reversible workspace/tool bootstrap needed to execute the repository's declared workflow: inspect the checkout/remotes, fetch the obvious public repository, install already-declared Composer dependencies, obtain required public sibling checkouts for cross-package work, discover available host/GitHub capabilities without exposing credentials, and establish an isolated branch or worktree before implementation. This bootstrap is not product-code mutation and does not authorize approval, task state, or owner artifacts. Missing `vendor/`, a missing remote, or an unavailable preferred PR/push tool is not by itself a terminal workflow blocker; continue safe local work until the next genuinely required action cannot be performed.

For a durable task id:

1. Before mutating product code, run `bin/agent-loop enter <task-id> --format=json` and obey the returned `next_action_kind` / `next_action`. `command` means execute it as written; `command_template` means fill model-owned placeholders from the actual request and repository evidence and execute it without asking a human merely because placeholders exist; `decision_required` means a genuine human-authority decision is required, so show the exact current decision subject before asking; `host_work` means perform the described host-native implementation/model work; `none` means there is no further lifecycle action. Never fabricate an approval or risk owner.
2. Use repository-managed skills and subagents when their descriptions match the task. Do not recreate their procedures from conversational memory. In particular, do not pre-build Map/Search, create Session/Recall state, or infer approval/close ordering: deterministic prerequisites and repairs must come from the canonical lifecycle result.
3. When host-native mutation is complete, run `bin/agent-loop finish <task-id> --format=json`, then obey its canonical next step until `next_action_kind=none` and the result is complete. If a human decision is requested, present the exact Contract/review/Learning/risk evidence being decided instead of asking for a generic confirmation. If an advertised command deterministically refuses without changing the next step, report a workflow defect rather than teaching the host a private workaround.
4. Never claim that hooks fired, checks passed, CI is green, a PR merged, or a release/deploy shipped unless current evidence proves it.

For untracked exploration, use an ephemeral session rather than inventing a durable task.
<!-- agent-loop:project-instructions:end -->

## Repository ownership map

Keep this section outside the managed router markers above. `init sync-instructions` owns the router block; this repository-specific architecture guidance must not be projected into sibling repositories as generic workflow text.

`voku/agent-loop` is the lifecycle/orchestration owner. It owns governed Contract/Run state, approval and human-decision routing, canonical next actions, mutation/edit authority, validation/review/Learning coordination, and the typed projections that embedding hosts need to understand current workflow authority.

It deliberately composes narrower owners rather than absorbing them:

- `voku/agent-kanban` owns board/card parsing, policy, queries, verification, and card mutation semantics.
- `voku/agent-session` owns pruneable working memory, Session identity, checkpoints, and validation observations.
- `voku/agent-map` owns read-only repository facts and typed edit/refactoring plans; plans are evidence, not mutation authority.
- `voku/agent-recall-compiler` owns bounded Recall context, provenance, Recall artifacts, operating-prompt recipes/rendering, and recipe applicability metadata.
- `voku/agent-learning` owns durable Findings, Proposals, evidence, decisions, guidance evolution, and constraint semantics.
- `voku/agent-loop-runner` is an optional execution consumer of Loop. The inverse dependency is forbidden.
- `voku/agent-ui` is a presentation/control-plane consumer. It must not become a workflow or owner-policy source.

## Cross-owner changes

When a feature exposes a missing semantic capability, change the semantic owner first instead of reconstructing its private files, JSON keys, CLI prose, or policy in Loop.

Preferred order:

1. add the smallest typed owner API/projection and owner regression coverage;
2. merge and release that owner through its marker-driven release flow;
3. bump Loop to the stable owner version;
4. integrate through the typed API;
5. run Loop's own validation/dogfood gates.

Do not leave a final integration on `dev-main`, a path repository, or copied owner semantics when a stable owner release is the intended contract. Temporary candidate wiring is evidence only and must not become the published dependency boundary.

For prompt-related work specifically: Recall owns recipe catalog/arguments/rendering/applicability metadata; Loop owns workflow/lifecycle authority and workflow prompt envelopes. A UI or runner may compose those projections, but Loop must not copy the recipe catalog and Recall must not acquire lifecycle policy.

## Implementation rules

- Prefer typed public owner APIs over subprocess/CLI parsing inside production orchestration.
- Keep generated evidence non-authoritative until the owning gate accepts it.
- Fail closed on stale provenance, unsupported contract versions, ambiguous owner state, or source identity drift before mutation.
- Do not turn Loop into a generic mutation engine merely because multiple safe edit/refactoring plans can cross its existing edit boundary.
- Keep development-only dogfood, architecture checks, and probes outside production autoload unless runtime consumers genuinely need them.

## Validation

The minimum repository gate is:

```bash
composer ci
```

Changes to cross-package boundaries, edit/refactoring paths, lifecycle authority, Recall integration, or execution contracts should also run the relevant repository dogfood/installed-consumer workflows. Do not replace executable evidence with a prose claim.

## Releases

Releases are marker-driven. A `.release/<version>.json` marker must point at a release-ready ancestor commit whose own `CHANGELOG.md` already contains that version. Existing tags are immutable release evidence.
