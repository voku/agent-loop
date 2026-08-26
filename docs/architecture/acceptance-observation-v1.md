# Acceptance observation mapping v1

An acceptance criterion is approved task intent, not proof. A validation command is an executable observation candidate, not semantic proof by itself.

For the first implementation slice of #312, the Contract owns an explicit mapping from required acceptance criteria to validation commands declared by the same Contract revision.

The mapping answers only:

> Which approved validation observations are intended to cover this required outcome?

It does not assert that the command passed, that the command is a sufficient oracle, or that current implementation evidence exists. Those remain lifecycle / Session / execution-evidence concerns.

## Invariants

- every mapped acceptance criterion must exist in `acceptance_criteria`;
- every mapped validation command must exist in `validation`;
- a criterion may map to more than one validation command;
- duplicate mappings normalize away;
- unmapped criteria remain explicitly uncovered;
- a green unrelated validation command must never be inferred to cover an unmapped criterion;
- superseded Contract revisions retain their own mapping;
- evidence freshness remains bound independently to the current implementation identity.

## Boundary

This is intentionally not a generic gate language and does not add a second validation executor. `agent-loop` owns the approved Contract relationship and whether missing coverage matters to lifecycle completion. Session / execution evidence owns what actually ran against which implementation. Recall may guide review of that evidence but does not own completion authority.
