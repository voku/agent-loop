# Upstream capability matrix

Status: reviewed integration map for the package-owned coding-agent behavior.

This file answers one question per upstream mechanism: **what did `agent-loop`
actually do with it?** Reading a source file is not the same as adapting its
behavior. A repository recheck is not evidence of completeness by itself.

Reviewed source pins:

- Caveman (`JuliusBrussee/caveman`): `14d4f2e21a16b573373ca24698cd6bd3db75bf52`;
- Ponytail (`DietrichGebert/ponytail`): `c982cd411abb53323c4baa1baa3c2f020b8d0b08`;
- Attention Control (`aaddrick/attention-control`): `3c8a2a8a38f163aa85ad325812b5ce3ba330ad27`;
- mnapoli Skills (`mnapoli/skills`): `f44250e55fff8e69447a1faa0093fb6298f070ae`.

Bounded history recheck on 2026-10-19 (not a replacement full-source audit):

- Caveman current head observed: `bad48a0e1df55e05e3f52249afa35ecc1909ee76`;
- Ponytail current head observed: `09c434ab73ca43761c5d8b0c5d341926c2fba710`;
- Attention Control is unchanged at the reviewed pin;
- Everything Claude Code (ECC) current head observed: `76aaa77df6adc5b889e689007bf99b9a4f74af34`;
- mnapoli Skills current head observed: `036f32c6ce2b4754c73f8c9b39cac5c46ef2f8b5` (two post-pin commits, README/art only).

This bounded recheck inspected mechanisms most likely to change existing
`ALREADY`/`ADAPT`/`DEFER`/`REJECT` decisions. It does not claim exhaustive
feature parity across the moved repositories. The only new first-party product
gap identified is version/freshness-bound real-host runtime evidence; #805 owns
that question.

Decision vocabulary:

- `ALREADY`: the mechanism has an intentional `agent-loop` equivalent;
- `ADAPT`: this integration adds or tightens the equivalent;
- `DEFER`: useful idea, but the owning typed contract does not exist yet;
- `REJECT`: intentionally outside the `agent-loop` workflow contract.

The adaptation target is not feature parity. The target is a smaller coding-agent
contract with persisted workflow state, exact evidence, reviewed learning, and
objective constraints where they can be enforced.

## mnapoli/skills

The comparison target is interaction ergonomics, not feature parity. The reviewed
repository packages several user-facing skills as direct commands; first-party
adaptation keeps the useful behavior while preserving Loop and agent-skills owner
boundaries.

| Upstream mechanism | Decision | First-party equivalent / enforcement |
| --- | --- | --- |
| `implement`: one obvious end-to-end implementation action | `ADAPT` | `agent-loop-workflow` is now the explicit implementation front door. It routes through `enter`, the current `next_action`, host work, and `finish`; a second `implement` skill is rejected because it would copy lifecycle policy. |
| `interview`: inspect repository context, clarify unresolved requirements, stop before coding | `ADAPT` | `voku/agent-skills/requirements-interview` owns the portable clarification behavior. It is tool-neutral and returns an implementation-ready contract without owning Loop lifecycle or mutation authority. |
| `address-pr-review`: read current feedback/CI, repair valid findings, reply and resolve | `ADAPT` | `voku/agent-skills/review-feedback-repair` owns the portable classify/repair/revalidate discipline. Provider-specific thread IDs, helper scripts and GitHub CLI/API contracts remain host/provider mechanics rather than Loop semantics. |
| `unslop`: rewrite prose to remove AI-sounding style patterns | `REJECT` | Useful as an optional external writing skill, but not a Loop lifecycle or core engineering-guidance requirement. `code-slop` / `slop-scan` remain code-maintainability mechanisms and are intentionally not claimed as equivalents. |
| `tell-grokbot`: send conversation context to a provider-specific agent webhook | `REJECT` | External agent messaging, webhook credentials and provider routines are integration concerns, not governed task authority. Loop does not acquire a second agent-to-agent transport merely for convenience. |

The installed release-set proof pins `voku/agent-skills` at
`c9e3b2966dc462c3ae9e9758f8bd39e0e85e7bf8` and proves that both adapted portable interaction skills are
projected into the clean consumer host roots. Projection remains installation
evidence rather than runtime-consumption evidence by itself.

Separately, PR #727 observes `host_runtime_skill_consumption` for the
package-owned `agent-loop-workflow` skill under Codex 0.157.0: a provider-free
baseline request contains its unique skill-body marker zero times, while an
explicit `$agent-loop-workflow` request contains it exactly once. That bounded
proof does not establish implicit semantic routing, consumption of
`requirements-interview` / `review-feedback-repair`, arbitrary skill
invocation, or behavior on any other host.

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
| Compact commit-message helper | `ADAPT` | `voku/agent-skills/git-workflow` owns portable Conventional Commit guidance, including a short subject preference, rationale-only bodies, and no staging or commit side effects. |
| Persistent user-selectable caveman intensity modes | `REJECT` | Workflow invariants are not a persona mode. No hidden flag decides whether correctness/evidence rules apply. |
| Model overrides per subagent | `REJECT` | Model/provider selection remains a host concern. Package-owned roles define behavior, not model routing. |
| Remote installer, marketplace, status line | `REJECT` | `init install-assets` installs reviewed local Composer-package assets only. |
| Version/freshness-bound real-host compatibility evidence | `DEFER` | Caveman now binds tested host versions to dated, method-specific real probes and treats newer versions as drift rather than silently inheriting old proof. agent-loop already separates projection from runtime evidence; #805 owns the smallest typed version/freshness extension rather than importing Caveman's registry/proxy stack. |

The 2026-10-19 bounded Caveman recheck also reinforces existing evidence rules:
its accounting layer distinguishes measured, inferred, provider-reported,
observed and verified values instead of silently changing basis, and its Pi
runtime warning was corrected from a definite claim to "may be off" when one
empty reply could merely be a timeout (`518a8242`). These are `ALREADY` in
agent-loop's evidence/uncertainty discipline and justify no new subsystem.

## Ponytail

The current Ponytail benchmark explicitly treats `Follow YAGNI principles, and prefer one-liner solutions` as a comparison arm, not as a proven compact reproduction of the skill. Its own result calls that arm erratic and records one dropped path-traversal guard. The transferable target is therefore the smallest instruction set that preserves the useful behavior and safety, not the fewest English words.

Current upstream recheck at `c982cd411abb53323c4baa1baa3c2f020b8d0b08` adds five useful evidence lessons without creating new first-party guidance owners:

- Ponytail's Hermes adapter stopped reinjecting the same persisted ruleset on every turn (`9410bcbc`). In `agent-loop`, exact-once runtime consumption belongs to the host-runtime proofs in #618 (Codex), #623 (Claude Code), and #611 (Cursor), while context assembly/injection remains owned by `src/AgentGuidance/AgentDisciplineHook.php` and the canonical discipline body remains owned by `resources/skills/agent-loop-discipline/SKILL.md`.
- Its agentic benchmark fixed two contamination paths: a self-test that lost `PONYTAIL_PLUGIN_DIR`, and benchmark cells that accidentally inherited the repository `AGENTS.md` / user memory (`e9d4a7ee`). Our corresponding evidence owner is the disposable #663/#675 crossover harness; these checks are experiment provenance, not product/session guidance.
- Its review/audit skills added repository-reuse verification before accepting replacement findings (`446e4ad4`). The first-party semantic owner is `voku/agent-skills` `code-review-simplicity` (PR voku/agent-skills#67), not `agent-loop-discipline`.
- Its audit now requires whole-tree caller evidence before a delete finding (`6f7a570e`). `agent-loop` keeps caller/source evidence in the review skill boundary and the simplify-audit wrapper; no new lifecycle state is justified.
- Ponytail now requires benchmark evidence for ruleset changes (`b432558e`). We do not adopt that as a blanket package rule: always-on guidance changes need behavior evidence proportionate to their runtime reach, as #663 does, while ordinary adapter/docs fixes remain under their existing deterministic tests.

A later bounded history recheck at current head `09c434ab73ca43761c5d8b0c5d341926c2fba710`
strengthens rather than reverses those decisions. Ponytail fixed persisted-mode
state across compaction (`70d0065e`), project/session collisions and session
switches, bounded slow hook startup, and SessionStart map input that could include
repository-controlled text or symlinks (`5c5ad8a0`). Its benchmark completeness
judge was also corrected to score what the agent actually delivered
(`88cedebb`). These repairs reinforce the existing `REJECT` for persistent
persona/intensity state and the existing experiment-provenance boundary; they do
not justify importing Ponytail mode state into Loop.


| Upstream mechanism | Decision | First-party equivalent / enforcement |
| --- | --- | --- |
| YAGNI / existing code / stdlib / native / installed dependency / minimum code ladder | `ADAPT` | `voku/agent-skills/coding-simplicity` owns the coding-time search order. `agent-loop` routes to it instead of injecting the ladder into every session. |
| Review-time replacement verification uses repository reuse -> stdlib -> native -> installed dependency | `ADAPT` | `voku/agent-skills/code-review-simplicity` owns the check; agent-loop pins `c9e3b2966dc462c3ae9e9758f8bd39e0e85e7bf8` and the installed release-set proof verifies that reviewed skill catalog is projected into the consumer host roots. |
| Exact-once host consumption of persisted context | `DEFER` | Repository projection is already owned; real consumption/reinjection semantics must be proven per host in #618, #623 and #611 before any runtime claim changes. |
| Benchmark-arm isolation from repository instructions, user memory and mutable plugin selection | `ADAPT` | #663/#675 owns this only as experiment provenance: frozen inputs, authority-state observation and dependency-graph identity. It is not session guidance or a product subsystem. |
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

## Everything Claude Code (ECC)

The original reviewed source pin is `c9148d0bb239ed01a95724a5928b98cdf9c30658`.
A bounded 2026-10-19 history recheck inspected current head
`76aaa77df6adc5b889e689007bf99b9a4f74af34` only for mechanisms relevant to
existing Loop boundaries; it is not a full ECC feature audit.

| Upstream mechanism | Decision | `agent-loop` equivalent / enforcement |
| --- | --- | --- |
| Canonical read-only role intent projected to host-native representation | `ADAPT` | `SubagentDefinition` keeps portable mutation intent and host renderers project only capabilities the host can represent. Projection remains distinct from observed runtime enforcement. |
| Context-profile proposal kept separate from installation, runtime authority, tool permissions and native certification | `ALREADY` | Loop/Recall/skills already separate bounded context selection from lifecycle and mutation authority. Repository projection or a generated context artifact is never promoted into runtime-consumption proof. |
| Reject a host adaptation before writes when a source deny-all tool policy cannot be represented safely | `ALREADY` | Host capability/projector code is allowed to report unsupported/degraded rather than broadening authority. The same fail-closed principle is used by Runner's Contract-bound validation permissions when an exact permission cannot be represented. |
| Credit only evidence/searches that could actually have observed the target | `ALREADY` | Evidence eligibility is tied to owned source/Run/Contract identities; Loop does not award generic "searched" credit merely because a command ran. ECC's GateGuard implementation remains product-specific. |
| Validate execution/failure records before durable storage | `ALREADY` | Session/Learning/Run records are schema-validated before they can become governed evidence; malformed model prose is not durable truth. |
| Transactional work-item claims that preserve the first owner and make same-owner retries idempotent | `REJECT` | Useful state-store behavior, but work-item ownership belongs to `agent-kanban`/task owners rather than Loop's lifecycle contract. Do not import ECC's state store into the umbrella package. |
| Composite harness-audit score and harness optimizer | `REJECT` | Deterministic checks and bounded contrastive experiments are useful; one aggregate harness score is not correctness authority and does not justify automatic optimization. |
| Frozen evaluation inputs and cleanup limited to state actually owned by the trial | `ADAPT` | Existing dogfood/benchmark work freezes task inputs and authority state and does not delete or rewrite unrelated host/session state. ECC's `19a1b62f` independently reinforces that experiment-ownership boundary. |

The recheck found three especially useful negative lessons. ECC's Qwen adapter
rejects an explicitly empty/null tool list before installation because the native
host would interpret it as inherited/broader authority (`fa26ba06`); GateGuard
stopped crediting searches that could not have seen the target (`64d8971a`); and
skill execution now validates failure reasons before writing the record
(`3ac34cfc`). All three reinforce the first-party rule that a representation or
observation must not be promoted into stronger authority/evidence than its owner
can actually prove.

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
