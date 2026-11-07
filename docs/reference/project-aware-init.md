# Project-aware init recommendations (read-only)

The first slice of [#947](https://github.com/voku/agent-loop/issues/947) exposes deterministic project evidence to a human or an LLM-aware coding host:

For the **actual end-user onboarding flow**, see [PHP project onboarding with a coding agent](php-project-onboarding.md). The coding host's own LLM uses the evidence; Agent Loop needs no second model client.

```bash
vendor/bin/agent-loop init recommend --format=json
```

The JSON document reports:

- **sources**: detected PHPStan, PHP-CS-Fixer, Composer, slop-scan and agent-loop configuration files;
- **map**: a proposed `--paths` scope, source and copyable command, always marked `requires_confirmation`;
- **quality**: names of declared Composer check/test/analyse scripts only; script bodies, side effects and dependencies remain unverified and require approval before execution;
- **exclusions**: literal PHPStan `excludePaths` and existing slop-scan `ignores`, **not** automatically merged;
- **prompting**: the current `.agent-loop/init.json` language or the `en` default, without claiming a user preference;
- **review**: the existing three-`changes_required` human Attention cap. This is a safety invariant, **not** an adjustable reviewer count;
- **next_actions/questions**: safe preview commands and unresolved decisions for a human or an LLM. Commands use the Composer-installed `vendor/bin/agent-loop` executable, without requiring a globally installed CLI on `PATH`.

## Reviewable, read-only candidate plan

The next narrow slice of #947 exposes a typed proposal view without adding an unsafe
configuration writer:

```bash
vendor/bin/agent-loop init plan --format=json
```

Its deterministic JSON includes a SHA-256 digest of the bounded `init recommend` evidence,
proposals with owner, source, confidence and approval prerequisites, an explicit
`execution: not_authorized` marker, and `file_changes: []`.

A project with no usable map scope or quality scripts produces no candidates.
Detected Composer script names are *not* validation authority: the host must inspect
their bodies before proposing execution. Literal PHPStan paths, Composer declarations
and directory-presence fallbacks have different provenance labels.

This command **does not** modify `.agent-loop/init.json`, slop-scan configuration,
map state or the human Attention safeguard. The empty file diff is intentional:
no configuration mutation is proposed based solely on default values or tool-specific
excludes. The coding host may present separate minimal changes for explicit approval,
but `init diff` and `init apply` do not yet exist.

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

An LLM can use this bounded JSON and the read-only candidate plan as context. It cannot treat a proposal as an approved configuration. [#947](https://github.com/voku/agent-loop/issues/947) tracks the typed proposal/diff/approval step, optional host-backed guidance, per-tool ignore translation with explicit confirmation, and installed-consumer CI.

No LLM-specific model or secret is required for the read-only command.
