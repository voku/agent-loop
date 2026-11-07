#!/usr/bin/env bash
# Exercise the real checked-out CLI against independent project roots.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
evidence="${1:-$root/build/init-recommend-dogfood}"
mkdir -p "$evidence"
evidence="$(cd "$evidence" && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

head="$(git -C "$root" rev-parse HEAD)"
if [[ -n "${EXPECTED_HEAD_SHA:-}" && "$head" != "$EXPECTED_HEAD_SHA" ]]; then
  echo "FAIL: checked-out commit differs from expected PR head" >&2
  exit 1
fi

# A. Existing user policy + Slop ignores + PHPStan paths + hostile Finder.
mkdir -p "$work/phpstan/src" "$work/phpstan/tests" "$work/phpstan/.agent-loop"
cat > "$work/phpstan/src/Example.php" <<'PHP'
<?php
declare(strict_types=1);
namespace SetupDogfood;
final readonly class Example
{
    public function value(): string
    {
        return 'ok';
    }
}
PHP
cat > "$work/phpstan/phpstan.neon" <<'NEON'
parameters:
  paths:
    - src
    - tests
  excludePaths:
    analyse:
      - tests/fixtures/*
NEON
printf '{"version":1,"prompting":{"language":"de-DE"}}\n' > "$work/phpstan/.agent-loop/init.json"
printf '{"ignores":["**/vendor/**","**/fixtures/**"]}\n' > "$work/phpstan/slop-scan.config.json"
cat > "$work/phpstan/.php-cs-fixer.dist.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/EXECUTED_UNEXPECTEDLY', 'bad');
return [];
PHP
(cd "$work/phpstan" && php "$root/bin/agent-loop" init recommend --format=json > "$evidence/phpstan.json")
jq -e '
  .schema_version == 1
  and .map.paths == ["src","tests"]
  and .map.source == "phpstan.neon"
  and .exclusions.phpstan == ["tests/fixtures/*"]
  and .exclusions.slop == ["**/vendor/**","**/fixtures/**"]
  and .prompting.language == "de-de"
  and .sources.php_cs_fixer == ".php-cs-fixer.dist.php"
  and .review.human_attention_every_changes_required == 3
  and .review.configurable_by_setup == false
' "$evidence/phpstan.json" >/dev/null
test ! -e "$work/phpstan/EXECUTED_UNEXPECTEDLY"

# Real CLI: accepted single-field mutation only after a reviewed evidence SHA.
(cd "$work/phpstan" && php "$root/bin/agent-loop" init plan --format=json --language=fr-FR) > "$evidence/approved-language-plan.json"
review_sha="$(jq -er '.evidence_sha256' "$evidence/approved-language-plan.json")"
cp "$work/phpstan/.agent-loop/init.json" "$evidence/original-language-config.json"
(
  cd "$work/phpstan"
  php "$root/bin/agent-loop" init apply --language=fr-FR --evidence-sha256="$review_sha" --accept=prompting.language --dry-run > "$evidence/language-dry-run.json"
  cmp .agent-loop/init.json "$evidence/original-language-config.json"
  php "$root/bin/agent-loop" init apply --language=fr-FR --evidence-sha256="$review_sha" --accept=prompting.language --yes > "$evidence/language-applied.json"
  jq -e '.prompting.language == "fr-fr"' .agent-loop/init.json >/dev/null
  if php "$root/bin/agent-loop" init apply --language=de-DE --evidence-sha256="$review_sha" --accept=prompting.language --yes > "$evidence/stale-stdout.txt" 2> "$evidence/stale-stderr.txt"; then
    echo 'FAIL: stale reviewed evidence was accepted' >&2
    exit 1
  fi
)
jq -e '.status == "dry_run"' "$evidence/language-dry-run.json" >/dev/null
jq -e '.status == "applied"' "$evidence/language-applied.json" >/dev/null
test ! -s "$evidence/stale-stdout.txt"
test ! -e "$work/phpstan/EXECUTED_UNEXPECTEDLY"

# Install the *real* Slop Scan tooling in the disposable consumer using the
# existing owner command, then run the installed scanner. Composer is an
# explicit CI opt-in, never an implicit action of init recommend.
(
  cd "$work/phpstan"
  php "$root/bin/agent-loop" init sync-tools --dry-run > "$evidence/install-preview.txt"
  test ! -e tools/slop-scan/composer.json
  php "$root/bin/agent-loop" init sync-tools > "$evidence/install-tools.txt"
  test -f tools/slop-scan/composer.json
  test -f tools/slop-scan/composer.lock
  cmp tools/slop-scan/composer.lock "$root/resources/tools/slop-scan/composer.lock"
  test -f tools/slop-scan/slop-scan.php
  composer install --working-dir=tools/slop-scan --no-interaction --prefer-dist --no-scripts --no-plugins --no-progress > "$evidence/composer-install.txt"
  test -f tools/slop-scan/vendor/autoload.php
  php tools/slop-scan/slop-scan.php scan . --config-file slop-scan.config.json --ignore 'tools/**' --json > "$evidence/slop-scan.json"
  jq -e 'type == "object" and has("metadata") and has("summary") and has("files") and has("findings")' "$evidence/slop-scan.json" >/dev/null
)
test ! -e "$work/phpstan/EXECUTED_UNEXPECTEDLY"

# B. Incorrectly nested PHPStan scope must use Composer instead.
mkdir -p "$work/nested/src" "$work/nested/app"
printf 'parameters:\n  paths:\n    unexpected:\n      - src\n' > "$work/nested/phpstan.neon"
printf '{"name":"setup/dogfood","autoload":{"psr-4":{"Demo\\\\":"app/"}}}\n' > "$work/nested/composer.json"
(cd "$work/nested" && php "$root/bin/agent-loop" init recommend --format=json > "$evidence/nested.json")
jq -e '.map.paths == ["app"] and .map.source == "composer.json"' "$evidence/nested.json" >/dev/null

# C. Reject repo-controlled symlink to external file.
printf 'parameters:\n  paths:\n    - .\n' > "$work/outside.neon"
mkdir -p "$work/escape"
ln -s "$work/outside.neon" "$work/escape/phpstan.neon"
if (cd "$work/escape" && php "$root/bin/agent-loop" init recommend --format=json > "$evidence/symlink-stdout.txt" 2> "$evidence/symlink-stderr.txt"); then
  echo 'FAIL: external symlink was accepted' >&2
  exit 1
fi
grep -q 'Symlinked project config' "$evidence/symlink-stderr.txt"
test ! -s "$evidence/symlink-stdout.txt"

# D. Real repository: assess the configs under review.
(cd "$root" && php bin/agent-loop init recommend --format=json > "$evidence/self.json")
jq -e '.schema_version == 1 and (.map.paths | index("src") != null) and .sources.phpstan != null and .sources.slop == "slop-scan.config.json"' "$evidence/self.json" >/dev/null

# E. Real Composer-installed consumer, not merely the source checkout.
# This validates package asset inclusion and the ordinary default install path.
mkdir -p "$work/installed"
jq -n --arg source "$root" '{
  name: "setup/dogfood-consumer",
  "require-dev": {"voku/agent-loop": "dev-main"},
  repositories: [{
    type: "path",
    url: $source,
    options: {symlink: false, versions: {"voku/agent-loop": "dev-main"}}
  }],
  "minimum-stability": "dev",
  "prefer-stable": true,
  config: {"allow-plugins": false}
}' > "$work/installed/composer.json"
composer update --working-dir="$work/installed" --no-interaction --prefer-dist --no-scripts --no-plugins --no-progress > "$evidence/installed-consumer-composer.txt"
(
  cd "$work/installed"
  test -f vendor/voku/agent-loop/resources/tools/slop-scan/composer.json
  test -f vendor/voku/agent-loop/resources/tools/slop-scan/composer.lock
  cmp vendor/voku/agent-loop/src/Init/InitSyncToolsCommand.php "$root/src/Init/InitSyncToolsCommand.php"
  php vendor/bin/agent-loop init recommend --format=json > "$evidence/installed-consumer-recommend.json"
  cmp vendor/voku/agent-loop/src/Init/InitLanguageConfigWriter.php "$root/src/Init/InitLanguageConfigWriter.php"
  mkdir -p .agent-loop
  php vendor/bin/agent-loop init plan --format=json --language=de-DE > "$evidence/installed-language-plan.json"
  installed_evidence="$(jq -er '.evidence_sha256' "$evidence/installed-language-plan.json")"
  php vendor/bin/agent-loop init apply --language=de-DE --evidence-sha256="$installed_evidence" --accept=prompting.language --yes > "$evidence/installed-language-apply.json"
  jq -e '.version == 1 and .prompting.language == "de-de"' .agent-loop/init.json >/dev/null
  php vendor/bin/agent-loop init sync-tools --dry-run > "$evidence/installed-consumer-preview.txt"
  test ! -e tools/slop-scan/composer.json
  php vendor/bin/agent-loop init sync-tools > "$evidence/installed-consumer-tools.txt"
  test -f tools/slop-scan/composer.json
  cmp tools/slop-scan/composer.json vendor/voku/agent-loop/resources/tools/slop-scan/composer.json
  cmp tools/slop-scan/composer.lock vendor/voku/agent-loop/resources/tools/slop-scan/composer.lock
  test ! -e tools/slop-scan/vendor
)
jq -e '.schema_version == 1 and .sources.composer == "composer.json"' "$evidence/installed-consumer-recommend.json" >/dev/null

jq -n --arg head "$head" --arg workflow "${GITHUB_WORKFLOW:-local}" --arg run_id "${GITHUB_RUN_ID:-local}" '
{
  schema_version: 1,
  exact_head: $head,
  workflow: $workflow,
  run_id: $run_id,
  result: "passed",
  cases: [
    "phpstan-and-slop-isolation",
    "composer-fallback-for-nested-scope",
    "out-of-root-symlink-rejection",
    "actual-agent-loop-repository",
    "composer-installed-consumer-default-tool-installation"
  ]
}' > "$evidence/proof.json"

echo "PASS: project-aware init dogfood at exact HEAD $head"
