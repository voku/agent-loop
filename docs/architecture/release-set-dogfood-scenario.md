# Installed release-set dogfood scenario

Status: executable scenario specification for
[agent-loop#20](https://github.com/voku/agent-loop/issues/20)

## Goal

Prove that one concrete installed `agent-*` package set behaves as a coherent
product in a clean consumer repository.

The scenario is not a demo. It is a release gate and architecture-discovery
fixture. A failure is useful evidence; replacing the installed packages with
sibling checkouts until it passes is not.

## Fixture repository

Create a temporary Git repository with this minimum shape:

```text
composer.json
src/
  RetryPolicy.php
  RequestService.php
  InvoiceService.php
tests/
  RetryPolicyTest.php
  RequestServiceTest.php
todo/cards/
  DEMO-1.md
infra/doc/agent-learning/
  config.json
  findings/
  proposals/
  history/
```

The fixture must contain enough real PHP structure to test:

- an exact method target;
- a direct caller and test;
- one conceptual behavior described with different words;
- one short identifier that is meaningful structurally;
- one German natural-language question;
- one query that the repository cannot answer.

Use repository-neutral names and data. Do not copy private downstream code,
identifiers, screenshots, paths or business terminology.

## Installation

1. Create a fresh Composer project.
2. Install the supported release set from the recorded baseline.
3. For a candidate test, replace only the intended package source unless the
   scenario explicitly records a coordinated candidate set.
4. Record the resolved lock-file versions, source types and references.
5. Assert that binaries load through the consumer project's autoloader.
6. Assert that no package-local nested `vendor/` path supplied loaded classes.

## Scenario IDs

Every step has a stable ID so failures can be compared across runs.

### `install.resolve`

Verify Composer resolves and installs the full package set.

Evidence:

- `composer.lock` package records;
- `composer show --format=json` summary;
- install exit code;
- source/dist/path references.

### `cli.namespaces`

Run help for:

```text
agent-loop
agent-loop board
agent-loop session
agent-loop map
agent-loop recall
agent-loop workflow
agent-loop edit
agent-loop review
agent-loop learn
agent-loop init
```

A command that exists in managed guidance but not in the installed runtime is a
compatibility failure, not a documentation footnote.

### `discover.exact`

Build/prepare the canonical map and ask an exact target or identifier question.

Expected behavior:

- canonical symbol identity is returned;
- source range/hash are current;
- structural authority is explicit;
- no inferred hit outranks or replaces the exact fact.

### `discover.behavior.en`

Ask an English behavior question whose wording differs from the symbol names.

Expected behavior:

- useful candidates are returned or a visible no-answer state is produced;
- routing strategy and infrastructure status are distinguishable;
- inferred candidates remain labelled inferred.

### `discover.behavior.de`

Ask a German question containing normal noun capitalization and non-ASCII
characters.

Expected behavior:

- Unicode tokens are not truncated;
- code-domain nouns such as `Methode` are not treated as user-named symbols;
- an unavailable/no-answer result is distinct from malformed structural input.

### `discover.no-answer`

Ask about a concept absent from the fixture.

Expected behavior:

- the result does not fabricate a confident target;
- no-answer is distinct from stale, missing or unavailable infrastructure;
- semantic nearest-neighbor behavior does not make “no answer” impossible.

### `workflow.ephemeral`

Create an ephemeral experiment, inspect status and close/drop it.

Expected behavior:

- it is visibly an experiment;
- repository-wide governed verification ignores it;
- it does not create an approval obligation or durable learning requirement;
- cleanup is explicit.

### `workflow.governed.plan`

Plan `DEMO-1` with:

- explicit goal;
- bounded file scope;
- validation command;
- behavior anchor;
- actor;
- non-ephemeral session.

Expected behavior:

- one session is created;
- candidate brief revision 1 exists;
- task/card/session identities can be inspected.

### `workflow.governed.approve`

Approve the exact current brief revision and prepare recall.

Expected behavior:

- approval is bound to the revision;
- recall compilation records map/search status and source/provider digests;
- public verification plan and private key remain separate;
- inferred navigation does not widen approved scope.

### `workflow.governed.execute`

Perform one bounded deterministic fixture change.

The first fixture should prefer a mechanical/exact replacement path so the gate
proves orchestration rather than model quality.

Expected behavior:

- execution bundle records request, prompt, execution and agent-result evidence;
- source changes are observed rather than asserted by the result file;
- no remote model/API is required.

### `workflow.governed.verify`

Verify the concrete edit bundle and the task-level cross-package state.

Expected behavior:

- plan/key/result/execution bindings match;
- required objective gates pass or fail explicitly;
- verification writes one bound result;
- a missing required gate cannot be reported as passed.

### `workflow.governed.review`

Generate the blind-spot review and record the required review checkpoint.

Expected behavior:

- review artifacts are under the current documented path;
- the first missing-checkpoint state explains the next action;
- re-running after the checkpoint reaches the expected state.

### `workflow.governed.learn`

Record:

- an explicit outcome for every selected guidance item;
- one session learning decision;
- either `no_durable_learning` or one small evidence-backed finding.

Expected behavior:

- no selected guidance and missing outcomes remain distinct cases;
- outcome and learning records retain compilation/task lineage;
- no durable proposal is approved automatically.

### `workflow.governed.close`

Close the task.

Expected behavior:

- every required gate is checked;
- joined status ends in a complete state;
- the run can be traced through all owning artifacts;
- board claim/status is released or moved according to the fixture policy.

### `recovery.brief-revised`

Revise an approved brief before execution.

Expected behavior:

- prior approval becomes stale/superseded;
- old validation evidence is not reassigned;
- status identifies the new approval action.

### `recovery.search-stale`

Make the search index disagree with the canonical map snapshot.

Expected behavior:

- stale search state is refused;
- canonical map/source facts remain distinguishable from unavailable search;
- the owning-package recovery path restores readiness;
- no stale candidate enters the briefing.

### `recovery.guidance-mismatch`

Once guidance capability metadata exists, install or create one managed asset
whose requirements exceed the runtime capability.

Expected behavior:

- status reports incompatible guidance;
- dry-run synchronization explains the repair;
- unmanaged project guidance is not overwritten.

## Machine-readable report

The scenario writes one canonical report:

```json
{
  "schema_version": "1.0",
  "release_set": {},
  "platform": {},
  "scenarios": [
    {
      "id": "discover.exact",
      "status": "passed",
      "commands": [],
      "exit_codes": [],
      "artifact_references": [],
      "failure_classification": null
    }
  ],
  "friction": [],
  "result": "passed"
}
```

Each failure classification is one of:

```text
regression
contract_gap
fixture_error
documentation_drift
downstream_configuration
deferred_with_reason
```

The report may include durations as explicitly volatile fields. Equivalent
functional inputs must otherwise produce stable scenario ordering, identifiers
and classifications.

## Release-gate rule

The gate passes only when:

- installation and autoload checks pass;
- exact, English, German and no-answer discovery semantics pass;
- ephemeral work remains outside governance;
- the governed task reaches close;
- stale-state recovery succeeds;
- every detected friction item has an owner/classification;
- no private downstream information appears in fixture or report.

A passing package-local test suite cannot override a failed installed-release
scenario. That would be grading the parts while ignoring that the machine does
not start.
