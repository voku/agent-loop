# Codex Online handoff: finish PR #280 and publish the Runner contract

This file exists to make a **single-repository, single-PR Codex Online session** productive without requiring access to `voku/agent-loop-runner` or another sibling checkout.

Repository facts at execution time always win over this snapshot. Re-fetch before changing code.

## Mission

Finish `voku/agent-loop` PR #280 (`fix/execution-authority-hardening`) as the production-safe authoritative execution contract that `voku/agent-loop-runner` can consume from a tagged release.

This is upstream owner work only. Do **not** implement Runner process supervision, worktree lifecycle, journals, host adapters, cancellation, scheduling, or provider integration in this repository.

Do not stop at analysis, a review note, or a locally green partial patch. Continue until this PR is either evidence-ready and merged, or blocked by a proven external/human-owned prerequisite.

## Prepared repository state

Before this handoff was written:

- PR #280 was selected as the canonical implementation for issue #277.
- Parallel PR #279 was re-compared and closed as superseded because its distinctive design passed executor workspace paths into `agent-loop`, duplicated Runner workspace hashing/validation, and therefore blurred the execution-plane/privacy boundary.
- PR #280 had no unresolved GitHub review threads or submitted reviews at the last inspection.
- The exact pre-handoff #280 head `cff8ddcae9cf74c90060b6e16b1ce6738eaa8740` had already passed:
  - installed release-set dogfood;
  - deterministic slop review;
  - governed execution-contract dogfood;
  - acceptance-criteria candidate dogfood;
  - prompt-primitives candidate dogfood;
  - installed refactor/removal lifecycle jobs.
- PHP 8.3/8.4/8.5 `composer ci` and self-shape were still running when later preparation commits were added, so **none of those earlier CI results are authority for the final head**.
- `composer.json` now projects `dev-main` as `0.18.x-dev`, because this PR changes the public execution contract and the next release should be treated as a compatibility-significant 0.x minor unless current repository policy proves otherwise.

Do not revert those decisions merely because another old PR still exists in Git history.

## Non-negotiable ownership boundary

Dependency direction:

```text
agent-loop-runner
      |
      v
  agent-loop
```

Never the reverse.

`agent-loop` owns authoritative governance:

- Task / Contract / Contract revision;
- governed Run;
- resolved versioned ExecutionPlan;
- execution profile and role semantics;
- legal stage transitions;
- StageResult acceptance;
- owner evidence;
- validation truth;
- Attention authority;
- review / Learning / verify / close truth.

An external executor may provide **runtime observations**, but never authority.

These must remain non-authoritative:

```text
process exit 0
model says done
model says tests pass
completion JSON exists
arbitrary candidate string
arbitrary artifact reference
arbitrary validation reference
Runner-local log path
Runner-private state
```

`agent-loop` must not learn or parse `.agent-loop-runner/**` paths.

## Candidate authority contract

A read-only stage must preserve the governed candidate exactly.

A mutating stage may change candidate only through the typed owner boundary:

```text
Runner observes actual Run workspace
  -> computes Git-native candidate identity
  -> StageCandidateObservation
  -> agent-loop validates current Task/Run/Contract/plan/stage/attempt/previous-candidate binding
  -> agent-loop verifies git-tree-v1:<exact-base>:<tree-object>
  -> agent-loop verifies the object exists and is exactly a Git tree
  -> agent-loop records owner candidate evidence
  -> StageResult may reference that resulting candidate
```

An existing Git tree by itself is **not** authority. Candidate owner evidence must be current and attempt-bound.

The implementation must reject at least:

- read-only candidate changes;
- wrong-base candidates;
- malformed candidate identities;
- nonexistent Git objects;
- non-tree Git objects such as commits;
- changed candidates without owner candidate evidence;
- candidate evidence replayed from another attempt;
- candidate/evidence from another Run, Contract revision, execution-plan digest, stage, attempt, or candidate binding.

Use real temporary Git repositories for behavior where Git object provenance matters. Fake `111111...` commits are not acceptable proof there.

## Artifact and validation authority

`StageResult` strings never become evidence by formatting alone.

External artifact flow may remain narrow:

```text
StageArtifactObservation
  -> agent-loop verifies exact current execution/candidate binding
  -> agent-loop creates owner artifact evidence reference
  -> StageResult references owner evidence
```

Validation is stricter:

- external executors do not mint validation truth;
- Runner logs are diagnostic only;
- model-provided validation references are not authority;
- deterministic owner validation creates current Loop-owned validation evidence;
- deterministic PASS without current owner validation evidence must fail closed.

Exact accepted StageResult replay must remain idempotent. Reusing the same submission identity with different content must fail closed.

## Attention authority

Required semantics:

```text
agent stage -> NEEDS_CLARIFICATION
  -> Loop creates Attention
  -> external Runner stops
  -> human/owner workflow resolves Attention with actor evidence
  -> authoritative resolution record exists
  -> external projection may resume on a new attempt
```

`ExecutionGateway::resolveAttention()` must not manufacture human authority from an Attention id.

Keep the explicit owner workflow path, currently shaped as:

```bash
vendor/bin/agent-loop workflow attention TASK --resolve ATTENTION_ID --by ACTOR
```

## Required execution work in this session

### 1. Re-ground before editing

Inspect:

```bash
git status --short --branch
git log --oneline --decorate -20
git fetch --all --tags --prune
```

Then inspect the actual PR head, main, issue #277, tags, Composer constraints, CHANGELOG, CI and review state.

Do not assume the SHAs in this file are still current.

### 2. Bootstrap the VM if needed

Use the real environment.

At minimum establish:

```bash
php -v
composer --version
git --version
composer install --no-interaction --prefer-dist
```

Recover normal public `origin` / dependencies when the Codex checkout is intentionally sparse or missing them. Do not replace executable tests with prose.

### 3. Establish exact-head baseline

Run the repository's real gates:

```bash
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer test:project-phpstan-rules
composer context:validate
composer dogfood:discipline
```

Also run configured self-shape / execution-contract / installed-consumer dogfood that is relevant to this PR.

Classify any failure as:

```text
PRE_EXISTING
INTRODUCED
UNKNOWN_ORIGIN
```

Fix introduced failures before proceeding.

### 4. Falsify the authority design

Do not merely confirm happy paths. Attempt to break:

- candidate owner binding;
- stale attempt replay;
- cross-Run / cross-Contract / cross-plan evidence reuse;
- non-tree Git object acceptance;
- arbitrary model artifact reference acceptance;
- arbitrary model validation reference acceptance;
- deterministic PASS without owner validation evidence;
- Attention resolution through the external gateway;
- accepted StageResult idempotent replay;
- changed submission under an already accepted submission id.

If a test exposes a genuine owner defect, fix it in `agent-loop` with the smallest owner-local design.

Do not add Runner workspace management to solve a Loop verification problem.

### 5. Keep public API consumable by Runner

The public boundary should be narrow, immutable and statically analyzable. Preserve or improve the typed contracts around:

- `ExecutionGateway`;
- `StageExecutionBundle`;
- `StageCandidateObservation`;
- `StageArtifactObservation`;
- `StageResult`;
- `ExecutionProjection`;
- owner evidence references;
- Attention projection/resolution observation.

Do not expose internal evidence stores as Runner APIs.

Run PHPStan at project maximum after every public-contract adjustment.

### 6. Dogfood agent-loop on this work

Use the repository's actual front door where practical instead of manually fabricating `.agent-loop/**` state.

The lifecycle should meaningfully exercise:

```text
PLAN
-> APPROVE
-> explicit execution profile when applicable
-> ENTER
-> validation/review evidence
-> Learning
-> verify/close
```

Do not fake lifecycle success just to make the dogfood test green.

### 7. Prepare the release contract

This PR is expected to begin the `0.18.x` development line because it changes/adds public execution APIs used by an external package.

Verify that assumption against repository policy. If still correct:

- keep `dev-main` at `0.18.x-dev`;
- add a factual `0.18.0` CHANGELOG section dated with the actual release date;
- describe candidate observation/evidence authority, artifact/validation authority, Attention hardening and exact validation evidence;
- do not claim CI/release evidence that has not actually passed.

The repository's tag workflow consumes `.release/<version>.json` containing both `version` and immutable `target_sha`, and requires the target commit's own CHANGELOG to already contain that version.

The project normally squash-merges PRs. Therefore **do not pre-commit a release marker with a guessed PR-head SHA**. A squash merge produces a new main commit, so the correct marker target can only be known after merge.

After merge:

1. obtain the exact new `main` SHA;
2. create the release marker for that exact SHA through the normal protected repository path if this session is authorized to do so;
3. verify the tag exists at the exact target;
4. verify the released package is actually resolvable by Composer/Packagist where the project expects it.

If the one-PR Codex environment truly prevents this post-merge repository action, stop only at that proven environment boundary and report the exact main SHA plus the exact release-marker content required for the next tiny release-only session.

### 8. Exact-head GitHub evidence before merge

Do not merge merely because GitHub says `mergeable`.

Require on the final PR head:

- PHP 8.3 green;
- PHP 8.4 green;
- PHP 8.5 green;
- PHPStan/project rules green;
- Composer validation green;
- relevant dogfood green;
- no unresolved review thread with a merge blocker;
- independent blind-spot review finds no owner-boundary blocker.

If a CI job fails, inspect the actual job log. Fix the cause and re-run. Earlier green SHA evidence does not count for a moved head.

### 9. Merge with race protection

When evidence-ready, merge the canonical PR using the repository's normal merge policy and expected-head/race protection when available.

Then re-fetch `main` and prove the expected changes are actually present. Repository evidence outranks any local narrative.

## Explicit non-goals for this PR

Do not add:

- Runner worktree creation/lifecycle;
- process supervision;
- Codex/Claude/OpenCode adapters;
- Runner runtime journal;
- cancellation/PID ownership;
- scheduler/DAG engine;
- automatic model/profile selection;
- tmux/dashboard/remote workers;
- Git push support for Runner;
- transcript-derived workflow truth;
- another memory/session/kanban system.

Those are downstream `agent-loop-runner` concerns after the public contract is released.

## Independent blind-spot pass

After all ordinary tests are green, assume the design is still wrong and explicitly try to falsify:

- semantic-owner confusion;
- model output becoming authority indirectly;
- forged Git candidate identity;
- stale candidate/evidence replay;
- same submission id with different content;
- owner evidence from another attempt;
- Attention id used as human authority;
- public APIs leaking internal persistence paths;
- any new dependency from Loop to Runner;
- CI claims from a stale SHA;
- release metadata targeting the wrong commit.

Add regression tests for any real defect found.

## Completion criterion

This Codex session is successful only when one of these is true:

### DONE

- PR #280 exact final head is green;
- blind-spot review is clean;
- PR #280 is merged;
- issue #277 is closed only if its actual DoD is satisfied;
- the hardened public contract is released from the exact merged commit and installable, **or** the one-PR session has proven that the release marker is the only remaining external/session-bound action and reports its exact content.

### BLOCKED

A genuinely external/human-owned requirement cannot be satisfied from this VM, and every independent authorized task has still been completed.

Do not use `PARTIAL` merely because the work is large.

## Final report format

Return:

```text
STATUS: DONE | BLOCKED

REPOSITORY STATE
- starting SHA
- final PR head SHA
- final main SHA if merged
- working tree clean/dirty

IMPLEMENTATION
- concrete behavior changed

AUTHORITY PROOF
- candidate
- artifact
- validation
- Attention
- idempotency

VALIDATION
- exact commands and results

CI
- exact workflow/job ids and conclusions for final head

DOGFOOD
- exact governed lifecycle evidence

MERGE / RELEASE
- PR merge result
- main SHA
- version/tag
- Composer/Packagist proof

REMAINING BLOCKERS
- only genuine external/session-owned blockers, with evidence

BLIND-SPOT FINDINGS
- defects discovered after the implementation initially appeared ready
```

Work autonomously. Do not ask for normal intermediate approval. Use the real VM, real Git, real Composer, real temporary repositories and real CI evidence.