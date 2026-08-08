# First-Party Agent Capability Matrix

This document is the source-level inventory for how first-party agent behavior is owned and projected. It complements `UPSTREAM_CAPABILITY_MATRIX.md`: upstream projects are cross-checks; this file starts from our own repositories and executable contracts.

The invariant is:

> Author behavior once at the correct semantic owner. Project it into each host's native mechanism. Make unsupported behavior explicit. Verify observable behavior, not merely generated files.

## Ownership

| Capability | Semantic owner | Current agent-loop role | Classification | Evidence / next gap |
|---|---|---|---|---|
| Historical engineering principles and lesson evolution | `voku/learnings` | Architecture input only; never dumped wholesale into runtime context | `KEEP` | Agentic Coding lessons already encode process, bounded context, earned memory, verification, resumability, and architecture feedback |
| Reusable PHP/testing/security/performance/architecture/type/simplicity guidance | `voku/agent-skills` | Select/install/use capability; do not duplicate the engineering truth | `ADAPT` | `agent-skills` already owns `code-review-*`, `php-best-practices`, `testing-best-practices`, `code-slop`, and `operational-prompting`; deterministic release integration is still missing |
| Workflow phase, approval, validation, review gate, learning decision, verify, close | `voku/agent-loop` | Canonical workflow owner | `KEEP` | Persisted workflow/session/recall/learning artifacts and close gates |
| Task assumptions, decisions, checkpoints, validation evidence | `voku/agent-session` | Orchestrate through typed package boundary | `KEEP` | Do not scrape focused-package storage or reconstruct argv internally |
| Bounded symbol/caller/context navigation | `voku/agent-map` | Select source context before assertions/reviews | `KEEP` | Generated map is navigation, real source remains evidence |
| Task-scoped L2 context compilation | `voku/agent-recall-compiler` | Compile/select task guidance | `KEEP` | Context is a dependency; do not replace it with prompt dumping |
| Findings, proposals, evidence, reviewed learning boundary | `voku/agent-learning` | Record reusable observations and require review before durable guidance | `KEEP` | Findings are not memory; no automatic promotion |
| Process/evidence blind-spot check | `voku/agent-loop` | `review blindspots` deterministic lifecycle check | `KEEP` | Must remain distinct from LLM engineering review lenses |
| Correctness review workflow contract | `voku/agent-loop` | Own exact diff/context input, no-mutation boundary, terminal state, persistence/routing | `THIN_ADAPTER` | General engineering review semantics must not keep growing here |
| General simplicity review semantics | `voku/agent-skills` | `agent-loop` may add only agent-* ownership/map-specific overlay | `MOVE_SEMANTICS` | Reconcile `agent-loop-simplify-review` / audit against `code-review-simplicity` without losing workflow evidence contracts |
| Host skill installation/projection | `voku/agent-loop` | Resolve canonical skill source, project to native host root, track managed entries | `ADAPT` | Current implementation mostly copies one `SKILL.md` tree; add deterministic `agent-skills` source integration without remote runtime fetches |
| Host subagent/custom-agent representation | `voku/agent-loop` | Parse one role meaning and render native host representation | `KEEP` | `SubagentDefinition` already acts as a small compiler; extend only for proven missing semantics |
| Host hook/bootstrap representation | `voku/agent-loop` | Project shared discipline policy through explicit host mechanics | `ADAPT` | Codex and Claude are implemented differently; Copilot/Antigravity behavior must stay explicitly unsupported until verified and implemented |
| Host capability/degradation reporting | `voku/agent-loop` | Typed matrix + `init doctor` projection | `ADAPT` | `HostCapabilityMatrix` starts with current repository facts; later revisions must be evidence-backed and tested |

## Host projection levels

Different asset types have different portability. Do not force them through one fake universal schema.

| Asset | Current portability | Current implementation |
|---|---|---|
| Skills | High | Same canonical skill directory copied into `.codex/skills`, `.claude/skills`, `.github/skills`, or `.agents/skills` |
| Subagents/custom agents | Medium | Canonical Markdown role parsed by `SubagentDefinition`, rendered as Codex TOML, Copilot `.agent.md`, or host Markdown/frontmatter |
| Session/subagent discipline bootstrap | Low | Implemented through Codex/Claude hook lifecycles; unsupported for other canonical hosts until a real native mechanism is implemented |
| Pre-tool guardrail | Low | Shared typed policy is exposed through Codex/Claude hook adapters; unsupported elsewhere today |
| Repository hook registration | Low | Codex hook bundle and Claude `settings.json#hooks` require different installation/registration mechanics |

The typed current-state projection lives in `HostCapabilityMatrix`. `init doctor` renders that model so unsupported behavior is visible instead of silently disappearing.

## Projection is not runtime behavior

The first self-shape pass of this PR was mechanically green, but review tightened the model from generic `skills` / `subagents` support to `skill-projection` / `subagent-projection`. The clean-consumer gate proves that agent-loop can render/install those assets for the target host. It does **not** by itself prove that a host discovers them in every session, propagates them into delegated work, or executes their semantics correctly.

Those runtime properties need their own capability rows and evidence. This is why bootstrap, propagation, guardrails, and hook support remain separate from file projection.

## Current host capability truth

The matrix below describes what **this repository currently implements**, not everything a vendor product may theoretically support.

| Capability | Codex | Claude | Copilot | Antigravity |
|---|---|---|---|---|
| skill projection | supported | supported | supported | supported |
| subagent projection | supported | supported | supported | supported |
| session bootstrap | supported | supported | unsupported | unsupported |
| subagent bootstrap | supported | supported | unsupported | unsupported |
| pre-tool guardrail | supported | supported | unsupported | unsupported |
| repository hooks | supported | supported | unsupported | unsupported |

A future host adapter may change an `unsupported` cell only with source/runtime evidence and executable tests. Do not infer support from a vendor feature name that merely sounds similar.

## Review boundary to reconcile next

`agent-loop-code-review`, `agent-loop-simplify-review`, and `agent-loop-simplify-audit` currently mix two kinds of knowledge:

1. workflow-specific contracts that belong here: exact raw diff, bounded source/caller lookup, no mutation, deterministic terminal status, task evidence and routing;
2. general engineering judgment that should be canonical in `voku/agent-skills`.

The next slice must classify those rules before deleting or merging anything. One dominant engineering lens should be selected first; do not create a mandatory multi-lens swarm.

## Non-goals

- no new repository/package for each capability, renderer, review lens, or schema;
- no giant universal YAML that models every host-specific knob;
- no lowest-common-denominator behavior to make hosts look symmetrical;
- no remote marketplace/network dependency in normal `agent-loop init` or workflow execution;
- no tests that equate generated file presence with delegated runtime behavior;
- no automatic durable-memory promotion.

## Dogfood requirement

Changes to this area must run through `agent-loop` itself. PR CI executes `tools/self-shape-dogfood.sh`, which builds a real governed brief from the PR diff, compiles context, runs `composer ci`, records validation, performs deterministic process blind-spot review, records the learning decision, verifies, reports, and closes the self-shape task.

Installed release-set dogfood must additionally prove that a clean consumer can execute `agent-loop init doctor` and observe the host capability projection shipped by the candidate package.
