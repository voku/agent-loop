#!/usr/bin/env bash
# Run one prepared arm with a real Claude Code host, headless, under the frozen envelope.
#
#   tools/learning-effect/run-arm.sh <out-dir> <model-id>
#
# Identical in every arm: model, task text, tool allowlist, disallowed web access,
# turn and wall-clock budget. Writes <out>/transcript.jsonl and <out>/run.json.
# A failed, timed-out or refused run is recorded too; never delete it, grade it.
# If the run stops at a genuine human decision (e.g. Contract approval in B/C), the
# operator makes it, appends one line to <out>/interventions.jsonl, and resumes with
#   claude -p --resume <session_id> "<operator message>" ... (same flags, appended transcript).
set -euo pipefail

out="$(cd "$1" && pwd)"
model="$2"
max_turns="${MAX_TURNS:-80}"
budget_seconds="${BUDGET_SECONDS:-2700}"

[[ -f "$out/manifest.json" && -f "$out/task.md" && -d "$out/repo" ]] || { echo "not a prepared arm: $out" >&2; exit 2; }
[[ ! -e "$out/transcript.jsonl" ]] || { echo "arm already ran: $out" >&2; exit 2; }
command -v claude >/dev/null || { echo "claude CLI not found" >&2; exit 2; }

started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
set +e
(
  cd "$out/repo"
  timeout "$budget_seconds" claude -p "$(cat "$out/task.md")" \
    --model "$model" \
    --output-format stream-json --verbose \
    --max-turns "$max_turns" \
    --permission-mode acceptEdits \
    --allowedTools 'Bash Read Edit Write Glob Grep' \
    --disallowedTools 'WebFetch WebSearch'
) > "$out/transcript.jsonl" 2> "$out/stderr.log"
exit_code=$?
set -e
finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

jq -n --arg model "$model" --arg started "$started" --arg finished "$finished" \
  --argjson exit "$exit_code" --argjson max_turns "$max_turns" --argjson budget "$budget_seconds" \
  --arg claude "$(claude --version 2>/dev/null || echo unknown)" \
  '{model: $model, claude_version: $claude, started_at: $started, finished_at: $finished,
    exit_code: $exit, timed_out: ($exit == 124), max_turns: $max_turns, budget_seconds: $budget}' \
  > "$out/run.json"
echo "arm finished with exit $exit_code: $out"
