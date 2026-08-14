# Tracker reconciliation — 2026-08-14

Reconciled only after the replay evidence was green, and only from commands run
against the current tree. A closed issue is not runtime evidence and an open
issue is not proof that something is missing.

## #18 — pre-1.0 semantic reset

### Dogfood invariant: proven

The roadmap's invariant is "prove the whole loop in one clean installed
consumer". `tools/release-set-dogfood.php` does exactly that and reports
`result: passed` with `friction: []` across all thirteen scenarios, from
`install.resolve` through `workflow.close` and `workflow.prune-replay`.

Item by item:

| Invariant | Evidence |
| --- | --- |
| Task → Contract → exact approval → governed Run with explicit `run_id` → Recall → observed validation → verification → Learning close-out → close | Release-set dogfood, 13/13 scenarios in a clean Composer consumer |
| malformed MEMORY cannot report a false clean review | `memory review` and `memory validate` both exit 1 on a malformed table (P0a #44) |
| contradictory validation status/exit cannot pass close | `session validation record --status passed --exit-code 3` is refused at record time: "Passing validation evidence requires exit code 0" (P0a agent-session#3) |
| approved Contract survives Session pruning | Run manifest after `session prune`: contract `approved`, approval `current`, both on the same `sha256` |
| durable verification/learning facts survive Session pruning | verification `passed` with `run_id`, `contract_revision`, `source_session_id`; learning `decided` with `run_id` and cited `finding_ids`; session reference reports `missing` |
| provenance never masquerades as correctness | every manifest reference carries `observation_mode`, and `ArchitectureRules::EvidenceIsNotAuthority` names the check |

### P0c ownership reset: done and now evidence-backed

`run_id` belongs to Run preparation, Session is disposable, and the Learning
close-out survives pruning. The Learning-root split-brain that #96 recorded is
gone: project configuration selects the root once, the Run binds it, and no
workflow command accepts `--learning-root`.

### What #18 still legitimately owns

- The durable-guidance handoff (`agent-learning#11`): four candidate proposals
  now sit in `.agent-loop/learning/proposals/candidate/` awaiting a named human
  approver, which is the first time this repository has had a real proposal to
  hand off. Whether an approved proposal and its canonical home can both act as
  active guidance is now testable here, and was not before.
- Proposal semantic identity versus physical target location.

Everything else in the P0/P1 list has evidence above. #18 should be reduced to
the guidance-handoff question rather than continuing as the parent of unrelated
future work.

## #34 — canonical agent capabilities and host asset projection

The capability truth exists and is deliberately narrow. `HostCapabilityMatrix`
reports per-host `skill-projection`, `subagent-projection`,
`session-bootstrap`, `subagent-bootstrap`, `pre-tool-guardrail` and
`repository-hooks` as `supported`/`degraded`/`unsupported`, `init doctor`
renders it for codex, claude, copilot and antigravity, and
`finding.2026-08-08.004` records why the model was narrowed: a green run proved
projection, not host discovery or runtime execution.

This is the dependency #21 was waiting on, and it is satisfied.

## #21 — guidance/runtime capability drift detection

Still genuinely open, and now unblocked. Nothing in the current tree detects
drift between the capability matrix and what a host actually does at runtime;
the matrix is a declaration, and no runtime probe compares against it.

Correct ordering confirmed: capability truth first (#34), drift detection second
(#21) — not the reverse.

## Out of scope, deliberately

Repository-health and Renovate work stays a separate lane. It shares PHP and
YAML with the lifecycle work and nothing else, and folding it into the agent
lifecycle would recreate the umbrella problem this reconciliation exists to
close.
