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
container had no DNS/network access. Relevant upstream files were then reviewed
through the connected GitHub API at fixed commits:

- Caveman: `ec83e5bace4c20484d704dea21e12fc4eb94e9aa`
- Ponytail: `16f29800fd2681bdf24f3eb4ccffe38be3baec6b`

Reviewed surfaces included primary skills, configuration and activation hooks,
mode parsers, subagent propagation, statistics, simplify-review/audit skills,
debt handling, tests, and published benchmark notes.

## Kept

- persistent guidance at session and subagent start;
- concise communication that preserves negation, exact terms, paths, numbers,
  commands, and errors;
- a minimal implementation ladder applied after tracing the real code path;
- root-cause changes after caller inspection;
- a focused simplify-review separate from correctness and security review;
- explicit points where brevity or minimalism must yield to clarity and safety;
- observable artifact metrics instead of invented savings.

## Rejected

- remote installers and plugin marketplaces;
- Node.js runtime code;
- mode parsers, status lines, flag files, transcript scanning, and savings
  estimates;
- client-specific adapters beyond existing `agent-loop init` targets;
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

The hook now leaves ordinary commands undecided and unchanged. A denial uses
only `permissionDecision:deny` with a non-empty reason and keeps hook processing
alive.

### Candidate 4

The first external-bootstrap matcher denied any command that merely mentioned an
upstream repository. That would have blocked legitimate work such as:

```bash
rg 'JuliusBrussee/caveman|DietrichGebert/ponytail|rtk-ai/rtk' docs CHANGELOG.md
```

The matcher was narrowed to actual download, package-install, plugin-install,
script-execution, and `rtk init` forms. Research now passes unchanged while the
replaced bootstraps remain denied.

## Current repository gate

`php tools/agent-discipline-dogfood.php` verifies ten checks:

1. all three first-party skills and the hook definition exist;
2. hook commands contain no remote URL;
3. `SessionStart` injects the discipline context;
4. `SubagentStart` receives the same map-first guidance;
5. `git diff --no-ext-diff` remains allowed and receives no rewritten input;
6. research about the replaced projects remains allowed and unchanged;
7. an unbounded dump of `.agent-map/php-symbols.json` is denied with bounded
   `agent-loop map` alternatives;
8. a Caveman bootstrap is denied and points to `init install-assets`;
9. a Ponytail bootstrap is denied and points to `init install-assets`;
10. an RTK bootstrap is denied and points to `init install-assets`.

## Latest executed local evidence

The current `AgentDisciplineHook` source was materialized into an isolated local
workspace and executed with PHP 8.4 after Candidate 4. PHP lint passed and eight
current policy/context cases passed:

```json
{
  "result": "passed",
  "cases": 8
}
```

Those cases covered session context, raw diff pass-through, upstream research
pass-through, unbounded map denial, and Caveman, Ponytail, RTK-download, and
`rtk init` denial.

The earlier full runtime dogfood also validated the unchanged PHP hook
entrypoints. The current repository gate is wired into `composer ci`; the clean
non-symlinked Composer-consumer installation is wired into GitHub Actions.
Neither Composer nor an Actions runner was available in the connector-only
local environment, so those broader gates remain unclaimed until their exit
codes are observed.

## Behavioral effect on this change

The discipline changed the implementation path itself:

- existing `sync-skills` and `sync-hooks` are reused instead of adding another
  copy engine;
- only one thin `install-assets` orchestrator was added;
- full external runtimes were rejected after inspection rather than ported;
- the first skill draft was shortened after measuring its own size;
- a schema-looking hook response was rejected after checking Codex's parser;
- an overbroad security matcher was narrowed after dogfood exposed a false
  positive;
- `agent-map` is treated as navigation state, never prompt material to dump.

This is the intended loop: guidance changes the work, the work exposes defects
in the guidance, and the same cases are rerun until the contract survives.
