#!/usr/bin/env bash
# Run one prepared arm with a real Claude Code host, headless, under the frozen envelope.
#
#   tools/learning-effect/run-arm.sh <out-dir> <model-id>
#
# Identical in every arm: model, task text, tool allowlist, disallowed web access,
# turn and wall-clock budget, and the Bash sandbox in sandbox-settings.json (no
# outbound network for Bash and its children; the Claude Code process itself still
# reaches the model). Before the arm, a probe session under the same settings must
# fail to reach github.com, or the arm is not run. Writes <out>/network-probe.jsonl,
# <out>/transcript.jsonl and <out>/run.json.
# The Read/Glob/Grep tools are not network-sandboxed and can read the whole disk: run
# each arm in a disposable container holding only <out>, never next to the case clones.
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
for dependency in bwrap socat jq; do
  command -v "$dependency" >/dev/null || { echo "$dependency is required for the Bash sandbox and run record" >&2; exit 2; }
done
settings="$(cd "$(dirname "$0")" && pwd)/sandbox-settings.json"

# Fail closed unless the sandbox demonstrably blocks outbound network from Bash.
(
  cd "$out/repo"
  timeout 300 claude -p 'Run exactly this one Bash command and report its output verbatim: curl -sS -m 10 -o /dev/null -w "HTTP=%{http_code}" https://github.com; echo " EXIT=$?"' \
    --model "$model" --settings "$settings" --output-format stream-json --verbose \
    --max-turns 3 --permission-mode acceptEdits --allowedTools 'Bash' --disallowedTools 'WebFetch WebSearch'
) > "$out/network-probe.jsonl" 2>&1 || true
# Judge only what the Bash tool returned, never the prompt or the model's prose.
probe_results="$(jq -r 'select(.type == "user") | .message.content[]? | select(.type == "tool_result") | (.content | if type == "string" then . else (map(.text? // "") | join("\n")) end)' "$out/network-probe.jsonl" 2>/dev/null || true)"
printf '%s\n' "$probe_results" > "$out/network-probe-results.txt"
if ! grep -q 'EXIT=[0-9]' <<<"$probe_results" || grep -Eq 'EXIT=0([^0-9]|$)|HTTP=[23][0-9][0-9]' <<<"$probe_results"; then
  echo "network isolation not demonstrated; see $out/network-probe.jsonl. Arm not run." >&2
  exit 3
fi

started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
set +e
(
  cd "$out/repo"
  timeout "$budget_seconds" claude -p "$(cat "$out/task.md")" \
    --model "$model" \
    --settings "$settings" \
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
  --arg settings_sha256 "$(sha256sum "$settings" | cut -d' ' -f1)" \
  '{model: $model, claude_version: $claude, sandbox_settings_sha256: $settings_sha256, network_isolation: "probe_blocked", started_at: $started, finished_at: $finished,
    exit_code: $exit, timed_out: ($exit == 124), max_turns: $max_turns, budget_seconds: $budget}' \
  > "$out/run.json"
echo "arm finished with exit $exit_code: $out"
