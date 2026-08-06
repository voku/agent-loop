---
name: agent-guidance-maintenance
description: Maintain package-owned and host-owned agent skills, hooks, docs, sync targets, dogfood evidence, and migration-safe validation.
---

# Agent Guidance Maintenance

Use this skill for repository-managed agent guidance: skills, hooks, shared docs,
validation, client synchronization, installation, and host migration notes.
Apply `agent-loop-discipline` to implementation work and
`agent-loop-dogfood` when behavior or hook semantics change.

## Fast Path

1. Edit the canonical source under `docs/agents/` or typed runtime under `src/`.
2. Keep the change scoped to the guidance contract.
3. Update executable help and focused tests when public `init` behavior changes.
4. Run the local dogfood case before broad validation.
5. Validate canonical assets and dry-run package installation.
6. Test a clean installed Composer consumer when package-owned assets change.
7. Update README, changelog, notices, and dogfood notes when the public contract
   or provenance changes.
8. Audit for contradictory instructions, duplicate skills, remote bootstraps,
   lossy evidence handling, and unverified claims.

## Canonical Files

- `docs/agents/skills/`;
- `docs/agents/codex-hooks/`;
- `docs/agents/INFO_Agents.md`;
- `docs/agents/dogfood/`;
- `docs/agents/THIRD_PARTY_NOTICES.md`;
- `src/AgentGuidance/`;
- `src/Init/`;
- `tools/agent-discipline-dogfood.php`;
- `tests/AgentDisciplineHookTest.php`;
- `tests/InitInstallAssetsCommandTest.php`;
- `README.md`, `CHANGELOG.md`, and `.github/workflows/ci.yml`.

Do not begin with generated copies under `.codex/`, `.claude/`, `.github/`, or
`.agents/`. Update the canonical package or host source, validate it, then use
`install-assets` or `sync-*`.

## Package-owned Versus Host-owned

- `init install-assets` always reads the assets shipped inside the installed
  `voku/agent-loop` package.
- `init sync-skills`, `sync-subagents`, and `sync-hooks` read the host's resolved
  canonical roots and support config/CLI overrides.
- Both paths use target manifests and refuse unmanaged overwrites unless the
  caller explicitly chooses `--force` or `--adopt-existing`.

Do not make `install-assets` honor a host override for its source. That would
turn an immutable package-install command into another ambiguous sync command.

## Guidance Rules

- Describe behavior that exists now; label future work explicitly.
- Keep human attention, implementation complexity, context size, and raw
  evidence as separate concerns.
- Use `agent-map` for bounded navigation; never dump generated indexes into a
  prompt.
- Preserve source, full diffs, command output, tests, and verification artifacts.
- Reject command rewriting or output compression that can hide lines or alter
  redirected files.
- Keep package ownership explicit across the focused `agent-*` repositories.
- Use concise grammatical prose; do not replace clarity with fragments.
- Keep installation offline and package-owned. No remote script, repository
  clone, marketplace, or runtime dependency may enter the init path silently.
- Keep target-manifest safety explicit.

## Hook Changes

Codex hook output must be checked against both the current schema and parser
semantics. In particular, `PreToolUse` pass-through returns no artificial
permission decision and no rewritten input; a denial uses a non-empty reason and
continues hook processing.

Keep hook entrypoints thin. Put behavior in typed PHP under `src/` so PHPUnit and
PHPStan can test the same logic the hook executes.

## Dogfood

For every behavior change:

1. choose a real bounded task or hook case;
2. keep baseline and candidate inputs equivalent;
3. change one mechanism at a time;
4. measure observable artifacts;
5. rerun the same case after every fix;
6. record failures, not only the final green result.

Do not claim saved reasoning tokens or counterfactual code size without actual
telemetry and a valid baseline.

## Hard Constraints

When a reviewed lesson is statically verifiable, prefer the smallest executable
constraint that protects a real property. Register it, test failing and accepted
examples, and baseline only verified legacy violations. Do not convert
subjective style preferences into noisy PHPStan rules.

## Validation

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
composer dogfood:discipline
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer ci
```

The clean installed-consumer CI scenario is required when package assets or
`install-assets` change. Never report a command as passed unless its exit code
was observed.
