# Project-aware PHP setup for coding agents (read-only discovery)

The purpose of this workflow is to let an existing coding agent configure Agent Loop **for the current PHP repository**, not merely dump detected settings.

Give your coding host (Codex, Claude Code, etc.) this instruction:

> Set up Agent Loop for this PHP project. Run `vendor/bin/agent-loop init recommend --format=llm` at the repository root, follow the returned task and evidence, inspect existing PHP quality tools, propose the minimal project-specific configuration and show diffs and dry-run output before changing anything.

The `--format=llm` output is a JSON brief containing an explicit objective, trust boundary, step-by-step setup workflow, required deliverables, preview commands and the deterministic project evidence. It is designed to be read by a coding agent already authorized to inspect this repository. Agent Loop itself does not call an LLM or require model keys.

For scripts, integrations and tools that need the evidence without the task framing, use the original format:

```bash
vendor/bin/agent-loop init recommend --format=json
```

The underlying evidence document reports:

- **sources**: detected PHPStan, PHP-CS-Fixer, Composer, slop-scan and agent-loop configuration files;
- **map**: a proposed `--paths` scope, source and copyable command, always marked `requires_confirmation`;
- **exclusions**: literal PHPStan `excludePaths` and existing slop-scan `ignores`, **not** automatically merged;
- **prompting**: the current `.agent-loop/init.json` language or the `en` default, without claiming a user preference;
- **review**: the existing three-`changes_required` human Attention cap. This is a safety invariant, **not** an adjustable reviewer count;
- **next_actions/questions**: safe preview commands and unresolved decisions for a human or an LLM.

## What the coding agent should produce

The coding host should inspect the project and propose a **minimal, reviewable setup**: Agent Loop prompt language, host assets, map index roots and scan-only dependencies, PHP quality gates based on existing Composer/PHPUnit/PHPStan/PHP-CS-Fixer setup, and Slop Scan rules only where independently justified. It must preserve project-owned files, account for uncertainty, present exact diffs and dry-run commands, and request approval before writes or trust-boundary changes. The report is a starting point for a workflow, not an approved configuration.

## Deliberate boundaries

This command **never writes**, runs Composer, executes PHP-CS-Fixer configs, calls a model, changes host policy, alters the review Attention safeguard, or installs tooling. It can safely inspect a Finder configuration's *presence*, not its effective execution-dependent scope.

The PHPStan path extractor intentionally recognizes only a simple literal `parameters.paths` list. It does **not** resolve NEON includes, parameters, dynamic values or arbitrary nesting. When no usable literal scope is found, it falls back to existing Composer PSR-4 directories, then existing `src`/`tests` directories. It avoids absolute/traversal/out-of-root paths and emits no map command when there is no usable scope.

PHPStan `excludePaths`, PHP-CS-Fixer Finder exclusions, slop-scan glob ignores and agent-map regular expressions have different meanings. In particular, excluding test fixtures from a coding-style fixer does **not** mean security/slop findings from those fixtures should be hidden. Interpret these as evidence and ask for confirmation before generating a config diff.

In an installed Composer consumer, `init sync-tools --dry-run` now finds bundled tool templates when the project has no `resources/tools` directory. An explicitly configured `paths.tools_root` or `--tools-root` remains authoritative and fails if it is missing. Preview first, then opt into installing the tool files and their dependencies:

```bash
vendor/bin/agent-loop init sync-tools --dry-run
vendor/bin/agent-loop init sync-tools
composer install --working-dir=tools/slop-scan --no-scripts --no-plugins
php tools/slop-scan/slop-scan.php scan . --config-file slop-scan.config.json --lint
```

`init recommend` never executes any of these mutation or network steps. The [exact-head dogfood workflow](../../.github/workflows/init-recommend-dogfood.yml) verifies both dry-run and actual tool-file projection in a disposable consumer, then installs and runs the real Slop Scan package. Its JSON proof is uploaded as a GitHub Actions artifact bound to the PR head.

## Next slices

The LLM-ready brief is an actionable instruction envelope, **not** a configuration writer or proposal validator. An agent can use this bounded JSON as context. It cannot treat a proposal as an approved configuration. [#947](https://github.com/voku/agent-loop/issues/947) tracks the typed proposal/diff/approval step, optional host-backed guidance, per-tool ignore translation with explicit confirmation, and installed-consumer CI.

No LLM-specific model or secret is required for the read-only command.
