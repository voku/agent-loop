# First-Party Agent Capability Matrix

This is the current ownership map for first-party agent behavior. Historical migration notes belong in PRs/issues, not in the live architecture contract.

> Author behavior once at the semantic owner. Project it through thin host/workflow adapters. Generated files are not runtime evidence.

## Semantic ownership

| Capability | Owner | `agent-loop` responsibility |
|---|---|---|
| Engineering lessons and principle history | `voku/learnings` | Architecture input only; never dump it wholesale into runtime context |
| Implementation-time simplicity | `voku/agent-skills/coding-simplicity` | Select/project it for coding, bug fixing, and refactoring; do not copy its rules into session bootstrap |
| PHP/testing/security/performance/type/architecture engineering guidance | `voku/agent-skills` | Select/project the relevant skill; engineering truth stays outside the umbrella package |
| Engineering review lenses | `voku/agent-skills/code-review-*` | Provide exact scope/evidence, select one dominant lens, persist the lens-local result, allow at most one evidence-backed handoff |
| Workflow phases, approval, review gate, learning decision, verify, close | `voku/agent-loop` | Canonical owner |
| Process/evidence blind-spot review | `voku/agent-loop` | Deterministic lifecycle check, separate from engineering review |
| Task assumptions, decisions, checkpoints, validation evidence | `voku/agent-session` | Orchestrate through typed package APIs |
| Bounded symbol/caller/context navigation | `voku/agent-map` | Select real source; generated map remains navigation only |
| Task-scoped L2 context compilation | `voku/agent-recall-compiler` | Compile/select task guidance |
| Findings, proposals, evidence, reviewed learning boundary | `voku/agent-learning` | Route reusable observations through explicit review before durable guidance |
| Skill projection/install | `voku/agent-loop` | Merge explicit local canonical roots, fail duplicate IDs, track one managed projection |
| Subagent representation | `voku/agent-loop` | Render one canonical role into host-native representation |
| Workflow/bootstrap hooks | `voku/agent-loop` | Project workflow/navigation/evidence discipline through explicit host adapters; do not smuggle engineering skills into every session |
| Host capability reporting | `voku/agent-loop` | Report the strongest owned evidence through `HostCapabilityMatrix` / `init doctor` |

## Engineering-skill boundary

`agent-loop-discipline` is intentionally smaller than the engineering skills it can route to.

For example, the Ponytail-derived rules now live in `coding-simplicity`:

```text
understand flow
  -> no change
  -> reuse repository owner
  -> stdlib
  -> native platform
  -> installed dependency
  -> shared root-cause fix
  -> minimum new code
```

That skill also owns the safety and verification floors. SessionStart/SubagentStart do not inject this implementation ladder into unrelated planning, research, review orchestration, or delegation.

Review-time simplicity is a separate concern: `code-review-simplicity` judges an existing diff; `coding-simplicity` guides implementation choices.

## Skill-source contract

`agent-loop` keeps its package workflow-skill root authoritative and may merge additional explicitly supplied **local** skill roots.

- collect/check roots before target mutation;
- missing or unreadable explicit roots fail;
- duplicate skill IDs fail;
- one target manifest tracks the merged projection;
- no remote source is downloaded by `install-assets`;
- the caller owns source provenance and may pin/check out the source before invocation.

Installed release-set CI pins the merged first-party `voku/agent-skills` revision and proves package workflow skills plus `coding-simplicity`/review skills coexist in the projected host roots.

## Projection is not runtime behavior

Status meanings are strict:

- `supported`: executable evidence exercises the claimed `agent-loop` boundary;
- `degraded`: a native adapter is contract-tested, but host runtime/delegation behavior itself has not been observed;
- `unsupported`: `agent-loop` has no adapter for the claimed capability.

| Capability | Codex | Claude | Copilot | Antigravity |
|---|---|---|---|---|
| skill projection | supported | supported | supported | supported |
| subagent projection | supported | supported | supported | supported |
| session bootstrap | degraded | degraded | unsupported | unsupported |
| subagent bootstrap | degraded | degraded | unsupported | unsupported |
| pre-tool guardrail | degraded | degraded | unsupported | unsupported |
| repository hooks | degraded | degraded | unsupported | unsupported |

A vendor feature can prove that a possible mechanism exists; it does not turn an `unsupported` or `degraded` cell green. Change a status only with evidence at the boundary that new status claims.

## Non-goals

- no package/repository per capability noun;
- no universal host schema that erases real client differences;
- no engineering handbook duplicated into workflow/bootstrap guidance;
- no remote marketplace/network dependency in normal runtime;
- no generated-file-presence test presented as delegated runtime proof;
- no automatic durable-memory promotion.

## Dogfood requirement

Changes to these boundaries run through `agent-loop` itself. PR CI executes the real diff through self-shape governance, while installed release-set dogfood projects the exact pinned first-party engineering-skill source into a clean consumer.

For coding-simplicity specifically, dogfood must prove both sides of the boundary:

1. the loadable skill is present with search-order, safety, verification, and no-persona semantics;
2. the always-on discipline/session context does **not** contain the implementation ladder.
