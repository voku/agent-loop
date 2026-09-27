# GH-640 — real Learning reflection dogfood on GH-618

Date: 2026-09-27

## Purpose

Close the remaining proof gap from #640 with a real discovery-bearing governed task.
The selected task is the still-open Codex runtime revalidation from #618. No
synthetic Finding fixture is used.

## Discovery task

The run revalidated the current agent-loop Codex read-only subagent projection
against the exact Codex release installed by the existing runtime proof:

- runtime: `codex-cli 0.157.0`
- upstream tag: `rust-v0.157.0`
- projected read-only Codex roles observed: 4
- `multi_agent`: stable and enabled
- exact upstream `role_tests.rs` proves
  `apply_role_preserves_parent_sandbox_permissions()`
- the same upstream test excludes `sandbox_mode` from role-controlled
  authority
- exact upstream `child_config.rs` reapplies the parent permission-profile
  snapshot to the child

Source digests captured by the run:

- `role_tests.rs`:
  `sha256:3496e47a57e43d9f647112f277fb3b6c3810e8268cd8477dc944c224f8eb6f3c`
- `child_config.rs`:
  `sha256:02f3d7790445ea65f8b4a43c774d88eb14a27597ac540c378b94bf3131aaa975`

The reusable lesson is version-bound: for Codex 0.157.0, a projected
`sandbox_mode = "read-only"` role is projection intent, not evidence that the
child has been runtime-downgraded from a more permissive parent. Child runtime
authority follows the parent permission profile.

This does **not** downgrade agent-loop's repository-side projection capability
claim. #618 already defines that capability as projection evidence. It does
mean that the remaining runtime criterion must not treat the role key itself as
proof of child read-only enforcement.

## Machine-facing Learning path

Governed task: `GH-618-640-1`

The actual `finish --format=json` response reached:

- `complete=false`
- `manifest.references.verification.gate=learning_decision`
- `manifest.references.verification.learning_disposition.evaluate_reusable_learning=true`
- `next_action_kind=command_template`
- typed invocation:
  `agent-loop finish GH-618-640-1 --learning <no_durable_learning|findings_recorded|follow_up_required> --by <actor> [--finding <finding-id> ...] [--follow-up-ref <follow-up-ref>]`

The discovery evidence then satisfied an explicit machine predicate before any
Finding was allowed to be created.

Learning owner result:

- Finding: `finding.2026-09-27.b065eb`
- initial state: `candidate`, `validation_status=unverified`
- reviewed state: `validated`, `validation_status=validated`
- confidence: `high`
- validated conclusion: Codex 0.157.0 role `sandbox_mode` does not replace the
  parent permission profile

The final persisted Run Learning decision is:

- `decision=findings_recorded`
- `finding_ids=["finding.2026-09-27.b065eb"]`
- `reason=null`
- Contract revision: 1
- validation and review evidence hashes are bound into the decision

The final `finish --format=json` response is:

- `complete=true`
- `manifest.references.verification.state=passed`
- `manifest.references.learning.state=decided`
- `manifest.references.learning.decision=findings_recorded`
- `next_action=none`
- `next_action_kind=none`

This proves the post-#639 runtime can consume the Learning gate through JSON and
typed next-action data and can reach an evidence-backed Finding plus
`findings_recorded` without relying on human-only stdout or a synthetic
Finding test.

## Evidence

GitHub Actions:

- workflow run: `36339681611`
- job: `108677189079`
- artifact: `10937823531`
- artifact digest:
  `sha256:335080baa193f1fb05e7916369a98a74c80dfd0afbba2d881cad9f9b60d1c92c`
- proof commit: `dbbb897cf1ee9754a3fe8896db5207d9cfa32bdd`

The artifact retains the exact gate JSON, Finding intake/validation JSON,
validated Finding, final close JSON, persisted Run Learning decision, Codex
feature output, exact upstream source files, and the discovery evidence JSON.

An earlier attempt reached the same `learning_decision` gate but stopped
before Finding creation because the proof consumer expected the optional typed
token to be exactly `--finding`; the actual typed template correctly emits
`[--finding`. Only that consumer assertion was corrected. No product-kernel
change was made to force the Learning path.
