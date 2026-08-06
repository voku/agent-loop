# First-party agent discipline dogfood

Date: 2026-08-07

## Goal

Replace external RTK, Caveman, and Ponytail runtime guidance with package-owned
agent behavior that:

- keeps progress and final replies concise for humans;
- prevents speculative or unrequested implementation;
- uses `agent-map` before broad PHP reads;
- preserves raw source, diffs, tests, and verification evidence;
- installs without cloning repositories, plugin marketplaces, remote scripts,
  Node.js, or runtime dependencies.

## Source review

Direct `git clone` was attempted first and failed because the execution
container had no DNS/network access. The relevant upstream repository files were
then reviewed through the connected GitHub API at fixed commits:

- Caveman: `ec83e5bace4c20484d704dea21e12fc4eb94e9aa`
- Ponytail: `16f29800fd2681bdf24f3eb4ccffe38be3baec6b`

Reviewed surfaces included their primary skills, configuration and activation
hooks, mode parsers, subagent propagation, statistics, simplify-review/audit
skills, debt ledger, tests, and published benchmark notes.

## Kept

- persistent guidance at session and subagent start;
- concise communication while preserving negation, exact terms, paths, numbers,
  commands, and errors;
- a minimal implementation ladder applied after tracing the real code path;
- root-cause changes after caller inspection;
- a focused simplify-review separate from correctness and security review;
- explicit boundaries where brevity or minimalism must yield to clarity and
  safety;
- measurement from observable artifacts rather than invented savings.

## Rejected

- remote installers and plugin marketplaces;
- Node.js runtime code;
- mode parsers, status lines, flag files, transcript scanning, and savings
  estimates;
- client-specific adapters beyond the existing `agent-loop init` targets;
- rewriting commands or tool output;
- replacing full diffs with summaries;
- claiming token or line savings without a real baseline.

## Dogfood iterations

### Baseline

The first PR version merely removed RTK and recommended installing Caveman and
Ponytail. It did not satisfy the security or ownership requirement and had no
runtime dogfood.

### Candidate 1

A combined PHP-oriented skill plus Codex hooks passed six synthetic hook cases,
but the main skill was 116 lines and 4,670 bytes. That contradicted the intended
attention budget, so it was reduced rather than defended with another essay.

### Candidate 2

The skill was reduced to 99 lines and 3,799 bytes without losing the map-first,
minimal-change, evidence-integrity, safety, or validation contracts. The same
cases still passed.

### Candidate 3

The hook output was checked against Codex's actual hook parser rather than only
its JSON schema. This exposed three invalid combinations in the prototype:

- `continue:false` in `PreToolUse`;
- `suppressOutput:true` in `PreToolUse`;
- `permissionDecision:allow` without `updatedInput`.

The hook now leaves ordinary commands undecided and unchanged, and uses only
`permissionDecision:deny` with a non-empty reason for the two guarded cases.
The complete dogfood suite passed again.

## Executed cases

`php tools/agent-discipline-dogfood.php` currently verifies:

1. all three first-party skills and the hook definition exist;
2. hook commands contain no remote URL;
3. `SessionStart` injects the discipline context;
4. `SubagentStart` receives the same map-first guidance;
5. `git diff --no-ext-diff` remains allowed and receives no rewritten input;
6. an unbounded dump of `.agent-map/php-symbols.json` is denied with bounded
   `agent-loop map` alternatives;
7. a Caveman/Ponytail remote bootstrap command is denied and points to
   `agent-loop init install-assets`.

Latest local result:

```json
{
  "result": "passed",
  "checks": 7,
  "skill_lines": 99,
  "skill_bytes": 3799,
  "runtime_dependencies": 0,
  "remote_installers": 0
}
```

The local environment had PHP 8.4 but no Composer and no outbound network.
Therefore the hook/runtime dogfood was executed locally, while the clean
Composer-consumer installation is an explicit GitHub Actions scenario.

## Behavioral effect on this change

The discipline changed the implementation path itself:

- existing `sync-skills` and `sync-hooks` are reused instead of creating a new
  copy engine;
- only one thin `install-assets` orchestrator is added;
- the full external runtimes were rejected after inspection rather than ported;
- the first skill draft was shortened after measuring its own size;
- a schema-looking hook response was rejected after checking Codex's parser;
- `agent-map` is treated as a navigation index, never as prompt material to dump.

This is the intended loop: guidance changes the work, the work exposes a defect
in the guidance, and the same case is rerun until the contract survives.
