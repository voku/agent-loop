# Upstream capability matrix

Status: reviewed integration map for the package-owned coding-agent behavior.

This file answers one question per upstream mechanism: **what did `agent-loop`
actually do with it?** Reading a source file is not the same as adapting its
behavior. A repository recheck is not evidence of completeness by itself.

Reviewed source pins:

- Caveman (`JuliusBrussee/caveman`): `14d4f2e21a16b573373ca24698cd6bd3db75bf52`;
- Ponytail (`DietrichGebert/ponytail`): `2ed6c52c9d7e5e56942508591085fd45dea277d3`;
- Attention Control (`aaddrick/attention-control`): `3c8a2a8a38f163aa85ad325812b5ce3ba330ad27`.

Decision vocabulary:

- `ALREADY`: the mechanism has an intentional `agent-loop` equivalent;
- `ADAPT`: this integration adds or tightens the equivalent;
- `DEFER`: useful idea, but the owning typed contract does not exist yet;
- `REJECT`: intentionally outside the `agent-loop` workflow contract.

The adaptation target is not feature parity. The target is a smaller coding-agent
contract with persisted workflow state, exact evidence, reviewed learning, and
objective constraints where they can be enforced.

## Caveman

| Upstream mechanism | Decision | `agent-loop` equivalent / enforcement |
| --- | --- | --- |
| Terse human-facing prose with exact technical strings | `ALREADY` | `agent-loop-discipline` removes filler while preserving normal grammar, negation, paths, symbols, numbers, commands, errors, and security detail. |
| Session-start activation and reinjection after resume/clear/compact | `ALREADY` | Package-owned Codex/Claude hooks inject the canonical discipline. Claude registration covers startup/resume/clear/compact/fork. No hidden mode flag is required. |
| Subagent instruction propagation | `ALREADY` | `SubagentStart` injects the same discipline so delegation does not silently drop global constraints. |
| Cavecrew investigator: read-only locator with path/line output | `ALREADY` | `agent-loop-investigate` / `agent-loop-investigator` add `agent-map` navigation plus real-source verification. |
| Cavecrew builder: hard bounded edit with terminal outcomes | `ADAPT` | Surgical skill/role use a deterministic terminal status vocabulary for applied, scope-expanded, human-gate, ambiguous, and regressed outcomes instead of free-form handoff prose. |
| Cavecrew reviewer: findings-only severity output | `ALREADY` | `agent-loop-code-review` / reviewer keep correctness separate from complexity and require verified source context. |
| Investigator -> builder -> reviewer chaining | `ALREADY` | Discipline routes this chain only for genuinely bounded work; broad work stays in the governed workflow. |
| Compress main-context narration | `ALREADY` | `agent-map`, recall compilation, bounded context, receipts, and narrow subagent output reduce context without rewriting source evidence. |
| Overwrite memory files with model-compressed prose | `REJECT` | Durable evidence and memory are never lossy-compressed in place. Raw evidence stays unchanged; compact projections reference it. |
| Session token/savings statistics | `REJECT` | No savings number is emitted without owned telemetry and a real baseline. Dogfood records observable artifact/runtime metrics instead. |
| Compact commit-message helper | `REJECT` | Commit-writing convenience is not part of workflow governance. Repository conventions or a host-specific writing skill own it. |
| Persistent user-selectable caveman intensity modes | `REJECT` | Workflow invariants are not a persona mode. No hidden flag decides whether correctness/evidence rules apply. |
| Model overrides per subagent | `REJECT` | Model/provider selection remains a host concern. Package-owned roles define behavior, not model routing. |
| Remote installer, marketplace, status line | `REJECT` | `init install-assets` installs reviewed local Composer-package assets only. |

## Ponytail

The current Ponytail benchmark explicitly treats `Follow YAGNI principles, and prefer one-liner solutions` as a comparison arm, not as a proven compact reproduction of the skill. Its own result calls that arm erratic and records one dropped path-traversal guard. The transferable target is therefore the smallest instruction set that preserves the useful behavior and safety, not the fewest English words.

| Upstream mechanism | Decision | First-party equivalent / enforcement |
| --- | --- | --- |
| YAGNI / existing code / stdlib / native / installed dependency / minimum code ladder | `ADAPT` | `voku/agent-skills/coding-simplicity` owns the coding-time search order. `agent-loop` routes to it instead of injecting the ladder into every session. |
| Understand the real flow before choosing the small fix | `ADAPT` | `coding-simplicity` requires task/flow understanding before minimization; `agent-map` remains bounded navigation support, not the semantic owner. |
| Root-cause fix after checking callers | `ADAPT` | `coding-simplicity` owns the root-cause rule; `agent-loop-surgical-edit` keeps caller evidence and bounded-scope orchestration. |
| No speculative abstraction, config, compatibility, dependency or cleanup | `ADAPT` | `coding-simplicity` owns implementation restraint. The surgical role only prevents silent scope expansion. |
| One runnable check for non-trivial logic | `ADAPT` | `coding-simplicity` owns the verification floor and defers to the repository's existing relevant gates. |
| Deliberate simplification with ceiling + observable upgrade trigger | `ADAPT` | `coding-simplicity` requires a known ceiling/revisit trigger; `agent-loop` provides task/session evidence rather than tool-branded product-code comments. |
| Diff-only over-engineering review | `ALREADY` | `agent-loop-simplify-review` is a thin workflow overlay around first-party `code-review-simplicity`. |
| Repository-wide over-engineering audit | `ALREADY` | `agent-loop-simplify-audit` is a bounded navigation/evidence wrapper around `code-review-simplicity`. |
| Cross-repository shortcut/debt ledger | `DEFER` | The concept is useful, but `agent-session` does not yet expose a typed cross-session decision query. `agent-loop` will not grep focused-package internals to fake one. |
| Benchmark/gain scoreboard | `DEFER` | Upstream numbers are not evidence for our adaptation. A first-party benchmark should compare baseline, full Ponytail, the seven-word arm, and progressively shorter complete-rule instructions on safety, completeness, and implementation surface before claiming behavioral equivalence. |
| Session/subagent reinjection of Ponytail coding behavior | `REJECT` | Coding minimization is task-specific engineering guidance. SessionStart/SubagentStart carry workflow/navigation/evidence bootstrap only; load `coding-simplicity` when coding work needs it. |
| Multi-host adapters around one behavior source | `ALREADY` | `agent-loop` can project canonical local first-party skills into supported host roots without copying their semantics into the workflow package. |
| User-selectable persistent intensity/mode tracking | `REJECT` | Engineering semantics are selected by task context, not a hidden persona/intensity flag. |
| Host-specific status line and plugin surfaces | `REJECT` | Not required for deterministic workflow state; `workflow status` and the run manifest are the product state surfaces. |

This boundary is deliberate: a separate loadable coding skill is useful precisely because it can be active for implementation work without making unrelated planning, research, review orchestration, or session bootstrap inherit a coding persona.

## Attention Control

| Upstream mechanism | Decision | `agent-loop` equivalent / enforcement |
| --- | --- | --- |
| Lead with the useful action or fact | `ALREADY` | Progress/completion receipts lead with `RESULT`. |
| Do work the agent owns instead of handing executable steps back | `ALREADY` | Human gates are limited to approval, real risk/irreversible action, and genuinely missing product intent. |
| Restate state so it survives attention/context loss | `ADAPT` | Session/subagent bootstrap adds a bounded workflow-resume hint from unfinished run manifests. It includes only validated task/state identifiers and requires `workflow status` before mutation. |
| Keep uncertainty as a fact; do not fill gaps with plausible specifics | `ADAPT` | Discipline treats unknowns as explicit state. The agent must inspect the owning artifact or name the unresolved fact; it may not fabricate versions, paths, line numbers, results, approvals, or intent. |
| Exact code/command/path/error text remains verbatim | `ALREADY` | Evidence-integrity and communication contracts preserve exact technical strings and raw artifacts. |
| Flat error reporting: location, cause, fix | `ALREADY` | Review and validation receipts require exact locations/commands and decisive failures. |
| Safety/irreversible actions override brevity | `ALREADY` | Safety floor and named human gates override concise output. |
| Stop repeating failed patches and challenge the underlying assumption | `ADAPT` | Repeated equivalent failures return to evidence gathering / PLAN rather than stacking another speculative patch. |
| Reader-specific ADHD assumptions | `REJECT` | A coding workflow must not assume a reader diagnosis or identity. |
| Fixed 20/25-word sentence limits, five-item list cap, restricted English vocabulary | `REJECT` | These are presentation preferences, not correctness or workflow invariants. Normal clear prose remains allowed. |
| Always-on output-style mode and stop command | `REJECT` | Agent-loop behavior is activated by the governed workflow/repository contract, not a conversational style mode. |

## Hard-boundary rule

An upstream idea becomes a hard `agent-loop` constraint only when the runtime can
observe the condition objectively without inventing state. Examples:

- unbounded generated-map dumps can be denied by `PreToolUse`;
- workflow approval/validation/review/learning readiness is enforced by persisted
  package state and close gates;
- role/skill contract drift is checked by package tests and discipline dogfood;
- a resume hook may expose a bounded manifest hint, but it cannot promote that
  derived projection into workflow authority.

Subjective style goals stay guidance. Security and correctness never depend on a
host dispatching a hook.

## Review rule for future upstream rechecks

A future recheck updates this matrix row by row. Do not write "nothing relevant
changed upstream" merely because the pinned commit moved little. First compare
all mechanisms that are still `DEFER`, all previously `REJECT` decisions whose
reason may have changed, and every `ALREADY`/`ADAPT` row against the current
first-party implementation and semantic owner.
