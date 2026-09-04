---
name: coding-simplicity
description: Implementation discipline: minimal implementation ladder, safety floor, adaptive navigation, stop rule, premise checks, and evidence integrity.
---

# Coding Simplicity & Implementation Discipline

Use this skill while implementing, debugging, or refactoring code, before introducing new classes, abstractions, or configuration switches.

## Minimal Implementation Ladder

Stop at the first rung that fully satisfies the verified requirement. Do not climb further to look thorough.

1. **No change needed:** the behavior already exists; explain it with code anchors and stop.
2. **Repository code owns it:** reuse an existing service, helper, factory, validator, or component.
3. **Standard library owns it:** use language built-in functions and native types.
4. **Platform/runtime owns it:** database, web server, runtime environment, or template engine solves it without new application code.
5. **Installed dependency owns it:** an existing package in the project manifest already covers it; do not add a new dependency for a few lines.
6. **One root-cause fix for all callers:** fix the owning function/method once instead of patching each call site.
7. **Only then add minimum new code** in the owning layer.

Before writing a new helper, search for an existing owner. Before patching a call site, verify whether the owning method is the real defect.

## Adaptive Navigation Before Editing

Use the cheapest reliable navigation for the information required:

- **Known files, literals, config, templates, or local tests:** prefer `rg`, `rg --files`, and focused source reads. Do not build an entire index merely to edit a known line.
- **Structural and relational questions:** use map queries (`map query`, `map related`, `map callers`, `map callees`, `map impact`) when you need call graphs, inheritance, cross-file impact, or semantic neighbors.
- **Governed refactoring plans:** before manually editing across files, check `agent-map plan-capabilities` for deterministic contracts (`method-move-plan`, `class-move-plan`, `method-copy-plan`, rename plans, removal plans).

## Safety Floor

Minimal never removes:
- Trust-boundary validation (input validation, prepared SQL statements, output escaping).
- Authentication, authorization, and CSRF protection.
- Audit obligations and security logging.
- Typed exceptions and observability on failure paths.
- The smallest meaningful regression test for a fixed bug.
- Backward compatibility in production mode.

A small patch in the wrong layer is still a defect.

## Stop Rule

After the requirement is satisfied and validated, stop. Do not add adjacent cleanup, speculative configuration, a one-implementation interface, a factory with one product, or a future-only switch. If you notice a real neighbouring problem, record it as a followup task instead of widening the current diff.

Exception: confirmed dead code exposed by the task itself should be deleted in the same change, together with its tests.

## Premise Check

On concrete avoidable complexity, repeated repair, or contradictory observations, check the premise before adding machinery:
1. What was the approved outcome?
2. Which assumption is causing complexity or churn?
3. Does current evidence still support that assumption?
4. Is there a simpler route that preserves the goal, acceptance criteria, scope, and authority?

Outcomes:
- `CONTINUE`: proceed if current evidence still holds.
- `REPLAN`: agent-owned when approved intent is unchanged; delete obsolete machinery.
- `HUMAN_DECISION_REQUIRED`: only for changing product intent, acceptance criteria, public contracts, or irreversible authority.

## Evidence Integrity

- Keep raw command output raw. Do not route commands through lossy output compression proxies that summarize diffs or truncate compiler/test output.
- Quote errors and test output verbatim.
- Generated indexes and maps are navigation leads, not evidence. Always read the real source range before making claims.

## Communication Receipts

Mid-task updates:
```text
RESULT: <verified result, decision, artifact, or blocker>
STATE: <phase> <task-id>
NEXT: <one agent-owned action or exact human gate>
```

Completion report:
```text
RESULT: <what changed and why (files, one line each)>
EVIDENCE: <exact validation commands and exit codes>
OMITTED: <deliberate omissions plus revisit trigger, or none>
```
Never claim "all tests pass" when only targeted checks were run; report exactly which checks were executed.
