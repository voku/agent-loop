# First-party agent discipline dogfood

Date: 2026-08-07 (Europe/Berlin)

## Goal

Replace external RTK, Caveman, and Ponytail runtime guidance with package-owned
agent behavior that:

- keeps progress and final replies concise for humans;
- prevents speculative or unrequested implementation;
- uses `agent-map` to avoid broad PHP reads when navigation is needed;
- preserves raw source, diffs, tests, and verification evidence;
- installs without cloning repositories, plugin marketplaces, remote scripts,
  Node.js, or third-party runtime dependencies.

The merge gate is behavioral as well as mechanical. A green installer is not
enough if the resulting agent still reads broadly or invents work.

## Source review

Direct `git clone` was attempted first and failed because the execution container
had no DNS/network access. Relevant upstream files were reviewed through the
connected GitHub API at fixed commits:

- Caveman: `ec83e5bace4c20484d704dea21e12fc4eb94e9aa`
- Ponytail: `16f29800fd2681bdf24f3eb4ccffe38be3baec6b`

Reviewed surfaces included primary skills, activation/hooks, mode handling,
subagent propagation, statistics, simplify/audit skills, debt handling, tests,
and published benchmark notes.

## Kept

- concise human-facing communication that preserves exact paths, symbols,
  commands, numbers, negation, constraints, and errors;
- a minimal implementation ladder after locating the real owner and callers;
- root-cause changes instead of symptom patches;
- a separate simplify-review that reads the complete raw diff;
- `agent-map` as bounded navigation before broad PHP reads;
- explicit evidence integrity and safety floors;
- observable artifact metrics instead of invented token savings;
- session/subagent context hooks as optional behavioral guardrails.

## Rejected

- remote installers, repositories, and plugin marketplaces;
- Node.js runtime code;
- mode parsers, status lines, flag files, transcript scanning, and savings
  estimates;
- command or tool-output rewriting;
- replacing full diffs with summaries;
- hook blacklists that pretend to be a security sandbox;
- host configuration for the immutable `install-assets` source;
- mandatory map ceremony for trivial documentation or already-localized edits;
- token or counterfactual code savings without direct telemetry.

## Iterations that changed the design

### 1. External recommendation was the wrong ownership model

The first PR version removed RTK but still recommended installing Caveman and
Ponytail. That did not satisfy the supply-chain or ownership goal. Their useful
ideas were therefore distilled into package-owned skills and PHP code instead.

### 2. The first discipline was too large

The initial combined skill was 116 lines / 4,670 bytes. A rule intended to save
human attention should not become another wall of instructions, so it was
reduced to 99 lines while preserving the implementation ladder, map boundary,
evidence integrity, safety floor, and validation contract.

### 3. The real Codex parser rejected schema-looking hook output

Testing against Codex's parser exposed invalid prototype combinations that a
superficial JSON check missed:

- `continue:false` in `PreToolUse`;
- `suppressOutput:true` in `PreToolUse`;
- synthetic `permissionDecision:allow` without `updatedInput`.

Pass-through now leaves commands undecided and unchanged. Map denials keep hook
processing alive and provide a bounded replacement.

### 4. The hook blacklist was itself speculative implementation

An earlier hook attempted to deny known Caveman, Ponytail, and RTK installation
commands. Dogfood first narrowed false positives, then the pre-merge simplify
pass asked the more important question: why is this repository trying to become
a shell security sandbox at all?

The user requirement is that `agent-loop init` never downloads third-party agent
code. That is guaranteed by the package-owned `install-assets` path. Hook
dispatch is host-dependent and cannot be a security boundary, so the external
installation blacklist was deleted. A regression case now proves such a command
passes through the hook unchanged.

### 5. `install-assets --config` was unnecessary flexibility

An earlier version accepted `--config` while deliberately refusing configured
skill/hook source roots. That produced an ambiguous half-contract and a review
suggestion to propagate configuration into the delegated sync commands.

The simpler boundary won: `install-assets` is configuration-free and reads only
assets shipped in the installed Composer package. Host-owned custom assets use
`sync-*`, where config and path overrides already belong.

## Pre-merge behavioral replay

A clean same-model A/B runner is not available in the connector-only execution
environment. The behavioral comparison therefore uses already-observed baseline
work from this PR and labels that limitation instead of pretending model runs
were identical.

### Case A: investigate `install-assets --config`

The baseline review used three broad repository shell probes with recorded output
sizes of 8,119, 7,666, and 28,542 characters: **44,327 characters total**. It
concluded that `--config` should be propagated further.

The disciplined replay inspected the three owning surfaces directly:

1. `src/Init/InitInstallAssetsCommand.php`;
2. `tests/InitInstallAssetsCommandTest.php`;
3. `src/Init/InitAgent.php`.

The result was not another propagation path. It removed `--config` from
`install-assets` and kept host customization in the existing `sync-*` commands.

| Metric | Observed baseline | Disciplined replay |
| --- | ---: | ---: |
| Broad repository probes | 3 | 0 |
| Recorded broad-probe output | 44,327 chars | 0 chars |
| Focused owning files inspected | not isolated | 3 |
| New config surface | proposed | 0 |
| New dependency | 0 | 0 |
| Result | propagate flexibility | delete unnecessary flexibility |
| Raw evidence retained | yes | yes |

This is the context/review-time improvement the project is aiming for: locate the
owner, read the bounded evidence, and stop rather than searching wider until an
additional mechanism looks justified.

### Case B: run the new minimalism rule against this PR

The last fully green pre-merge baseline was
`c25dc91b72c9ea3510d8a404b5e554214dfd89dc`. Applying the new discipline and
simplify-review to the PR itself changed ten files by **+118 / -181 lines**, a
net reduction of **63 lines** at the measured checkpoint.

The deleted surface included:

- the external add-on installation blacklist in `AgentDisciplineHook`;
- five bootstrap-denial regression cases;
- `install-assets --config` parsing/loading;
- the custom-config source test;
- obsolete bootstrap-denial dogfood machinery.

No replacement dependency, factory, interface, generic manager, config switch,
or second asset-copy engine was introduced.

### Case C: trivial work must stay trivial

The discipline now explicitly skips map ceremony for documentation-only or
already-localized edits. The documentation changes in this pre-merge pass were
performed directly against their known files; no map build/query was required.
This protects the opposite failure mode: a process for reducing context should
not make a one-file text edit require a workflow pageant.

## Current runtime gate

`php tools/agent-discipline-dogfood.php` executes the configured hook commands in
an isolated workspace without requiring a Git repository and verifies:

1. all first-party skills and hook definitions are present;
2. configured hook commands contain no remote URL or Git-root dependency;
3. `SessionStart` injects the discipline;
4. `SubagentStart` receives the same discipline;
5. `git diff --no-ext-diff` passes through without command rewriting;
6. an external installer command also passes through, proving the hook is not a
   security sandbox;
7. unbounded JSON/SQLite map dumps are denied with bounded `agent-loop map`
   alternatives.

The gate reports zero runtime dependencies and zero remote installers for the
package-owned discipline.

## Installed consumer evidence

GitHub Actions CI #325 on
`c25dc91b72c9ea3510d8a404b5e554214dfd89dc` passed:

- `composer ci` on PHP 8.3, 8.4, and 8.5;
- the clean non-symlinked Composer consumer lifecycle;
- `init install-assets` from the installed `vendor/voku/agent-loop` package;
- the installed first-party asset dogfood script.

That run predates the final simplification in this report. The current head must
pass the same gates before merge; this document does not claim that result until
the new Actions exit codes are observed.

## Acceptance decision

The candidate meets the behavioral goal when the current CI is green because:

- third-party agent add-ons are no longer runtime or init dependencies;
- the immutable installer cannot be redirected by host config;
- the hook is explicitly a behavior aid rather than fake security isolation;
- raw source, diffs, tests, and verification artifacts remain untouched;
- a real review replay replaced three broad probes / 44,327 characters of tool
  output with three bounded owner reads;
- applying the minimalism rule to its own implementation removed net code and
  two unrequested mechanisms instead of adding another abstraction;
- trivial localized work is exempt from mandatory map ceremony.

This is the intended loop: guidance changes the work, the work exposes defects
in the guidance, and defects are removed until both behavior and validation
support the reason the guidance exists.
