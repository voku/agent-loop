#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
RESULT_ROOT="${ROOT}/build/gh663-real-host"
MODEL="claude-sonnet-4.5"
WORKTREES=()
TASK_A_BASE="10bef9759fa8d6b0c09773781d458cd3837a5b6d"
TASK_B_BASE="17ca145c7937de66aea8779412265e32e42c0682"
CURRENT_SKILL="${ROOT}/resources/skills/agent-loop-discipline/SKILL.md"

rm -rf "${RESULT_ROOT}"
mkdir -p "${RESULT_ROOT}"

cleanup() {
    local dir
    for dir in "${WORKTREES[@]:-}"; do
        [[ -n "${dir}" ]] || continue
        git worktree remove --force "${dir}" >/dev/null 2>&1 || true
    done
    git worktree prune >/dev/null 2>&1 || true
}
trap cleanup EXIT
CURRENT_BODY="${RESULT_ROOT}/discipline-current.md"
MINIMAL_BODY="${RESULT_ROOT}/discipline-minimal.md"

python3 - "${CURRENT_SKILL}" "${CURRENT_BODY}" "${MINIMAL_BODY}" <<'PY'
from pathlib import Path
import re
import sys

source = Path(sys.argv[1]).read_text()
current_path = Path(sys.argv[2])
minimal_path = Path(sys.argv[3])

body = re.sub(r"\A---\r?\n.*?\r?\n---\r?\n", "", source, count=1, flags=re.S).lstrip()
if body == source or not body.startswith("# Agent Loop Discipline"):
    raise SystemExit("unable to strip discipline frontmatter")

keep = {"Governed Workflow", "Workflow Evidence Integrity", "Workflow Output"}
out = []
active = True
seen = set()
for line in body.splitlines(keepends=True):
    if line.startswith("## "):
        heading = line[3:].strip()
        active = heading in keep
        if active:
            seen.add(heading)
    if active:
        out.append(line)

minimal = "".join(out)
if seen != keep:
    raise SystemExit(f"minimal arm missed headings: {sorted(keep - seen)}")
for removed in (
    "Agent I/O",
    "Prompt Controls",
    "Navigate Before Editing",
    "L2 Execution Contract",
    "Engineering Skill Routing",
    "Role Routing",
    "Hook Boundary",
    "Validation And Close",
):
    if f"## {removed}" in minimal:
        raise SystemExit(f"minimal arm retained removed section: {removed}")

current_path.write_text(body)
minimal_path.write_text(minimal)
PY

sha256sum "${CURRENT_BODY}" "${MINIMAL_BODY}"
wc -c -l "${CURRENT_BODY}" "${MINIMAL_BODY}"

select_model() {
    local home="${RUNNER_TEMP}/gh663-copilot-model-probe"
    mkdir -p "${home}"

    export MODEL
    echo "Probing fixed Copilot model: ${MODEL}"
    set +e
    COPILOT_HOME="${home}" \
    COPILOT_AUTO_UPDATE=false \
    GITHUB_TOKEN="${GITHUB_TOKEN}" \
    timeout 2m copilot \
      -p "Reply with exactly OK." \
      --model "${MODEL}" \
      --no-ask-user \
      --allow-tool=read \
      --deny-tool=write \
      --deny-tool=shell \
      --deny-tool=memory \
      --no-remote \
      --no-remote-export \
      >"${home}/probe.out" 2>"${home}/probe.err"
    local probe_exit=$?
    set -e

    cat "${home}/probe.out"
    cat "${home}/probe.err" >&2
    if [[ "${probe_exit}" -ne 0 ]]; then
        echo "Fixed Copilot model probe failed: ${MODEL}" >&2
        return "${probe_exit}"
    fi
    echo "Selected fixed Copilot model: ${MODEL}"
}
select_model

TASK_A_PROMPT="$(cat <<'EOF'
Current CI has one deterministic failure:
StaleVerificationConvergenceTest::testAmendedImplementationConvergesThroughAFreshExactHeadReceipt

Following the canonical action emits the exact same executable
agent-loop finish STALE-399 --reviewed-report-sha256 ... --by <actor>
again without lifecycle progress.

Find the smallest correct repair. Do not weaken the convergence assertion.
EOF
)"

TASK_B_PROMPT="$(cat <<'EOF'
At this frozen repository state, the self-shape runner cannot reliably run twice over its own durable state.
The workflow refusals are correct and must not be weakened.
Find the smallest correct repair, identify the behavior owner, and name the regression/evidence that would falsify your preferred explanation.
EOF
)"

setup_home() {
    local home="$1"
    local discipline="$2"

    mkdir -p "${home}/hooks"
    cp "${discipline}" "${home}/discipline.txt"

    cat > "${home}/gh663-hook.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
input="$(cat)"
printf '%s\n' "$input" > "${COPILOT_HOME}/session-start-input.json"
sha256sum "${COPILOT_HOME}/discipline.txt" > "${COPILOT_HOME}/hook-fired.txt"
jq -Rs -c '{additionalContext: .}' "${COPILOT_HOME}/discipline.txt"
EOF
    chmod +x "${home}/gh663-hook.sh"

    cat > "${home}/hooks/gh663.json" <<'EOF'
{
  "version": 1,
  "hooks": {
    "sessionStart": [
      {
        "type": "command",
        "bash": "bash \"$COPILOT_HOME/gh663-hook.sh\"",
        "timeoutSec": 10
      }
    ]
  }
}
EOF
}

prepare_worktree() {
    local dir="$1"
    local base="$2"

    git worktree add --detach "${dir}" "${base}"
    WORKTREES+=("${dir}")
    (
        cd "${dir}"
        composer install --no-interaction --prefer-dist --no-progress
    )
}

write_receipt() {
    local out="$1"
    local task="$2"
    local arm="$3"
    local base="$4"
    local order="$5"
    local prompt="$6"
    local worktree="$7"
    local home="$8"
    local discipline="$9"
    local exit_code="${10}"
    local started_at="${11}"
    local finished_at="${12}"

    local cli_version prompt_sha router_state router_sha task_authority_path task_authority_sha discipline_sha discipline_bytes final_sha final_bytes transcript_sha transcript_bytes diff_sha hook_fired hook_input_sha
    cli_version="$(copilot --version | head -n 1)"
    prompt_sha="$(printf '%s' "${prompt}" | sha256sum | cut -d' ' -f1)"
    router_state="absent"
    router_sha=""
    if [[ -f "${worktree}/AGENTS.md" ]]; then
        router_state="present"
        router_sha="$(sha256sum "${worktree}/AGENTS.md" | cut -d' ' -f1)"
    fi
    task_authority_path=""
    task_authority_sha=""
    if [[ "${task}" == "task-b" ]]; then
        task_authority_path="docs/agents/dogfood/self-shaping.md"
        task_authority_sha="$(sha256sum "${worktree}/${task_authority_path}" | cut -d' ' -f1)"
    fi
    discipline_sha="$(sha256sum "${discipline}" | cut -d' ' -f1)"
    discipline_bytes="$(wc -c < "${discipline}" | tr -d ' ')"
    final_sha="$(sha256sum "${out}/final.txt" | cut -d' ' -f1)"
    final_bytes="$(wc -c < "${out}/final.txt" | tr -d ' ')"
    transcript_sha="$(sha256sum "${out}/transcript.md" | cut -d' ' -f1)"
    transcript_bytes="$(wc -c < "${out}/transcript.md" | tr -d ' ')"
    diff_sha="$(sha256sum "${out}/task.diff" | cut -d' ' -f1)"
    hook_fired=false
    hook_input_sha=""
    if [[ -s "${home}/hook-fired.txt" ]] && [[ -s "${home}/session-start-input.json" ]]; then
        hook_fired=true
        hook_input_sha="$(sha256sum "${home}/session-start-input.json" | cut -d' ' -f1)"
    fi

    jq -n \
      --arg schema_version "1.0" \
      --arg issue "663" \
      --arg task "${task}" \
      --arg arm "${arm}" \
      --arg base_sha "${base}" \
      --arg pair_order "${order}" \
      --arg model_request "${MODEL}" \
      --arg cli_version "${cli_version}" \
      --arg prompt_sha256 "${prompt_sha}" \
      --arg router_state "${router_state}" \
      --arg router_sha256 "${router_sha}" \
      --arg task_authority_path "${task_authority_path}" \
      --arg task_authority_sha256 "${task_authority_sha}" \
      --arg discipline_sha256 "${discipline_sha}" \
      --argjson discipline_bytes "${discipline_bytes}" \
      --argjson exit_code "${exit_code}" \
      --arg started_at "${started_at}" \
      --arg finished_at "${finished_at}" \
      --arg final_sha256 "${final_sha}" \
      --argjson final_bytes "${final_bytes}" \
      --arg transcript_sha256 "${transcript_sha}" \
      --argjson transcript_bytes "${transcript_bytes}" \
      --arg task_diff_sha256 "${diff_sha}" \
      --argjson hook_fired "${hook_fired}" \
      --arg hook_input_sha256 "${hook_input_sha}" \
      '{
        schema_version: $schema_version,
        issue: $issue,
        task: $task,
        arm: $arm,
        base_sha: $base_sha,
        pair_order: $pair_order,
        host: "github-copilot-cli",
        model_request: $model_request,
        cli_version: $cli_version,
        prompt_sha256: $prompt_sha256,
        router: {
          path: "AGENTS.md",
          state: $router_state,
          sha256: (if $router_sha256 == "" then null else $router_sha256 end)
        },
        task_authority: {
          path: (if $task_authority_path == "" then null else $task_authority_path end),
          sha256: (if $task_authority_sha256 == "" then null else $task_authority_sha256 end)
        },
        discipline: {sha256: $discipline_sha256, bytes: $discipline_bytes},
        session_start_hook: {fired: $hook_fired, input_sha256: $hook_input_sha256},
        exit_code: $exit_code,
        started_at: $started_at,
        finished_at: $finished_at,
        final: {sha256: $final_sha256, bytes: $final_bytes},
        transcript: {sha256: $transcript_sha256, bytes: $transcript_bytes},
        task_diff_sha256: $task_diff_sha256
      }' > "${out}/receipt.json"
}

run_case() {
    local task="$1"
    local arm="$2"
    local base="$3"
    local order="$4"
    local prompt="$5"
    local discipline="$6"

    local slug="${task}-${arm}"
    local worktree="${RUNNER_TEMP}/gh663-${slug}"
    local home="${RUNNER_TEMP}/gh663-copilot-home-${slug}"
    local out="${RESULT_ROOT}/${slug}"

    mkdir -p "${out}"
    prepare_worktree "${worktree}" "${base}"
    setup_home "${home}" "${discipline}"

    if [[ "${task}" == "task-b" ]] && [[ ! -f "${worktree}/docs/agents/dogfood/self-shaping.md" ]]; then
        echo "Task B authority file is missing at frozen base" >&2
        return 1
    fi

    local started_at finished_at exit_code
    started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    set +e
    (
        cd "${worktree}"
        COPILOT_HOME="${home}" \
        COPILOT_AUTO_UPDATE=false \
        GITHUB_TOKEN="${GITHUB_TOKEN}" \
        timeout 12m copilot \
          -p "${prompt}" \
          --model "${MODEL}" \
          --no-ask-user \
          --allow-tool=read \
          --allow-tool=write \
          --allow-tool=shell \
          --deny-tool=memory \
          --deny-tool='shell(git push)' \
          --deny-tool='shell(gh pr create)' \
          --deny-tool='shell(gh pr merge)' \
          --no-remote \
          --no-remote-export \
          --share="${out}/transcript.md" \
          -s
    ) > "${out}/final.txt" 2> "${out}/stderr.txt"
    exit_code=$?
    set -e

    finished_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    if [[ ! -s "${home}/hook-fired.txt" ]] || [[ ! -s "${home}/session-start-input.json" ]]; then
        echo "SessionStart hook did not produce evidence for ${slug}" >&2
        if [[ "${exit_code}" -eq 0 ]]; then
            exit_code=97
        fi
    fi

    (
        cd "${worktree}"
        git diff --binary > "${out}/task.diff"
        git status --porcelain=v1 > "${out}/git-status.txt"
    )

    if [[ ! -f "${out}/transcript.md" ]]; then
        : > "${out}/transcript.md"
    fi

    write_receipt "${out}" "${task}" "${arm}" "${base}" "${order}" "${prompt}" "${worktree}" "${home}" "${discipline}" "${exit_code}" "${started_at}" "${finished_at}"

    jq . "${out}/receipt.json"
    echo "--- final ${slug} ---"
    cat "${out}/final.txt"
    echo "--- stderr ${slug} ---"
    cat "${out}/stderr.txt"

    if [[ "${exit_code}" -ne 0 ]]; then
        return "${exit_code}"
    fi
}

run_case "task-a" "current" "${TASK_A_BASE}" "1/2" "${TASK_A_PROMPT}" "${CURRENT_BODY}"
run_case "task-a" "minimal" "${TASK_A_BASE}" "2/2" "${TASK_A_PROMPT}" "${MINIMAL_BODY}"
run_case "task-b" "minimal" "${TASK_B_BASE}" "1/2" "${TASK_B_PROMPT}" "${MINIMAL_BODY}"
run_case "task-b" "current" "${TASK_B_BASE}" "2/2" "${TASK_B_PROMPT}" "${CURRENT_BODY}"

jq -s '.' \
  "${RESULT_ROOT}/task-a-current/receipt.json" \
  "${RESULT_ROOT}/task-a-minimal/receipt.json" \
  "${RESULT_ROOT}/task-b-minimal/receipt.json" \
  "${RESULT_ROOT}/task-b-current/receipt.json" > "${RESULT_ROOT}/cohort.json"

jq -e '
  length == 4
  and all(.[]; .exit_code == 0)
  and (map(select(.task == "task-a") | .prompt_sha256) | unique | length == 1)
  and (map(select(.task == "task-b") | .prompt_sha256) | unique | length == 1)
  and (map(select(.task == "task-a") | .base_sha) | unique | length == 1)
  and (map(select(.task == "task-b") | .base_sha) | unique | length == 1)
  and (map(select(.task == "task-a") | .router) | unique | length == 1)
  and (map(select(.task == "task-b") | .router) | unique | length == 1)
  and (map(select(.task == "task-a") | .task_authority) | unique | length == 1)
  and (map(select(.task == "task-b") | .task_authority) | unique | length == 1)
  and (map(.model_request) | unique | length == 1)
  and (map(.cli_version) | unique | length == 1)
  and all(.[]; .session_start_hook.fired == true)
' "${RESULT_ROOT}/cohort.json" >/dev/null
