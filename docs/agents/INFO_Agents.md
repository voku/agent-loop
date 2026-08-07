# Agent Assets In agent-loop

## Purpose

`agent-loop` ships portable, repository-managed agent assets with the Composer
package. Consumers install those reviewed files from their local
`vendor/voku/agent-loop` copy; `init` does not clone repositories, execute remote
installers, or depend on an external plugin marketplace.

Canonical package roots:

- `docs/agents/skills/`
- `docs/agents/subagents/`
- `docs/agents/codex-hooks/`
- `docs/agents/tools/`

Host repositories may override the normal sync source roots through
`.agent-loop/init.json` or CLI path options. `init install-assets` is different:
it is intentionally configuration-free and installs only the immutable assets
shipped inside the currently installed `voku/agent-loop` package.

## First-party install

Review the plan:

```bash
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
```

Preview and install the package-owned assets:

```bash
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
vendor/bin/agent-loop init install-assets --agent=codex
vendor/bin/agent-loop init doctor
```

Use `--agent=all` for the complete package-owned set:

- portable skills for Codex, Claude, Copilot, and Antigravity;
- investigator, surgical-builder, and code-reviewer subagent definitions for
  the existing Copilot and Antigravity agent targets;
- repository-local PHP hooks for Codex.

Claude currently receives the portable skills only. Codex receives the role
behavior through those portable skills plus the `SubagentStart` discipline hook;
`sync-subagents` does not currently define a Codex target format.

The command:

- reads assets only from the installed Composer package;
- accepts no host config or source-root override;
- reuses the existing manifest-safe `sync-skills`, `sync-subagents`, and
  `sync-hooks` code;
- refuses to overwrite unmanaged targets unless `--force` or
  `--adopt-existing` is explicit;
- supports `--dry-run`;
- downloads and executes nothing.

After Codex installation, open `/hooks`, inspect the copied PHP files, and trust
them only after review.

## Current commands

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init status
vendor/bin/agent-loop init tools
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-plan --profile=linux --agent=codex
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init sync-subagents --agent=copilot --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
vendor/bin/agent-loop init scaffold --dry-run
```

`init doctor` and `init status` are read-only. `init tools` writes only the
bounded `.agent-loop/tool-inventory.json` cache. `install-plan` prints commands
but executes none. `install-assets` and `sync-*` are the explicit mutation
boundaries.

## What was adapted

The package-owned behavior combines concrete mechanisms reviewed in Caveman and
Ponytail rather than merely repeating their slogans. Exact upstream commits and
the mechanism mapping are recorded in `THIRD_PARTY_NOTICES.md`.

### `agent-loop-discipline`

The shared PHP engineering discipline combines concise communication with the
minimal implementation ladder:

- trace the real flow and callers before editing shared behavior;
- use `agent-loop map query`, `related`, `file`, and `changed` before broad PHP
  reads, without adding map ceremony to trivial or already-localized work;
- prefer existing repository code, PHP standard library, platform features,
  installed dependencies, one shared root-cause fix, and deterministic edits;
- stop once the requested behavior is satisfied instead of adding adjacent
  cleanup, configuration, abstractions, compatibility, or policy;
- preserve exact paths, symbols, commands, numbers, negation, errors, and raw
  evidence while removing filler from progress/final communication;
- preserve strict types, precise PHPDoc where needed, contextual exceptions,
  package ownership, security controls, and focused regression tests.

### `agent-loop-investigate`

Read-only locator derived from Caveman's investigator role but adapted around
`agent-map`. It returns verified `path:line`, symbol, caller, test, and reference
locations after bounded real-source reads. It neither edits nor proposes fixes.

### `agent-loop-surgical-edit`

Bounded one/two-file implementation role. It prefers deterministic
`agent-loop edit --runner=auto` when an exact replacement is proven, otherwise
makes the smallest verified edit. It escalates instead of silently widening to a
cross-cutting change.

### `agent-loop-code-review`

Concise correctness review of the complete raw diff. Findings are
path/line/severity/problem/fix, with `agent-map` caller lookup when needed. It is
separate from complexity review so terse output does not collapse two different
questions into one vague audit.

### `agent-loop-simplify-review`

Diff-only complexity review adapted from Ponytail review. It identifies deletion,
repository reuse, standard-library/native replacements, speculative abstractions,
smaller expressions, and wrong package ownership. It applies nothing.

### `agent-loop-simplify-audit`

Repo-wide counterpart adapted from Ponytail audit. It uses map/navigation data to
prioritize candidates, verifies them against real source/callers, and ranks
concrete maintenance surface that could be removed.

### `agent-loop-task-progress`

Deliberate simplification debt is not stored as tool-specific comments. A
shortcut with a real ceiling is recorded in `agent-session` as a decision naming
that ceiling and an observable revisit trigger. Only reusable conclusions move
through the normal `agent-learning` review boundary.

### `agent-loop-dogfood`

Behavioral evaluation uses observable artifacts such as tool calls, source
reads, changed files, dependencies, unrequested behavior, executed checks,
response length, full-evidence inspection, and review findings. No per-repo
savings number is invented from code that was never built.

## Package-owned subagent roles

The canonical definitions under `docs/agents/subagents/` mirror the three-role
Cavecrew workflow without copying its runtime:

1. `agent-loop-investigator`: locate with `agent-map`, verify real source, return
   terse evidence;
2. `agent-loop-surgical-builder`: edit only already-understood 1-2 file scope,
   validate, return a receipt;
3. `agent-loop-code-reviewer`: inspect raw diff and relevant callers, return only
   actionable correctness findings.

A common flow is investigator -> surgical builder -> code reviewer. Broader
features and cross-cutting refactors stay in the main governed workflow instead
of being forced through a tiny builder role.

## The three budgets

The first-party discipline keeps three concerns separate:

1. **Human attention:** progress and final replies remain concise, grammatical,
   and technically exact.
2. **Implementation complexity:** the agent stops at the first verified solution
   that fully satisfies the request.
3. **Context:** `agent-map` and recall select bounded source ranges before broad
   file reads.

None permits rewriting evidence. Source files, full diffs, test output,
static-analysis output, verification artifacts, redirected harness files, and
decisive errors remain complete and unchanged. A summary may point to evidence;
it never replaces evidence.

## Codex hooks

The bundled hooks are thin PHP entrypoints backed by the typed
`AgentDisciplineHook` class under `src/`.

- `SessionStart` injects the package-owned discipline.
- `SubagentStart` propagates the same contract to spawned agents.
- `PreToolUse` leaves ordinary Bash commands unchanged.
- `PreToolUse` redirects only unbounded dumps of generated `.agent-map` indexes
  toward bounded map commands.

Hooks are behavioral guardrails, never a correctness or security boundary. A
host may fail to dispatch a hook, so correctness, trust-boundary validation, CI,
and the offline install contract must remain valid without hook execution. The
hook does not rewrite commands or filter tool output.

## agent-map boundary

Generated map files are navigation state, not source evidence:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
vendor/bin/agent-loop map stats
```

Do not dump `.agent-map/php-symbols.json` or `.agent-map/search.sqlite` into a
prompt. Use map results to select the smallest real source range, then inspect
that source directly.

## Historical search

`ctx` remains an optional local history search tool:

```bash
ctx search "<task / module / failure / command>"
ctx show event <ctx-event-id> --window 5
```

History is discovery material, not current evidence or durable memory. Inspect a
focused event, verify it against the current repository, and persist only a
bounded reference when it changes a learning conclusion.

## Host-repository overrides

A host with legacy assets under `infra/doc/agents/` may configure the ordinary
validation and sync commands:

```json
{
  "version": 1,
  "paths": {
    "skills_root": "infra/doc/agents/skills",
    "subagents_root": "infra/doc/agents/subagents",
    "codex_hooks_root": "infra/doc/agents/codex-hooks",
    "tools_root": "infra/doc/agents/tools",
    "recall_root": "infra/doc/agent-learning/recall-output"
  }
}
```

```bash
vendor/bin/agent-loop init validate --kind=all --config=.agent-loop/init.json
vendor/bin/agent-loop init sync-skills --agent=codex --config=.agent-loop/init.json --dry-run
```

Do not edit generated client copies first. Update canonical host sources,
validate them, then sync them. Use `install-assets` when the package-owned
first-party defaults are wanted instead.

## Dogfood contract

The local gate:

```bash
composer dogfood:discipline
```

verifies the packaged discipline, hook definition, session/subagent context,
unchanged raw commands, the explicit non-security hook boundary, and bounded map
denial.

`composer ci` runs PHPUnit, PHPStan, and this gate. The GitHub release-set job
also installs `agent-loop` into a clean non-symlinked Composer consumer, executes
`init install-assets --agent=all`, checks the installed skills/subagent roles and
Codex hooks, and runs the dogfood script from the installed package.

Runtime gates prove mechanics. Guidance changes additionally require the
behavioral acceptance method documented by `agent-loop-dogfood`; a green
installer alone does not prove that the agent became easier to review or less
speculative.

The reviewed iterations and observed failures are recorded in
`docs/agents/dogfood/2026-08-07-first-party-discipline.md`. Third-party
attribution and mechanism mapping are in `docs/agents/THIRD_PARTY_NOTICES.md`;
neither file is an installation dependency.

## Validation

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init sync-subagents --agent=copilot --dry-run
vendor/bin/agent-loop init doctor
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer dogfood:discipline
composer ci
```

Never report a command as passed unless it ran and its exit code was observed.
