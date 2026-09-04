---
name: agent-loop-shipping-evidence
description: Prove merged, shipped, or released claims against an exact candidate SHA and exact integrated commit instead of branch names or historical PR state.
---

# Agent Loop Shipping Evidence

Use this skill before claiming that completed work is merged, shipped, or
released. Governed workflow completion is necessary task evidence but is not a
shipping fact.

## Invariant

Freeze the source candidate as a full Git object ID **after any rebase that changes the candidate commit identity**. Then identify the exact
commit that integrated it into the target and run:

```bash
vendor/bin/agent-loop verify \
  --candidate-sha=<full-source-candidate-sha> \
  --integrated-sha=<full-integrated-sha> \
  --target-ref=<target-branch-or-frozen-target-ref> \
  --format=toon
```

The check resolves the target ref once to an exact commit SHA and proves:

- candidate -> integrated by Git ancestry when the frozen candidate remains in the integrated history; or
- candidate tree == integrated tree for an unchanged squash merge;
- integrated -> frozen target by Git ancestry.

A rebase that rewrites the candidate must happen **before** the candidate SHA is frozen. `GitCandidateEvidence` deliberately does not infer patch equivalence or map a pre-rebase commit to its rewritten descendant. If a candidate was frozen too early, freeze the actual post-rebase candidate and validate that exact result instead.

A changed squash tree fails and requires validation of the actual integrated
candidate. A branch name, PR number, `merged=true`, or the statement that a
branch once had a merged PR is never enough.

`--format=toon` is the agent-facing default because this is a read-only
structured projection. Use `--format=json` only when another machine consumer
explicitly requires JSON. The evidence calculation itself is identical.

## Release Claim

For a release claim add the exact tag:

```bash
vendor/bin/agent-loop verify \
  --candidate-sha=<full-source-candidate-sha> \
  --integrated-sha=<full-integrated-sha> \
  --target-ref=main \
  --release-tag=<version> \
  --format=toon
```

The evidence records both the tag object identity and its peeled release commit,
then proves the integrated commit is contained by that release commit.

For cross-package work, a local Composer path overlay is development evidence,
not shipping evidence. When the claim is that ordinary consumers can install the
result, bind the proof to released dependency identities and the clean-consumer
release-set gate.

## Boundary

Do not add another workflow phase. `workflow close` answers whether the governed
task is complete. This check answers a different external question: whether the
exact result being discussed reached the claimed Git target or release.
