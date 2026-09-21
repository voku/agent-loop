# Learning-owned skill promotion dogfood

## Governed identity

- Task: `AGENT-LOOP-550-SKILL-PROMOTION-DOGFOOD-001`
- Contract revision: `3` (approved by `moellekenl`)
- Run: `run:AGENT-LOOP-550-SKILL-PROMOTION-DOGFOOD-001:117d261ee7666158`
- Session: `2026-09-21-agent-loop-550-skill-promotion-dogfood-001-r3-572aba3c`
- Base commit: `a8ba658820daa386f46aa9f9d075d0a80338d6fd`

## Normal consumer projection

The clean consumer used the released package through the normal Composer and
`agent-loop init install-assets --agent=codex` path. No extra skill repository
or manually supplied skill root was used.

- `voku/agent-learning`: `0.18.22`
- package source and dist reference: `7114cd89f8ce3ec6b2f89cca4b56f25bc7b66e1d`
- projected source: `vendor/voku/agent-learning/resources/skills/agent-skill-promotion/SKILL.md`
- projected consumer: `.codex/skills/agent-skill-promotion/SKILL.md`
- source and projection SHA-256: `9084de27f99fb1e936141bc29bd3215d231e4c85d7c1483365451fd881583901`
- normal dry-run result: 24 skills from 4 source roots, including `agent-skill-promotion`
- host status after installation and policy sync: all repository integrations ready,
  `next_action_kind=none`

The projection proves package ownership and installation only. Runtime skill
selection or loaded-token effect was not observable in this host run and is
therefore not claimed.

## Real Finding and bounded promotion path

The case was naturally relevant to the user-supplied skill-context-boundary
observation and direct inspection of the existing package-owned
`agent-loop-discipline` entry point. It was not created solely to exercise the
promotion skill.

- Finding: `finding.2026-09-21.ed98de`
- Finding state: `validated`
- classification: `UPDATE_SKILL`
- pattern: `skill.context_boundary.progressive_disclosure`
- target: existing `agent-loop-discipline` skill owner
- validation case: preserve universal lifecycle authority in `SKILL.md`; route
  conditional navigation, L2, engineering/role routing, evidence, hook-boundary,
  and close detail through directly discoverable flat references without changing
  lifecycle ownership.

The Learning-owned path was:

```text
validated Finding
  -> agent-learning prepare
  -> bounded consolidation result
  -> agent-learning proposal-import
  -> candidate Proposal
```

The imported candidate is:

- Proposal: `proposal.2026-09-21.11d3a8`
- action: `REPLACE`
- Learning decision: `UPDATE_SKILL`
- target: `agent-loop-discipline`
- status: `candidate`
- source Finding: `finding.2026-09-21.ed98de`
- approval: none; it remains human-reviewable

`agent-learning validate` and `proposal-validate` both passed. No proposal was
approved, applied, or used to mutate Loop policy.

## Outcome

**KEEP**, with runtime selection effect withheld. The authoring boundary worked:
the existing owner was selected, duplicate/new-skill creation was avoided,
conditional detail was identified for progressive disclosure, and the candidate
preserved Learning ownership and human approval.

The dogfood also exposed and repaired one integration gap: the Loop projection
test expected four Learning consumer skills after release `0.18.22`, while the
correct package projection contains five. The repair was limited to the approved
test path `tests/RepositorySetupProjectionTest.php` and changed the expectation
from `4` to `5`.

The remaining follow-up is empirical: an independent governed task with host
selection/load telemetry is needed before claiming that the split changes the
runtime context or a downstream decision.

## Validation evidence

The affected projection test passed: 4 tests, 44 assertions.

The Composer CI stages were run separately to make quiet intervals observable:

- `composer validate --strict`: passed
- `composer cs`: passed; 0 of 566 files needed fixing
- `composer test`: passed; 1,341 tests, 9,187 assertions, 1 warning, 1 skipped
- `composer phpstan`: passed; 564/564 files, no errors
- `composer test:project-phpstan-rules`: passed
- `composer context:validate`: passed (`OK`)
- `composer dogfood:discipline`: passed; all 8 checks
- `composer review:slop:install`: passed
- `composer review:slop`: passed; 0 new findings, 105 seconds

The long PHPUnit pauses were active test execution with progress output every
roughly 60 tests. The slop scan emitted no progress output while consuming CPU;
process inspection showed `slop-scan.php` active at approximately 98.5% CPU,
and the completed rerun returned exit 0.

The first exact aggregate `composer ci` attempt exceeded Composer's configured
900-second process timeout even though its individual stages passed. The
approved repair raised `config.process-timeout` to `1800` in `composer.json`.
The exact aggregate rerun then passed with exit 0, after 12:44.278 for
PHPUnit plus the remaining PHPStan/rule/context/dogfood/slop stages.
