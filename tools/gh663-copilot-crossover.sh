#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
RESULT_ROOT="${ROOT}/build/gh663-real-host"
MODEL_POLICY="cli-default-1.0.91"
WORKTREES=()
TASK_A_BASE="10bef9759fa8d6b0c09773781d458cd3837a5b6d"
TASK_B_BASE="17ca145c7937de66aea8779412265e32e42c0682"
CURRENT_SKILL="${ROOT}/resources/skills/agent-loop-discipline/SKILL.md"
MODE="${1:-full-cohort}"

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

    echo "Probing Copilot CLI v1.0.91 documented default model policy"
    COPILOT_HOME="${home}" \
    COPILOT_AUTO_UPDATE=false \
    GITHUB_TOKEN="${GITHUB_TOKEN}" \
    timeout 2m copilot \
      -p "Reply with exactly OK." \
      --no-ask-user \
      --allow-tool=read \
      --deny-tool=write \
      --deny-tool=shell \
      --deny-tool=memory \
      --no-remote \
      --no-remote-export \
      >"${home}/probe.out" 2>"${home}/probe.err"

    cat "${home}/probe.out"
    cat "${home}/probe.err" >&2
    echo "Selected model policy: ${MODEL_POLICY}"
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

authority_classification() {
    local file="$1"
    local mode="$2"

    if [[ ! -s "${file}" ]]; then
        printf '%s' "unobserved"
        return
    fi

    local kind
    kind="$(jq -r '.manifest.next_action_kind // "unknown"' "${file}")"

    case "${kind}" in
        decision_required)
            printf '%s' "human_decision_required"
            ;;
        host_work)
            printf '%s' "host_mutation_authorized"
            ;;
        command|command_template)
            if [[ "${mode}" == "preapproved" ]]; then
                printf '%s' "preapproved_lifecycle_command"
            else
                printf '%s' "lifecycle_command_required"
            fi
            ;;
        none)
            printf '%s' "lifecycle_complete"
            ;;
        *)
            printf '%s' "unknown"
            ;;
    esac
}

capture_authority_state() {
    local worktree="$1"
    local out="$2"
    local phase="$3"

    (
        cd "${worktree}"
        if php bin/agent-loop workflow status STALE-399 --format=json > "${out}/authority-${phase}.json" 2> "${out}/authority-${phase}.stderr"; then
            :
        else
            printf '{"status":"unavailable"}\n' > "${out}/authority-${phase}.json"
        fi
    )
}

seed_task_a_preapproval() {
    local worktree="$1"
    local out="$2"

    (
        cd "${worktree}"
        {
            php bin/agent-loop map build --paths=src,tests
            php bin/agent-loop workflow plan STALE-399 \
              --by lars \
              --file src/Run/RunManifestProjector.php \
              --file tests/StaleVerificationConvergenceTest.php \
              --goal 'Repair the repeated reviewed-report finish action so amended implementation converges through a fresh exact-head receipt without weakening the assertion.' \
              --scope src/Run/RunManifestProjector.php \
              --scope tests/StaleVerificationConvergenceTest.php \
              --acceptance 'The canonical lifecycle action converges without repeating the same reviewed-report finish command.' \
              --validation 'vendor/bin/phpunit tests/StaleVerificationConvergenceTest.php'
            php bin/agent-loop workflow approve STALE-399 --by lars
        } > "${out}/preapproval.log" 2>&1
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
    local authority_mode="${13}"

    local cli_version prompt_sha router_state router_sha task_authority_path task_authority_sha discipline_sha discipline_bytes final_sha final_bytes transcript_sha transcript_bytes diff_sha hook_fired hook_input_sha dependency_lock_sha dependency_graph_sha authority_before_kind authority_before_action authority_before_class authority_after_kind authority_after_action authority_after_class
    cli_version="$(copilot --version | head -n 1)"
    prompt_sha="$(printf '%s' "${prompt}" | sha256sum | cut -d' ' -f1)"
    router_state="absent"
    router_sha=""
    if git -C "${ROOT}" cat-file -e "${base}:AGENTS.md" 2>/dev/null; then
        router_state="present"
        router_sha="$(git -C "${ROOT}" show "${base}:AGENTS.md" | sha256sum | cut -d' ' -f1)"
    fi
    task_authority_path=""
    task_authority_sha=""
    if [[ "${task}" == "task-b" ]]; then
        task_authority_path="docs/agents/dogfood/self-shaping.md"
        task_authority_sha="$(git -C "${ROOT}" show "${base}:${task_authority_path}" | sha256sum | cut -d' ' -f1)"
    fi
    discipline_sha="$(sha256sum "${discipline}" | cut -d' ' -f1)"
    dependency_lock_sha="$(sha256sum "${worktree}/composer.lock" | cut -d' ' -f1)"
    dependency_graph_sha="$(
        jq -S -c '[.packages[] | {name, version}] | sort_by(.name)' "${worktree}/vendor/composer/installed.json" |
          sha256sum | cut -d' ' -f1
    )"
    authority_before_kind="$(jq -r '.manifest.next_action_kind // null' "${out}/authority-before.json")"
    authority_before_action="$(jq -r '.manifest.next_action // null' "${out}/authority-before.json")"
    authority_before_class="$(authority_classification "${out}/authority-before.json" "${authority_mode}")"
    authority_after_kind="$(jq -r '.manifest.next_action_kind // null' "${out}/authority-after.json")"
    authority_after_action="$(jq -r '.manifest.next_action // null' "${out}/authority-after.json")"
    authority_after_class="$(authority_classification "${out}/authority-after.json" "${authority_mode}")"
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
      --arg model_policy "${MODEL_POLICY}" \
      --arg cli_version "${cli_version}" \
      --arg prompt_sha256 "${prompt_sha}" \
      --arg router_state "${router_state}" \
      --arg router_sha256 "${router_sha}" \
      --arg task_authority_path "${task_authority_path}" \
      --arg task_authority_sha256 "${task_authority_sha}" \
      --arg discipline_sha256 "${discipline_sha}" \
      --arg dependency_lock_sha256 "${dependency_lock_sha}" \
      --arg dependency_graph_sha256 "${dependency_graph_sha}" \
      --arg authority_mode "${authority_mode}" \
      --arg authority_before_kind "${authority_before_kind}" \
      --arg authority_before_action "${authority_before_action}" \
      --arg authority_before_class "${authority_before_class}" \
      --arg authority_after_kind "${authority_after_kind}" \
      --arg authority_after_action "${authority_after_action}" \
      --arg authority_after_class "${authority_after_class}" \
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
        model_policy: $model_policy,
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
        dependency_graph: {
          composer_lock_sha256: $dependency_lock_sha256,
          installed_packages_sha256: $dependency_graph_sha256
        },
        authority_state: {
          mode: $authority_mode,
          before: {
            next_action_kind: (if $authority_before_kind == "null" then null else $authority_before_kind end),
            next_action: (if $authority_before_action == "null" then null else $authority_before_action end),
            classification: $authority_before_class
          },
          after: {
            next_action_kind: (if $authority_after_kind == "null" then null else $authority_after_kind end),
            next_action: (if $authority_after_action == "null" then null else $authority_after_action end),
            classification: $authority_after_class
          }
        },
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
    local authority_mode="${7:-observe-only}"

    local slug="${task}-${arm}"
    local worktree="${RUNNER_TEMP}/gh663-${slug}"
    local home="${RUNNER_TEMP}/gh663-copilot-home-${slug}"
    local out="${RESULT_ROOT}/${slug}"

    mkdir -p "${out}"
    prepare_worktree "${worktree}" "${base}"
    setup_home "${home}" "${discipline}"

    if [[ "${authority_mode}" == "preapproved" ]]; then
        if [[ "${task}" != "task-a" ]]; then
            echo "preapproved mode is only valid for task-a" >&2
            return 96
        fi
        seed_task_a_preapproval "${worktree}" "${out}"
    fi
    capture_authority_state "${worktree}" "${out}" "before"

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
    capture_authority_state "${worktree}" "${out}" "after"

    if [[ ! -s "${home}/hook-fired.txt" ]] || [[ ! -s "${home}/session-start-input.json" ]]; then
        echo "SessionStart hook did not produce evidence for ${slug}" >&2
        if [[ "${exit_code}" -eq 0 ]]; then
            exit_code=97
        fi
    fi

    (
        cd "${worktree}"
        git diff --binary "${base}" > "${out}/task.diff"
        git status --porcelain=v1 > "${out}/git-status.txt"
    )

    if [[ ! -f "${out}/transcript.md" ]]; then
        : > "${out}/transcript.md"
    fi

    write_receipt "${out}" "${task}" "${arm}" "${base}" "${order}" "${prompt}" "${worktree}" "${home}" "${discipline}" "${exit_code}" "${started_at}" "${finished_at}" "${authority_mode}"

    jq . "${out}/receipt.json"
    echo "--- final ${slug} ---"
    cat "${out}/final.txt"
    echo "--- stderr ${slug} ---"
    cat "${out}/stderr.txt"

    if [[ "${exit_code}" -ne 0 ]]; then
        return "${exit_code}"
    fi
}

if [[ "${MODE}" == "preapproved-minimal-only" ]]; then
    run_case "task-a" "minimal-preapproved" "${TASK_A_BASE}" "2/2" "${TASK_A_PROMPT}" "${MINIMAL_BODY}" "preapproved"

    jq -s '.' \
      "${RESULT_ROOT}/task-a-minimal-preapproved/receipt.json" > "${RESULT_ROOT}/cohort.json"

    jq -e '
      length == 1
      and .[0].prompt_sha256 == "a155d72cf1de3342279358d0d9344f86794038f93cfcfbad061db3d61ba01f11"
      and .[0].base_sha == "10bef9759fa8d6b0c09773781d458cd3837a5b6d"
      and .[0].router.sha256 == "12c0746a3f516ccbd1cf09bdd62a917112b317c73d2253de9d93e4d02d313d33"
      and .[0].dependency_graph.composer_lock_sha256 == "25df05beb1641b93fdfc83687261b74fa62a420be1f26b31b38c2bdf7a149fda"
      and .[0].dependency_graph.installed_packages_sha256 == "45efa37a3c3811d0e6529e7b48f928883acffe675d6ba601af4188d3bd11a064"
      and .[0].authority_state.mode == "preapproved"
      and .[0].authority_state.before.next_action_kind == "command"
      and .[0].authority_state.before.next_action == "agent-loop enter STALE-399"
      and .[0].authority_state.before.classification == "preapproved_lifecycle_command"
      and .[0].model_policy == "cli-default-1.0.91"
      and .[0].cli_version == "GitHub Copilot CLI 1.0.91."
      and .[0].session_start_hook.fired == true
    ' "${RESULT_ROOT}/cohort.json" >/dev/null
elif [[ "${MODE}" == "preapproved-pair" ]]; then
    run_case "task-a" "current-preapproved" "${TASK_A_BASE}" "1/2" "${TASK_A_PROMPT}" "${CURRENT_BODY}" "preapproved"
    run_case "task-a" "minimal-preapproved" "${TASK_A_BASE}" "2/2" "${TASK_A_PROMPT}" "${MINIMAL_BODY}" "preapproved"

    jq -s '.' \
      "${RESULT_ROOT}/task-a-current-preapproved/receipt.json" \
      "${RESULT_ROOT}/task-a-minimal-preapproved/receipt.json" > "${RESULT_ROOT}/cohort.json"

    jq -e '
      length == 2
      and all(.[]; .exit_code == 0)
      and (map(.prompt_sha256) | unique | length == 1)
      and (map(.base_sha) | unique | length == 1)
      and (map(.router) | unique | length == 1)
      and (map(.dependency_graph.composer_lock_sha256) | unique | length == 1)
      and (map(.dependency_graph.installed_packages_sha256) | unique | length == 1)
      and all(.[];
        .authority_state.mode == "preapproved"
        and .authority_state.before.classification != "human_decision_required"
        and .authority_state.before.next_action_kind != null
      )
      and (map(.model_policy) | unique | length == 1)
      and (map(.cli_version) | unique | length == 1)
      and all(.[]; .session_start_hook.fired == true)
    ' "${RESULT_ROOT}/cohort.json" >/dev/null
else
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
      and (map(.model_policy) | unique | length == 1)
      and (map(.cli_version) | unique | length == 1)
      and all(.[]; .session_start_hook.fired == true)
    ' "${RESULT_ROOT}/cohort.json" >/dev/null
fi
