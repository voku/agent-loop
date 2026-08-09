# Installed release-set dogfood gate

Tracking: [agent-loop#20](https://github.com/voku/agent-loop/issues/20) and the current coordinated release issue.

The release-set gate proves the `agent-*` packages as a clean Composer consumer
sees them. Package-local tests remain necessary, but they cannot detect an
installed package loading a sibling checkout, a nested `vendor/` tree, or a
runtime/guidance combination that only fails after the packages are composed.
Humanity has already tried “all the individual pieces looked green” as a system
integration strategy. The results were educational.

## Two gates, two questions

The 0.14 release process deliberately separates candidate integration from
published compatibility.

### Coordinated candidate gates

Before the owning package tags exist, CI checks out exact reviewed commits for
`agent-learning`, `agent-session`, `agent-recall-compiler`, and the first-party
`agent-skills` catalog. Composer path repositories give the three Composer
packages candidate versions inside the release line being prepared.

This proves that the new APIs and artifacts compose before publication. It is
used by:

- PHPUnit + PHPStan/Composer CI on the supported PHP matrix;
- the governed execution-contract dogfood;
- `Agent-loop shapes itself`.

Candidate repositories are pinned to exact commit SHAs. Floating `main` refs are
not acceptable evidence for a release candidate.

### Installed published release-set gate

The `release-set-dogfood` job answers the different question: **can a clean
consumer install the normal package constraints and complete the lifecycle using
published packages?**

It does not replace missing releases with path/VCS fallbacks. If `composer
update` fails because an intended release line does not exist yet, the gate stays
red. That is a publication blocker, not a reason to relax the package contract.

## Run it

```bash
php tools/release-set-dogfood.php --keep
```

Explicit paths are useful in CI or while investigating a failure:

```bash
php tools/release-set-dogfood.php \
  --workspace=/tmp/agent-loop-release-set \
  --report="$PWD/build/release-set-report.json" \
  --keep
```

The runner itself has no Composer dependencies. It stages only the candidate
`agent-loop` package metadata, `src/`, and `bin/`; the candidate's development
`vendor/` directory is never copied into the consumer fixture.

## Installed topology

```text
clean temporary workspace
├── candidate-agent-loop/
│   ├── composer.json
│   ├── bin/
│   └── src/
├── consumer/
│   ├── composer.json
│   ├── composer.lock
│   ├── vendor/
│   ├── fixture source/tests
│   └── generated workflow artifacts
└── artifacts/
    └── logs/
```

The consumer requires `voku/agent-loop:dev-main` through a non-symlinked path
repository. Every focused package is resolved through that candidate's **real
Composer constraints**. The report records the exact resolved versions and
references.

For the 0.14 candidate those constraints require the new release lines for
`agent-learning`, `agent-session`, and `agent-recall-compiler`. A report that
fails during Composer resolution and therefore contains no resolved release set
means the publication gate has not been reached yet; no lifecycle scenario may be
claimed as tested in that run.

## Current scenarios

The clean-consumer gate covers:

1. clean Composer resolution and resolved-package inventory;
2. class loading through the consumer's `vendor/` tree;
3. every public `agent-loop` namespace help path;
4. canonical map and derived search-index preparation;
5. exact target discovery;
6. English behavior search;
7. German behavior search with the original UTF-8 argument preserved;
8. a structural no-answer query that must not return a known fixture symbol;
9. idempotent scaffold creation;
10. an ephemeral experiment excluded from repository-wide verification;
11. a governed task through plan and approval;
12. deterministic fixture-owned source mutation;
13. map/search refresh and recall recompilation;
14. declared validation evidence;
15. blind-spot review and reviewed checkpoint;
16. an explicit `no_durable_learning` decision;
17. stale-manifest detection and explicit repair;
18. cross-package verification and successful workflow close.

The separate execution-contract dogfood owns the L2-specific negative/positive
mutation proof: it creates approved L2 policy, proves mutation is rejected while
the bound L1 is missing, persists the exact five-section contract, and proves the
same bounded mutation then succeeds. Keeping that focused proof separate makes a
failed published-package install distinguishable from an execution-contract
regression.

The fixture is deliberately repository-neutral. It contains a small retry policy,
one caller, one unrelated service, and no private downstream names or business
rules.

## Report contract

The runner writes canonical JSON containing:

- report schema version;
- resolved package set and source type;
- PHP, Composer and platform diagnostics;
- ordered scenario IDs;
- exact command argument arrays and exit codes;
- stdout/stderr hashes and retained log paths;
- bounded artifact paths and hashes;
- classified friction;
- one overall result.

Stable ordering and field names are part of the contract. These values are
evidence rather than byte-stable identities and may vary between equivalent
runs:

- PHP, Composer and operating-system versions;
- installed package source references when the candidate commit changes;
- command stdout/stderr hashes when tools include environment paths;
- SQLite file hashes;
- Git-generated commit identities inside the disposable consumer.

Consumers comparing reports must compare scenario/result semantics and the
resolved release set before treating volatile evidence fields as drift.

## Failure policy

The gate stops after the first failed dependent scenario, writes the report, and
retains scenario logs. It does not retry with:

- sibling repository autoloaders;
- a nested package `vendor/` tree;
- symlinked candidate source;
- relaxed package constraints;
- unpublished package commits smuggled in as a published-set fallback;
- a hosted LLM;
- private project fixtures.

Every failure must become one of:

- a regression in the owning package;
- a missing/incorrect published release;
- an evidence-backed follow-up;
- an explicit deferred contract gap.

## Release rule

A coordinated release is not done merely because each owning repository has a
release-prep commit.

For the 0.14 line:

1. candidate integration gates must pass against the exact reviewed owner SHAs;
2. `agent-learning` 0.9.0, `agent-session` 0.4.0 and
   `agent-recall-compiler` 0.10.0 must exist through the normal package release
   path;
3. rerun this clean-consumer gate without dependency fallbacks;
4. only a passing report makes that focused release set supported;
5. then merge/publish the matching `agent-loop` release.

This keeps “prepared in Git” separate from “installable by users”, a distinction
software releases have traditionally discovered five minutes after announcing
them.
