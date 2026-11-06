# Project-aware init recommendations (read-only)

The first slice of [#947](https://github.com/voku/agent-loop/issues/947) exposes deterministic project evidence to a human or an LLM-aware coding host:

```bash
vendor/bin/agent-loop init recommend --format=json
```

The JSON document reports:

- **sources**: detected PHPStan, PHP-CS-Fixer, Composer, slop-scan and agent-loop configuration files;
- **map**: a proposed `--paths` scope, source and copyable command, always marked `requires_confirmation`;
- **exclusions**: literal PHPStan `excludePaths` and existing slop-scan `ignores`, **not** automatically merged;
- **prompting**: the current `.agent-loop/init.json` language or the `en` default, without claiming a user preference;
- **review**: the existing three-`changes_required` human Attention cap. This is a safety invariant, **not** an adjustable reviewer count;
- **next_actions/questions**: safe preview commands and unresolved decisions for a human or an LLM.

## Deliberate boundaries

This command **never writes**, runs Composer, executes PHP-CS-Fixer configs, calls a model, changes host policy, alters the review Attention safeguard, or installs tooling. It can safely inspect a Finder configuration's *presence*, not its effective execution-dependent scope.

The PHPStan path extractor intentionally recognizes only a simple literal `parameters.paths` list. It does **not** resolve NEON includes, parameters, dynamic values or arbitrary nesting. When no usable literal scope is found, it falls back to existing Composer PSR-4 directories, then existing `src`/`tests` directories. It avoids absolute/traversal/out-of-root paths and emits no map command when there is no usable scope.

PHPStan `excludePaths`, PHP-CS-Fixer Finder exclusions, slop-scan glob ignores and agent-map regular expressions have different meanings. In particular, excluding test fixtures from a coding-style fixer does **not** mean security/slop findings from those fixtures should be hidden. Interpret these as evidence and ask for confirmation before generating a config diff.

`init sync-tools --dry-run` previews the isolated slop-scan project installation files; it does not install Composer dependencies. Continue through the existing owner commands, not a new hidden installer.

## Next slices

An LLM can use this bounded JSON as context. It cannot treat a proposal as an approved configuration. [#947](https://github.com/voku/agent-loop/issues/947) tracks the typed proposal/diff/approval step, optional host-backed guidance, per-tool ignore translation with explicit confirmation, and installed-consumer CI.

No LLM-specific model or secret is required for the read-only command.
