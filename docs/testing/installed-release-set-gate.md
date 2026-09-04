# Installed release-set dogfood gate

Tracking: [agent-loop#20](https://github.com/voku/agent-loop/issues/20)

The release-set gate proves the `agent-*` packages as a clean Composer consumer
sees them. Package-local tests remain necessary, but they cannot detect an
installed package loading a sibling checkout, a nested `vendor/` tree, or a
runtime/guidance combination that only fails after the packages are composed.
Humanity has already tried “all the individual pieces looked green” as a system
integration strategy. The results were educational.

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

The default run resolves focused owner packages from published Composer sources.
A checkout under `build/candidate-*` is ignored unless it is explicitly selected;
leftover directories must never silently change a run that is being used as
published-release evidence.

For a coordinated owner change, name every candidate package deliberately:

```bash
php tools/release-set-dogfood.php \
  --candidate=voku/agent-session \
  --candidate=voku/agent-recall-compiler \
  --keep
```

Only the supported owner candidates may be named. A requested candidate without
a checkout and `composer.json` at its canonical `build/candidate-*` path fails
before Composer resolution instead of falling back to a release.

The runner itself has no Composer dependencies. It stages only the candidate
package metadata, `src/`, `bin/`, and `resources/`; the candidate's
development `vendor/` directory is never copied into the consumer fixture.

## Installed topology

```text
clean temporary workspace
├── candidate-agent-loop/
│   ├── composer.json
│   ├── bin/
│   ├── resources/
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
repository. Every focused package is resolved through the candidate's real
Composer constraints. The report records the exact resolved version, source
type, and source URL for every installed package, plus the explicitly selected
owner candidates. A normal published-owner run also fails if Session, Recall,
or Learning unexpectedly resolves from a path repository.

## Current scenarios

The first executable gate covers:

1. clean Composer resolution;
2. class loading through the consumer's `vendor/` tree;
3. every public `agent-loop` namespace help path;
4. canonical map and derived search-index preparation;
5. exact target discovery;
6. English behavior search;
7. German behavior search with the original UTF-8 argument preserved;
8. a structural no-answer query that must not return a known fixture symbol;
9. Codex host projection plus installed guidance/CLI agreement for `init status`
   and dry-run `init sync-instructions`;
10. an ephemeral experiment excluded from repository-wide verification;
11. a governed task through plan and approval;
12. a deterministic fixture-owned exact source change;
13. map/search refresh and recall recompilation;
14. declared validation evidence;
15. blind-spot review and checkpoint;
16. an explicit `no_durable_learning` decision;
17. stale-manifest detection and explicit repair;
18. cross-package verification and workflow close.

The fixture is deliberately repository-neutral. It contains a small retry policy,
one caller, one unrelated invoice service, and no private downstream names or
business rules.

## Report contract

The runner writes canonical JSON with:

- report schema version;
- explicitly selected owner candidates;
- resolved package set with exact version, source type, and source URL;
- PHP, Composer and platform diagnostics;
- ordered scenario IDs;
- exact command argument arrays and exit codes;
- stdout/stderr hashes and retained log paths;
- bounded artifact paths and hashes;
- classified friction;
- one overall result.

Stable ordering and field names are part of the contract. The following values
are evidence rather than byte-stable identities and may vary between equivalent
runs:

- PHP, Composer and operating-system versions;
- installed package source references when the candidate commit changes;
- command stdout/stderr hashes when Composer or tools include environment paths;
- SQLite file hashes;
- Git-generated commit identities inside the disposable consumer.

Consumers comparing reports must compare scenario/result semantics and the
resolved release set before treating those volatile evidence fields as drift.

## Failure policy

The gate stops after the first failed dependent scenario, writes the report, and
retains the scenario logs. It does not retry with:

- sibling repository autoloaders;
- a nested package `vendor/` tree;
- symlinked candidate source;
- relaxed package constraints;
- an unrequested `build/candidate-*` checkout;
- a hosted LLM;
- private project fixtures.

Every failure must become one of:

- a regression in the owning package;
- an evidence-backed follow-up;
- an explicit deferred contract gap.

## Deliberate remaining gaps

This first slice does **not** complete issue #20.

- The governed source change is fixture-owned and deterministic. A follow-up
  must exercise the real `agent-loop edit --runner=mechanical` bundle and
  `edit verify` path without leaking verifier-owned answers.
- Search-index snapshot mismatch needs the versioned readiness contract from
  `agent-map#2` rather than test code parsing SQLite internals.
- Installed projection/CLI agreement is now release-gated, but live host runtime
  behavior still requires explicit runtime evidence before being called supported.
- The current gate proves one candidate `agent-loop` plus its resolved focused
  dependencies. Compatibility edges are added only when a supported edge is
  named, not by generating every version combination humans can imagine.

The issue closes only when those scenarios join the same clean-consumer report
and the gate has produced real passing release evidence.
