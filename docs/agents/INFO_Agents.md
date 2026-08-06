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
it intentionally installs the immutable assets shipped inside the currently
installed `voku/agent-loop` package.

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

Use `--agent=all` to install the bundled skills for every supported client.
Codex additionally receives the repository-local PHP hooks because that hook
contract is validated and dogfooded in this package. Claude, Copilot, and
Antigravity currently receive the portable skills only.

The command:

- reads assets from the installed Composer package;
- reuses the existing manifest-safe `sync-skills` and `sync-hooks` code;
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
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init sync-subagents --agent=copilot --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
vendor/bin/agent-loop init scaffold --dry-run
```

`init doctor` and `init status` are read-only. `init tools` writes only the
bounded `.agent-loop/tool-inventory.json` cache. `install-plan` prints commands
but executes none. `install-assets` and `sync-*` are the explicit mutation
boundaries.

## The three budgets

The first-party discipline keeps three concerns separate:

1. **Human attention:** progress and final replies remain concise, grammatical,
   and technically exact.
2. **Implementation complexity:** the agent stops at the first verified solution
   that fully satisfies the request.
3. **Context:** `agent-map` and recall select bounded source ranges before broad
   file reads.

None of these permits rewriting evidence. Source files, full diffs, test output,
static-analysis output, verification artifacts, redirected harness files, and
decisive errors remain complete and unchanged. A summary may point to evidence;
it never replaces evidence.

## Package-owned discipline

### `agent-loop-discipline`

The default PHP engineering discipline:

- trace the real flow and callers before editing shared behavior;
- use `agent-loop map query`, `related`, `file`, and `changed` before broad PHP
  reads;
- prefer existing repository code, PHP standard library, platform features,
  installed dependencies, one shared root-cause fix, and deterministic edits;
- preserve strict types, precise PHPDoc, contextual exceptions, package
  ownership, security controls, and focused regression tests;
- report only executed validation.

### `agent-loop-simplify-review`

A separate one-shot review of the complete raw diff for unnecessary complexity.
It may identify deletion, repository reuse, standard-library replacements,
native platform features, speculative abstractions, smaller expressions, and
wrong package ownership. It does not replace correctness, security,
accessibility, or performance review and applies no changes automatically.

### `agent-loop-dogfood`

A repeatable evaluation method for guidance and hook changes. Baseline and
candidate runs use the same task, repository revision, model, tools, and
validation. The comparison uses observable artifacts such as changed files,
line counts, dependencies, unrequested behavior, broad reads, executed checks,
response length, and review findings. It does not invent reasoning-token or
counterfactual code savings.

## Codex hooks

The bundled hooks are thin PHP entrypoints backed by the typed
`AgentDisciplineHook` class under `src/`.

- `SessionStart` injects the package-owned discipline.
- `SubagentStart` propagates the same contract to spawned agents.
- `PreToolUse` leaves ordinary Bash commands unchanged.
- `PreToolUse` denies only two repository-specific unsafe patterns:
  - remote bootstrap commands for the previously evaluated add-ons;
  - unbounded dumps of generated `.agent-map` indexes.

The denial names a bounded replacement. It does not rewrite a command or filter
its output. The hook output matches Codex's current command-hook schemas and
parser behavior, including the requirement that `PreToolUse` pass-through
returns no synthetic `allow` decision.

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

verifies the packaged skills, hook definition, session/subagent context,
unchanged raw diff command, bounded map denial, and remote-bootstrap denial.

`composer ci` runs PHPUnit, PHPStan, and this gate. The GitHub release-set job
also installs `agent-loop` into a clean non-symlinked Composer consumer, checks
that the assets exist under `vendor/voku/agent-loop`, executes
`init install-assets`, and runs the dogfood script from the installed package.

The reviewed iterations and observed failures are recorded in
`docs/agents/dogfood/2026-08-07-first-party-discipline.md`. Third-party
attribution is isolated in `docs/agents/THIRD_PARTY_NOTICES.md`; neither file is
an installation dependency.

## Validation

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer dogfood:discipline
composer ci
```

Never report a command as passed unless it ran and its exit code was observed.
