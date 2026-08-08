# First-Party Agent Capability Matrix

This document is the source-level inventory for how first-party agent behavior is owned and projected. It complements `UPSTREAM_CAPABILITY_MATRIX.md`: upstream projects are cross-checks; this file starts from our own repositories and executable contracts.

The invariant is:

> Author behavior once at the correct semantic owner. Project it into each host's native mechanism. Make unsupported behavior explicit. Verify observable behavior, not merely generated files.

## Ownership

| Capability | Semantic owner | Current agent-loop role | Classification | Evidence / next gap |
|---|---|---|---|---|
| Historical engineering principles and lesson evolution | `voku/learnings` | Architecture input only; never dumped wholesale into runtime context | `KEEP` | Agentic Coding lessons already encode process, bounded context, earned memory, verification, resumability, and architecture feedback |
| Reusable PHP/testing/security/performance/architecture/type/simplicity guidance | `voku/agent-skills` | Select/install/use capability; do not duplicate the engineering truth | `ADAPT` | `agent-skills` owns `code-review-*`, `php-best-practices`, `testing-best-practices`, `code-slop`, and `operational-prompting`; `install-assets --extra-skills-root` now merges an explicit local source without copying its semantics into agent-loop |
| Workflow phase, approval, validation, review gate, learning decision, verify, close | `voku/agent-loop` | Canonical workflow owner | `KEEP` | Persisted workflow/session/recall/learning artifacts and close gates |
| Task assumptions, decisions, checkpoints, validation evidence | `voku/agent-session` | Orchestrate through typed package boundary | `KEEP` | Do not scrape focused-package storage or reconstruct argv internally |
| Bounded symbol/caller/context navigation | `voku/agent-map` | Select source context before assertions/reviews | `KEEP` | Generated map is navigation, real source remains evidence |
| Task-scoped L2 context compilation | `voku/agent-recall-compiler` | Compile/select task guidance | `KEEP` | Context is a dependency; do not replace it with prompt dumping |
| Findings, proposals, evidence, reviewed learning boundary | `voku/agent-learning` | Record reusable observations and require review before durable guidance | `KEEP` | Findings are not memory; no automatic promotion |
| Process/evidence blind-spot check | `voku/agent-loop` | `review blindspots` deterministic lifecycle check | `KEEP` | Must remain distinct from LLM engineering review lenses |
| Correctness review workflow contract | `voku/agent-loop` | Own exact diff/context input, no-mutation boundary, terminal state, persistence/routing | `THIN_ADAPTER` | General engineering review semantics must not keep growing here |
| General simplicity review semantics | `voku/agent-skills` | `agent-loop` may add only agent-* ownership/map-specific overlay | `MOVE_SEMANTICS` | Reconcile `agent-loop-simplify-review` / audit against `code-review-simplicity` without losing workflow evidence contracts; tracked by #36 |
| Host skill installation/projection | `voku/agent-loop` | Merge one or more explicit canonical skill roots, project them to the native host root, track one managed manifest | `ADAPT` | All roots are checked/collected before target mutation; missing/unreadable explicit roots and duplicate skill IDs fail early; one managed manifest prevents one source from making another look stale; runtime never downloads a source |
| Host subagent/custom-agent representation | `voku/agent-loop` | Parse one role meaning and render native host representation | `KEEP` | `SubagentDefinition` already acts as a small compiler; extend only for proven missing semantics |
| Host hook/bootstrap representation | `voku/agent-loop` | Project shared discipline policy through explicit host mechanics | `ADAPT` | Codex and Claude adapters are contract-tested but remain degraded until live host execution is observed. Copilot now documents repository hooks, but agent-loop has no adapter/runtime proof yet; Antigravity has no adapter and remains unsupported |
| Host capability/degradation reporting | `voku/agent-loop` | Typed matrix + `init doctor` projection | `ADAPT` | `HostCapabilityMatrix` reports evidence level, not optimistic feature availability |

## Host projection levels

Different asset types have different portability. Do not force them through one fake universal schema.

| Asset | Current portability | Current implementation |
|---|---|---|
| Skills | High | One or more canonical local skill roots are merged before mutation, then projected into `.codex/skills`, `.claude/skills`, `.github/skills`, or `.agents/skills` through one manifest |
| Subagents/custom agents | Medium | Canonical Markdown role parsed by `SubagentDefinition`, rendered as Codex TOML, Copilot `.agent.md`, or host Markdown/frontmatter |
| Session/subagent discipline bootstrap | Low | Codex/Claude native hook adapters exist and are contract-tested; runtime execution/propagation is not yet observed, so capability stays degraded |
| Pre-tool guardrail | Low | Shared typed policy is exposed through Codex/Claude hook adapters; runtime execution is not yet observed, so capability stays degraded |
| Repository hook registration | Low | Codex hook bundle and Claude `settings.json#hooks` require different installation/registration mechanics; registration alone is not runtime proof |

The typed current-state projection lives in `HostCapabilityMatrix`. `init doctor` renders that model so unsupported or degraded behavior is visible instead of silently disappearing.

## Skill-source boundary

`agent-loop` keeps its bundled workflow skill root authoritative and may merge additional explicitly supplied **local** skill roots. This is how `voku/agent-skills` can remain the canonical owner of reusable engineering knowledge without copying its content into `agent-loop`.

The merge contract is deliberately boring:

- roots are collected and explicit roots are checked before writing targets;
- an explicitly requested missing root fails the operation;
- a source root that exists but cannot be read/enumerated fails before target or manifest mutation;
- the same skill directory name in two roots is an error;
- all selected skills share one target manifest, so a second source cannot accidentally make the first source look stale;
- `install-assets` never clones or downloads the additional source;
- the caller owns source provenance and may pin/check out the source before invocation.

This is a prevalidated single managed projection, not a filesystem transaction. A later I/O failure is still an ordinary write failure; there is no rollback claim.

PR CI dogfoods this contract with `voku/agent-skills` pinned to commit `b5b910666c08e30950e2a5999d9c4a447b31e367`. A clean Composer consumer installs the candidate `agent-loop`, passes the pinned checkout as `--extra-skills-root`, and verifies that `agent-loop-discipline`, `code-review-security`, and `operational-prompting` coexist in the projected host skill trees. The pin is test provenance, not a runtime dependency.

## Projection is not runtime behavior

The first self-shape pass of this PR was mechanically green, but review tightened the model from generic `skills` / `subagents` support to `skill-projection` / `subagent-projection`. The clean-consumer gate proves that agent-loop can render/install those assets for the target host. It does **not** by itself prove that a host discovers them in every session, propagates them into delegated work, or executes their semantics correctly.

Those runtime properties need their own capability rows and evidence. This is why bootstrap, propagation, guardrails, and hook support remain separate from file projection.

## Current host capability truth

The matrix describes the strongest evidence **this repository currently owns**, not everything a vendor product may theoretically support.

Status meanings are intentionally strict:

- `supported`: agent-loop behavior is directly exercised by executable evidence at the claimed boundary;
- `degraded`: agent-loop has a native adapter and contract tests, but the host runtime/delegation behavior itself has not been observed;
- `unsupported`: agent-loop has no adapter for the claimed capability.

| Capability | Codex | Claude | Copilot | Antigravity |
|---|---|---|---|---|
| skill projection | supported | supported | supported | supported |
| subagent projection | supported | supported | supported | supported |
| session bootstrap | degraded | degraded | unsupported | unsupported |
| subagent bootstrap | degraded | degraded | unsupported | unsupported |
| pre-tool guardrail | degraded | degraded | unsupported | unsupported |
| repository hooks | degraded | degraded | unsupported | unsupported |

GitHub now documents repository-level Copilot hooks under `.github/hooks/*.json`, including `sessionStart`, `subagentStart`, and `preToolUse` for Copilot CLI/cloud agent. That proves a vendor mechanism exists; it does not prove our PHP hook commands execute safely in every Copilot runtime. In particular, command-hook failures for `preToolUse` can deny tool execution. The Copilot cell therefore remains `unsupported` until an agent-loop adapter and executable runtime evidence exist. See [GitHub Copilot hooks reference](https://docs.github.com/en/copilot/reference/hooks-reference).

A future host adapter may change an `unsupported` or `degraded` cell only with evidence at the boundary the new status claims. Do not infer runtime support from generated files or a vendor feature name that merely sounds similar.

## Review ownership audit discovered by dogfood

The PR's own review path made the next ownership problem concrete instead of leaving it as a noun in the roadmap. Current review assets contain several different contracts that should not share one owner.

| Current source/concept | Correct owner | Classification | Required direction |
|---|---|---|---|
| `agent-loop-code-review`: complete raw diff, exact source/caller evidence, no mutation, `findings|clean|blocked` terminal state | `voku/agent-loop` | `WORKFLOW_CONTRACT` | Keep; this is routing/evidence behavior, not a technical review handbook |
| `agent-loop-code-review`: general security/architecture/type/performance judgment as it grows | `voku/agent-skills` | `ENGINEERING_SEMANTIC` | Do not duplicate; dispatch the dominant first-party lens instead |
| `agent-loop-simplify-review`: `delete/reuse/stdlib/native/yagni/shrink` engineering judgment | `voku/agent-skills` | `ENGINEERING_SEMANTIC` | Reconcile with `code-review-simplicity`; keep only workflow and agent-* overlay locally |
| `agent-loop-simplify-review`: `agent-map changed/file/related`, agent-* package ownership, no automatic edits | `voku/agent-loop` | `AGENT_LOOP_OVERLAY` | Keep thin and explicit |
| `agent-loop-simplify-audit`: bounded candidate selection and real-source/caller verification | `voku/agent-loop` | `WORKFLOW_CONTRACT` | Keep the audit/navigation contract; technical simplicity rules should come from the canonical lens |
| `agent-skills/code-review-*`: concern-specific technical rules and required evidence | `voku/agent-skills` | `ENGINEERING_SEMANTIC` / `LENS_EVIDENCE_CONTRACT` | Keep and make each lens independently dispatchable |
| `agent-skills/code-review-*`: mandatory six-pass protocol, global precedence, merged-finding/dedupe policy | workflow caller (`agent-loop` when it is the caller) | `WORKFLOW_ORCHESTRATION` | Remove from individual lens semantics; one dominant lens first, at most one evidence-backed handoff |
| `agent-skills/code-review-simplicity`: mandatory bound-range enumeration for every non-blocked review | `voku/agent-skills` | `OVERFIT_RULE` | Make conditional on changed/relevant bounds instead of blocking unrelated simplicity reviews |
| `agent-loop review blindspots` | `voku/agent-loop` | `PROCESS_EVIDENCE` | Keep deterministic and separate from every engineering lens |

This is intentionally an audit, not the migration itself. The cross-repository implementation is tracked in #36. `voku/agent-skills` currently has GitHub Issues disabled, so the integration issue lives here without changing semantic ownership.

The target composition is:

```text
agent-loop REVIEW
    owns exact scope/evidence, selects one dominant engineering capability,
    persists the result, and decides workflow progression
        |
        +--> agent-skills/code-review-<dominant concern>
                owns engineering judgment for that concern
                may request at most one focused follow-up when evidence warrants it
```

## Non-goals

- no new repository/package for each capability, renderer, review lens, or schema;
- no giant universal YAML that models every host-specific knob;
- no lowest-common-denominator behavior to make hosts look symmetrical;
- no remote marketplace/network dependency in normal `agent-loop init` or workflow execution;
- no tests that equate generated file presence with delegated runtime behavior;
- no automatic durable-memory promotion.

## Dogfood requirement

Changes to this area must run through `agent-loop` itself. PR CI executes `tools/self-shape-dogfood.sh`, which builds a real governed brief from the PR diff, compiles context, runs `composer ci`, records validation, performs deterministic process blind-spot review, records the learning decision, verifies, reports, and closes the self-shape task.

Installed release-set dogfood must additionally prove that a clean consumer can execute `agent-loop init doctor` and observe the host capability projection shipped by the candidate package. When engineering skill-source integration changes, that consumer must also project the real pinned first-party `voku/agent-skills` source rather than a hand-written lookalike fixture alone.
