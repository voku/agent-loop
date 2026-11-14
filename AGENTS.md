<!-- agent-loop:project-instructions:begin -->
## agent-loop workflow router

Use `voku/agent-loop` for governed work; detailed procedures live in installed skills and CLI help. When setup is uncertain, run `bin/agent-loop init host-status --format=json` and obey `next_action_kind` / `next_action`; `runtime_boundary` grants no mutation authority. Troubleshoot with `bin/agent-loop init status` or `init sync-instructions`.

Before the CLI is runnable, do only reversible workspace bootstrap, not product edits, approvals, or owner artifacts. Missing dependencies or preferred tools alone are not terminal blockers.

For durable tasks, run `bin/agent-loop enter <task-id> --format=json` before edits and `bin/agent-loop finish <task-id> --format=json` after host work; follow each returned `next_action_kind` / `next_action` until `none` / complete. Execute `command`, fill evidence-backed `command_template`, perform `host_work`; only `decision_required` needs a genuine human-authority decision. Show the exact current decision subject; never invent approval.

Use skills for specialist work, not lifecycle phases; do not pre-build Map/Search or invent Session/Recall state. Use ephemeral sessions for untracked exploration. Claim hooks, checks, CI, merges, and releases only with evidence.

### Agent Learning

This project uses `voku/agent-learning` for reviewable evidence and durable guidance.
- Durable findings, proposals, and LearningNotes live below `.agent-loop/learning/`.
- Record validated findings or LearningNotes using the canonical learning commands/skills (`agent-learning-consumer`, `agent-learning-note`).
- Never auto-promote unverified findings, raw LLM observations, or dream output into active guidance.
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

## Codelight reasoning lens

For non-trivial engineering work, use `voku/agent-skills/engineering-codelight` as a compact reasoning lens inside the current Loop action. The canonical long-form reference lives in `voku/learnings/CODELIGHT_MANIFESTO.md`. Neither the manifesto nor the skill owns Loop lifecycle state, approvals, mutation authority, or required evidence.

When a repeated Codelight lesson can become a typed owner API, test, static-analysis rule, generated check, or other deterministic constraint, prefer that structure and remove duplicated prose instead of growing this file.

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
- Prefer action over commentary when the requested outcome is reachable with current repository, host, or GitHub capabilities. Discover available mutation tools before declaring an intermediate artifact missing or handing work back to the user. If the safe path is branch -> change -> PR -> exact-head validation -> merge -> release marker/tag, execute that path as far as current authority permits; only report a blocker when the next genuinely required action cannot be performed.

## Validation

The minimum repository gate is:

```bash
composer ci
```

Changes to cross-package boundaries, edit/refactoring paths, lifecycle authority, Recall integration, or execution contracts should also run the relevant repository dogfood/installed-consumer workflows. Do not replace executable evidence with a prose claim.

## Releases

Releases are marker-driven. A `.release/<version>.json` marker must point at a release-ready ancestor commit whose own `CHANGELOG.md` already contains that version. Existing tags are immutable release evidence.

When release work is requested and the tag is missing, treat that as work to complete, not as a terminal status to report. If the repository's existing marker/tag flow and available GitHub mutations can produce the missing prerequisites safely, prepare the changelog/release commit, validate the exact current head, merge it, publish the marker, and verify the resulting tag before moving on.
