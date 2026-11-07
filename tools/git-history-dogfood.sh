#!/usr/bin/env bash
set -euo pipefail

# Validate real Git provenance commands in disposable repositories, not autonomous LLM behavior.
if [[ $# -ne 1 || -z "$1" ]]; then
    printf 'Usage: %s <report.json>\n' "$0" >&2
    exit 64
fi

report="$1"
workspace="$(mktemp -d)"
trap 'rm -rf -- "$workspace"' EXIT
repo="$workspace/repo"
shallow="$workspace/shallow"

git init --quiet --initial-branch=main "$repo"
git -C "$repo" config user.name 'History Dogfood'
git -C "$repo" config user.email 'history-dogfood@example.invalid'
mkdir -p "$repo/src"
cat > "$repo/src/LegacyAccessPolicy.php" <<'PHP'
<?php

declare(strict_types=1);

final class AccessPolicy
{
    public function canAccess(): bool
    {
        return true;
    }
}
PHP

git -C "$repo" add -- src/LegacyAccessPolicy.php
git -C "$repo" commit --quiet -m 'feat: default to authorized access'
base_sha="$(git -C "$repo" rev-parse HEAD)"

git -C "$repo" mv src/LegacyAccessPolicy.php src/AccessPolicy.php
git -C "$repo" commit --quiet -m 'refactor: rename access policy file'
rename_sha="$(git -C "$repo" rev-parse HEAD)"

sed -i 's/return true;/return false;/' "$repo/src/AccessPolicy.php"
git -C "$repo" add -- src/AccessPolicy.php
git -C "$repo" commit --quiet -m 'fix: accidentally invert access decision'
regression_sha="$(git -C "$repo" rev-parse HEAD)"

printf '\n// Unrelated comment (should not become blamed culprit).\n' >> "$repo/src/AccessPolicy.php"
git -C "$repo" add -- src/AccessPolicy.php
git -C "$repo" commit --quiet -m 'docs: annotate access policy'
head_sha="$(git -C "$repo" rev-parse HEAD)"

# One-file log must cross the rename and preserve the culprit.
mapfile -t history < <(git -C "$repo" --no-pager log --follow --max-count=15 --format='%H' -- src/AccessPolicy.php)
[[ ${#history[@]} -eq 4 ]]
[[ " ${history[*]} " == *" $base_sha "* ]]
[[ " ${history[*]} " == *" $rename_sha "* ]]
[[ " ${history[*]} " == *" $regression_sha "* ]]

# Validate the pre-rename path: the current filename is absent at the base revision.
rename_aware="$(git -C "$repo" --no-pager log --follow --find-renames --name-status --max-count=15 --format='%H' -- src/AccessPolicy.php)"
[[ "$rename_aware" == *$'R100\tsrc/LegacyAccessPolicy.php\tsrc/AccessPolicy.php'* ]]
[[ -z "$(git -C "$repo" --no-pager show "$base_sha" -- src/AccessPolicy.php)" ]]
historical_patch="$(git -C "$repo" --no-pager show "$base_sha" -- src/LegacyAccessPolicy.php)"
[[ "$historical_patch" == *'+        return true;'* ]]

line="$(grep -n 'return false;' "$repo/src/AccessPolicy.php" | cut -d: -f1)"
[[ "$line" =~ ^[0-9]+$ ]]
blamed_sha="$(git -C "$repo" --no-pager blame -l -L "$line,$line" -- src/AccessPolicy.php | cut -d' ' -f1)"
[[ "$blamed_sha" == "$regression_sha" ]]

before="$(git -C "$repo" show "${regression_sha}^:src/AccessPolicy.php")"
after="$(git -C "$repo" show "${regression_sha}:src/AccessPolicy.php")"
[[ "$before" == *'return true;'* ]]
[[ "$after" == *'return false;'* ]]
patch="$(git -C "$repo" --no-pager show --no-ext-diff --no-textconv --format=fuller --unified=3 "$regression_sha" -- src/AccessPolicy.php)"
[[ "$patch" == *'-        return true;'* ]]
[[ "$patch" == *'+        return false;'* ]]
stat="$(git -C "$repo" --no-pager show --stat --format=fuller "$regression_sha" -- src/AccessPolicy.php)"
[[ "$stat" == *'src/AccessPolicy.php'* ]]
pickaxe_sha="$(git -C "$repo" --no-pager log --max-count=15 --format='%H' -S'return false;' -- src/AccessPolicy.php)"
[[ "$pickaxe_sha" == "$regression_sha" ]]

# Enforce displayed patch budget with a visible truncation marker.
for ((i = 1; i <= 300; ++i)); do
    printf 'line %03d\n' "$i"
done > "$repo/src/LargeChange.txt"
git -C "$repo" add -- src/LargeChange.txt
git -C "$repo" commit --quiet -m 'test: large diff for bounded output'
large_sha="$(git -C "$repo" rev-parse HEAD)"
bounded="$(git -C "$repo" --no-pager show --no-ext-diff --no-textconv --format=fuller --unified=3 "$large_sha" -- src/LargeChange.txt | awk 'NR <= 200 { print; next } NR == 201 { print "[output truncated after 200 lines]" }')"
displayed_lines="$(printf '%s\n' "$bounded" | wc -l | tr -d ' ')"
[[ "$displayed_lines" -eq 201 ]]
[[ "${bounded##*$'\n'}" == '[output truncated after 200 lines]' ]]

# Verify that Git errors are not hidden by a successful awk pipeline.
if git -C "$repo" --no-pager show --no-ext-diff --no-textconv deadbeef -- src/AccessPolicy.php 2>/dev/null \
    | awk 'NR <= 200 { print; next } NR == 201 { print "[output truncated after 200 lines]" }' >/dev/null; then
    echo 'Invalid Git revision was hidden by the output limiter' >&2
    exit 1
fi

git clone --quiet --depth 1 "file://$repo" "$shallow"
[[ "$(git -C "$shallow" rev-parse --is-shallow-repository)" == 'true' ]]
[[ "$(git -C "$shallow" rev-list --count HEAD)" == '1' ]]
[[ "$(git -C "$repo" rev-parse --is-shallow-repository)" == 'false' ]]

mkdir -p "$(dirname "$report")"
jq -n \
    --arg head_sha "$head_sha" \
    --arg base_sha "$base_sha" \
    --arg rename_sha "$rename_sha" \
    --arg regression_sha "$regression_sha" \
    --arg blamed_sha "$blamed_sha" \
    --arg pickaxe_sha "$pickaxe_sha" \
    --arg large_sha "$large_sha" \
    --argjson inspected_commits "${#history[@]}" \
    --argjson displayed_lines "$displayed_lines" \
    '{
      schema_version: "1.0",
      result: "passed",
      fixture: {base_sha: $base_sha, rename_sha: $rename_sha, regression_sha: $regression_sha, head_sha: $head_sha, large_sha: $large_sha},
      history: {files_inspected: 1, commit_budget: 15, inspected_commits: $inspected_commits, rename_crossed: true, historical_path_verified: true, blamed_sha: $blamed_sha, source_before_after_verified: true, stat_verified: true, pickaxe_sha: $pickaxe_sha},
      patch: {displayed_lines: $displayed_lines, displayed_line_budget: 200, truncation_marker: true, git_error_propagated: true},
      shallow: {is_shallow: true, history_incomplete: true, automated_fetch: false},
      limitations: ["Proves native Git evidence mechanics in a controlled regression, not autonomous agent choice or causal accuracy on real defects."]
    }' > "$report"

printf 'History dogfood passed: regression=%s; blame=%s; rename=verified; truncated_lines=%s; shallow=true\n' "$regression_sha" "$blamed_sha" "$displayed_lines"
