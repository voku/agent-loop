# Project-integrated PHPStan tooling

Some agent-facing tools can run from an isolated Composer project under `tools/`.
PHPStan extensions cannot: they must participate in the same root Composer graph and
PHPStan process as the repository being analysed.

## Recommended root installation

From the consumer repository root:

```bash
composer require --dev voku/phpstan-agent-format voku/phpstan-rules
vendor/bin/agent-loop init tools --refresh
```

`init tools` reports direct root Composer configuration. It deliberately does not
run Composer itself: dependency resolution can reach the network and modifies the
host project's `composer.json` / `composer.lock`.

Keep these packages in `require-dev`. They are development tooling, not runtime
application dependencies.

## PHPStan activation

If the project already uses `phpstan/extension-installer`, the packages' PHPStan
extension metadata may be loaded through that existing mechanism. Otherwise add
the extensions explicitly to the repository's PHPStan configuration:

```neon
includes:
    - vendor/voku/phpstan-agent-format/extension.neon
    - vendor/voku/phpstan-rules/rules.neon
```

Do not silently rewrite an existing PHPStan configuration merely because the
packages are present. `voku/phpstan-rules` changes which findings PHPStan reports,
so enabling it is an explicit project tooling decision.

## Agent-facing formatter

When `voku/phpstan-agent-format` is enabled, an agent-owned diagnostic or repair
loop can request the compact formatter:

```bash
vendor/bin/phpstan analyse --error-format=agent
```

The formatter clusters related findings, suppresses duplicate symptoms, and uses
a bounded agent-oriented representation. That makes it useful for navigation and
repair while spending fewer context tokens on repeated console output.

This compact representation is **not** a substitute for the repository's declared
validation evidence. If the approved Contract says to run `composer phpstan`,
`composer ci`, or another exact command, run that command unchanged before
recording validation. Agent-facing compression may guide the repair; authoritative
validation remains the exact project command and its observed result.

## Boundary with isolated evidence tools

Use isolated `tools/` Composer projects for evidence utilities such as `slop-scan`
when dependency isolation is useful or required. Use root Composer dependencies
for PHPStan extensions that must execute inside the analysed project's PHPStan
runtime. Putting `phpstan-agent-format` or `phpstan-rules` under an isolated
`tools/<name>/vendor/` would give them the wrong Composer/PHPStan world and defeat
the reason to install them.
