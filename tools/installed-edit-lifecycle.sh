#!/usr/bin/env bash
# Installed-consumer proof of the full governed lifecycle for plans executed by voku/agent-edit:
#
#   agent-map plan -> agent-loop edit refactor --dry-run -> apply (agent-edit preflight/apply) -> map rebuild
#   -> agent-loop edit refactor verify (agent-edit verify) -> validation/review/learning evidence -> finish
#
# Usage: tools/installed-edit-lifecycle.sh <method-rename|class-rename|class-move|method-move> <work-dir>
# Environment: LOOP (default vendor/bin/agent-loop) and MAP (default vendor/bin/agent-map), resolved inside <work-dir>.
set -euo pipefail

scenario="${1:?scenario required}"
root="${2:?work directory required}"
loop="${LOOP:-vendor/bin/agent-loop}"
map="${MAP:-vendor/bin/agent-map}"
task=DEMO-1
by=installed-edit-lifecycle

mkdir -p "$root/src" "$root/tests"
cd "$root"

php_file() { # <path> <namespace> <body>
  mkdir -p "$(dirname "$1")"
  printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\n%s\n' "$2" "$3" > "$1"
}

case "$scenario" in
  method-rename)
    plan_command=(rename-plan 'Fixture\Greeter::greet' welcome)
    plan_type=method_rename_plan
    verify_kind=rename_plan_verification
    runner=rename-plan
    goal='Rename Fixture\Greeter::greet to welcome through the released agent-map rename plan.'
    file=src/Greeter.php
    expected_changed='["src/Caller.php","src/Greeter.php"]'
    php_file src/Greeter.php 'Fixture' $'final class Greeter\n{\n    public function greet(string $name): string\n    {\n        return \'Hello \' . $name;\n    }\n}'
    php_file src/Caller.php 'Fixture' $'final class Caller\n{\n    public function run(Greeter $greeter): string\n    {\n        return $greeter->greet(\'Agent\');\n    }\n}'
    post_check() { grep -q 'function welcome' src/Greeter.php && grep -q -- '->welcome(' src/Caller.php; }
    ;;
  class-rename)
    plan_command=(class-rename-plan 'Fixture\Greeter' Welcomer)
    plan_type=class_rename_plan
    verify_kind=rename_plan_verification
    runner=rename-plan
    goal='Rename Fixture\Greeter to Welcomer, including its file move, through the released agent-map class rename plan.'
    file=src/Greeter.php
    expected_changed='["src/Caller.php","src/Greeter.php","src/Welcomer.php"]'
    php_file src/Greeter.php 'Fixture' $'final class Greeter\n{\n}'
    php_file src/Caller.php 'Fixture' $'final class Caller\n{\n    public function make(): Greeter\n    {\n        return new Greeter();\n    }\n}'
    post_check() { test -f src/Welcomer.php && test ! -f src/Greeter.php && grep -q 'new Welcomer()' src/Caller.php; }
    ;;
  class-move)
    plan_command=(class-move-plan 'Fixture\Legacy\Greeter' 'Fixture\Modern\Greeter')
    plan_type=class_move_plan
    verify_kind=class_move_plan_verification
    runner=class-move-plan
    goal='Move Fixture\Legacy\Greeter to Fixture\Modern\Greeter through the released agent-map class move plan.'
    file=src/Legacy/Greeter.php
    expected_changed='["src/Client/Caller.php","src/Legacy/Greeter.php","src/Modern/Greeter.php"]'
    php_file src/Legacy/Greeter.php 'Fixture\Legacy' $'final class Greeter\n{\n}'
    php_file src/Client/Caller.php 'Fixture\Client' $'use Fixture\\Legacy\\Greeter;\n\nfinal class Caller\n{\n    public function make(): Greeter\n    {\n        return new Greeter();\n    }\n}'
    post_check() { test -f src/Modern/Greeter.php && test ! -f src/Legacy/Greeter.php && grep -q 'use Fixture\\Modern\\Greeter;' src/Client/Caller.php; }
    ;;
  method-move)
    plan_command=(method-move-plan 'Fixture\Source::helper' 'Fixture\Target')
    plan_type=method_move_plan
    verify_kind=method_move_plan_verification
    runner=method-move-plan
    goal='Move the unused private Fixture\Source::helper method into Fixture\Target through the released agent-map method move plan.'
    file=src/Source.php
    expected_changed='["src/Source.php","src/Target.php"]'
    php_file src/Source.php 'Fixture' $'final class Source\n{\n    private static function helper(int $x): int\n    {\n        return $x + 1;\n    }\n}'
    php_file src/Target.php 'Fixture' $'final class Target\n{\n}'
    post_check() { grep -q 'function helper' src/Target.php && ! grep -q 'function helper' src/Source.php; }
    ;;
  *) echo "Unknown scenario: $scenario" >&2; exit 2 ;;
esac

# A class move needs unshadowed PSR-4 prefixes, otherwise Map correctly downgrades the plan to review_required.
if [ "$scenario" = class-move ]; then
  psr4='"Fixture\\Legacy\\": "src/Legacy/", "Fixture\\Modern\\": "src/Modern/", "Fixture\\Client\\": "src/Client/"'
else
  psr4='"Fixture\\": "src/"'
fi
if [ -n "${LOOP_REPO:-}" ]; then
  # Installed-consumer mode: agent-loop (path) and the released agent-map (path) are installed as a consuming project
  # would; voku/agent-edit resolves from Packagist through agent-loop's own `^0.1.0` requirement.
  jq -n \
    --argjson psr4 "{${psr4}}" \
    --arg loop "$LOOP_REPO" \
    --arg map "${MAP_REPO:?MAP_REPO required in installed mode}" \
    --arg map_version "${MAP_VERSION:?MAP_VERSION required in installed mode}" \
    '{
      name: "voku/installed-edit-lifecycle-consumer",
      type: "project",
      "require-dev": {"phpstan/phpstan": "^2.2", "voku/agent-loop": "dev-main"},
      repositories: [
        {type: "path", url: $loop, options: {symlink: false, versions: {"voku/agent-loop": "dev-main"}}},
        {type: "path", url: $map, options: {symlink: false, versions: {"voku/agent-map": $map_version}}}
      ],
      autoload: {"psr-4": $psr4},
      scripts: {test: "php tests/run.php"},
      "minimum-stability": "dev",
      "prefer-stable": true,
      config: {"allow-plugins": false, "sort-packages": true}
    }' > composer.json
  composer update --no-interaction --prefer-dist --no-progress --no-ansi
  composer show voku/agent-edit --format=json > resolved-agent-edit.json
  jq -e '(.versions | index("0.1.0")) != null' resolved-agent-edit.json >/dev/null
else
  cat > composer.json <<JSON
{
  "name": "voku/installed-edit-lifecycle-consumer",
  "type": "project",
  "autoload": {"psr-4": {$psr4}},
  "scripts": {"test": "php tests/run.php"}
}
JSON
fi
cat > tests/run.php <<'PHP'
<?php

declare(strict_types=1);

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (str_ends_with($file->getPathname(), '.php')) {
        exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('Syntax error after governed edit: ' . $file->getPathname());
        }
    }
}
PHP
printf '/vendor/\n/.agent-loop/\n/composer.lock\n/*.json.bak\n/enter.json\n/status.json\n/finish.json\n/stale.out\n/resolved-agent-edit.json\n' > .gitignore

git init -q --initial-branch=main
git config user.name 'Installed Edit Lifecycle'
git config user.email 'installed-edit-lifecycle@example.invalid'
git add -A
git commit -q -m "fixture: initial $scenario consumer"

base_commit="$(git rev-parse HEAD)"

$loop init scaffold --demo --agent=codex
$loop workflow plan "$task" \
  --by "$by" \
  --file "$file" \
  --base-commit "$base_commit" \
  --goal "$goal" \
  --behavior-anchor 'The edited code keeps parsing and the governed bundle proves the exact published plan was applied.' \
  --validation 'composer test' \
  --acceptance 'The plan was applied by agent-edit, verification proves current Map evidence, and PHP still parses.'
$loop workflow approve "$task" --by "$by"
$loop enter "$task" --format=json --max-lines=40 --max-bytes=4096 > enter.json
jq -e '.mutation_ready == true' enter.json >/dev/null

build_map() {
  $map build --root=. --paths=src --backend=phpstan --phpstan-memory-limit=512M --out=.agent-loop/map/php-symbols.json
}
build_map

bundle=".agent-loop/edit/$task"
mkdir -p "$bundle"
$map "${plan_command[@]}" --index=.agent-loop/map/php-symbols.json --format=json > "$bundle/plan.json"
jq -e --arg type "$plan_type" '
  (.type == $type) and (.contract_version == "1.0") and (.status == "safe")
  and (.stale_evidence == []) and (.blockers == [])
' "$bundle/plan.json" >/dev/null

snapshot() { find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum; }
before="$(snapshot)"
$loop edit refactor "$bundle/plan.json" --task="$task" --map-index=.agent-loop/map/php-symbols.json --map-root=. --output-dir="$bundle/dry-run" --dry-run
test "$(snapshot)" = "$before"
jq -e --arg runner "$runner" '(.status == "prepared") and (.runner.name == $runner) and (.runner.dry_run == true) and (.changed_files == [])' "$bundle/dry-run/execution.json" >/dev/null

# Fail-closed: a stale plan (source edited after planning) is refused and changes nothing.
cp "$file" "$file.bak"
printf '\n// edited after planning\n' >> "$file"
stale_before="$(snapshot)"
if $loop edit refactor "$bundle/plan.json" --task="$task" --map-index=.agent-loop/map/php-symbols.json --map-root=. --output-dir="$bundle/stale" >stale.out 2>&1; then
  echo 'A stale plan was applied.' >&2
  exit 1
fi
test "$(snapshot)" = "$stale_before"
mv "$file.bak" "$file"
test "$(snapshot)" = "$before"

$loop edit refactor "$bundle/plan.json" --task="$task" --map-index=.agent-loop/map/php-symbols.json --map-root=. --output-dir="$bundle"
post_check
jq -e --arg runner "$runner" --argjson changed "$expected_changed" '
  (.status == "runner_succeeded") and (.runner.name == $runner) and (.runner.dry_run == false)
  and ((.changed_files | sort) == ($changed | sort))
' "$bundle/execution.json" >/dev/null

build_map
$loop edit refactor verify --bundle="$bundle" --map-index=.agent-loop/map/php-symbols.json --map-root=.
jq -e --arg type "$plan_type" --arg kind "$verify_kind" '
  (.kind == $kind) and (.status == "passed") and (.plan.type == $type) and (.plan.contract_version == "1.0")
  and (.checks.execution_binding == "passed") and (.checks.current_map == "passed")
  and (.checks.changed_files == "passed")
' "$bundle/verification-result.json" >/dev/null

composer test
$loop session validation record "$task" --contract-revision 1 --command 'composer test' --status passed --exit-code 0 --duration-ms 0 --by "$by"

set +e
$loop review blindspots "$task"
review_rc=$?
set -e
if test "$review_rc" -ne 0 && test "$review_rc" -ne 1; then
  exit "$review_rc"
fi
$loop session checkpoint "$task" --title "Installed $scenario review" --body "Reviewed the $plan_type plan, the agent-edit receipt and verification, and validation evidence."
$loop review blindspots "$task"
$loop workflow learn "$task" --status no_durable_learning --by "$by" --reason "The installed $scenario proof added no reusable project-specific guidance."
$loop verify --task-id="$task"

$loop workflow status "$task" --format=json > status.json
review_sha="$(jq -er '.manifest.references.review.source.sha256' status.json)"
case "$review_sha" in sha256:*) ;; *) echo 'Missing exact review digest.' >&2; exit 1 ;; esac
$loop finish "$task" --reviewed-report-sha256 "$review_sha" --by "$by"
$loop finish "$task" --format=json > finish.json
jq -e '(.complete == true) and (.next_action == "none")' finish.json >/dev/null
echo "ok installed-edit-lifecycle $scenario ($plan_type@1.0)"
