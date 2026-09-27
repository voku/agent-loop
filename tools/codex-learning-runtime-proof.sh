#!/usr/bin/env bash
set -euo pipefail

task_id='GH-618-640-1'
actor='codex-runtime-proof-agent'
reviewer='codex-runtime-proof-reviewer'
loop="${GITHUB_WORKSPACE}/bin/agent-loop"
task_evidence="${PROOF_ROOT}/codex-subagent-authority-evidence.json"
upstream_dir="${EVIDENCE_ROOT}/codex-v0.157.0-source"
mkdir -p "${upstream_dir}"

# Discovery evidence comes from the exact Codex release installed by the
# runtime-proof workflow, not current main and not an agent-loop fixture.
codex features list > "${EVIDENCE_ROOT}/codex-features.txt"
grep -Eq '^multi_agent[[:space:]]+stable[[:space:]]+true$' "${EVIDENCE_ROOT}/codex-features.txt"
curl -fsSL \
  https://raw.githubusercontent.com/openai/codex/rust-v0.157.0/codex-rs/core/src/agent/role_tests.rs \
  > "${upstream_dir}/role_tests.rs"
curl -fsSL \
  https://raw.githubusercontent.com/openai/codex/rust-v0.157.0/codex-rs/core/src/agent/child_config.rs \
  > "${upstream_dir}/child_config.rs"

grep -Fq 'async fn apply_role_preserves_parent_sandbox_permissions()' "${upstream_dir}/role_tests.rs"
grep -Fq '"sandbox_mode",' "${upstream_dir}/role_tests.rs"
grep -Fq '"role must not control {key}"' "${upstream_dir}/role_tests.rs"
grep -Fq 'set_permission_profile_from_session_snapshot' "${upstream_dir}/child_config.rs"

projected_read_only_count="$(grep -l '^sandbox_mode = "read-only"$' "${PROOF_ROOT}"/.codex/agents/*.toml | wc -l | tr -d ' ')"
test "${projected_read_only_count}" -gt 0

jq -n \
  --arg codex_version "$(codex --version)" \
  --arg upstream_tag 'rust-v0.157.0' \
  --argjson projected_read_only_count "${projected_read_only_count}" \
  --arg role_tests_sha256 "sha256:$(sha256sum "${upstream_dir}/role_tests.rs" | cut -d' ' -f1)" \
  --arg child_config_sha256 "sha256:$(sha256sum "${upstream_dir}/child_config.rs" | cut -d' ' -f1)" \
  '{
    schema_version: "1.0",
    source_issue: "voku/agent-loop#618",
    codex_version: $codex_version,
    upstream_tag: $upstream_tag,
    discovery: {
      projected_read_only_role_count: $projected_read_only_count,
      multi_agent_feature: {stability: "stable", enabled: true},
      role_preserves_parent_sandbox_permissions: true,
      role_sandbox_mode_is_non_controlling: true,
      child_reapplies_parent_permission_profile: true
    },
    source_digests: {
      role_tests: $role_tests_sha256,
      child_config: $child_config_sha256
    },
    conclusion: "Codex 0.157.0 role sandbox_mode=read-only is projection intent, not proof of child read-only enforcement; child runtime authority follows the parent permission profile."
  }' > "${EVIDENCE_ROOT}/codex-subagent-authority-evidence.json"

(
  cd "${PROOF_ROOT}"

  "${loop}" workflow plan "${task_id}" \
    --by "${actor}" \
    --file codex-subagent-authority-evidence.json \
    --goal 'Revalidate whether Codex 0.157.0 projected read-only subagent roles establish child read-only enforcement.' \
    --non-goal 'Do not invent a Finding when the exact runtime/release evidence does not establish a reusable lesson.' \
    --acceptance 'Record the exact Codex version and release evidence.' \
    --acceptance 'Reach the Learning decision through machine-readable finish JSON.' \
    --acceptance 'Capture a Finding only when the discovery evidence contradicts the enforcement claim.' \
    --validation 'test -s codex-subagent-authority-evidence.json'
  "${loop}" workflow approve "${task_id}" --by ci-approval-fixture
  "${loop}" enter "${task_id}" --format=json > "${EVIDENCE_ROOT}/learning-enter.json"

  cp "${EVIDENCE_ROOT}/codex-subagent-authority-evidence.json" "${task_evidence}"
  test -s codex-subagent-authority-evidence.json
  "${loop}" session validation record "${task_id}" \
    --contract-revision 1 \
    --command 'test -s codex-subagent-authority-evidence.json' \
    --status passed \
    --exit-code 0 \
    --duration-ms 0 \
    --by "${actor}"

  if ! "${loop}" review blindspots "${task_id}"; then
    "${loop}" session checkpoint "${task_id}" \
      --title 'Codex authority discovery evidence' \
      --body 'Exact Codex 0.157.0 release evidence and the generated authority conclusion were inspected; no synthetic Finding or human-only close gate was introduced.'
    "${loop}" review blindspots "${task_id}"
  fi

  "${loop}" recall log-outcome \
    --root .agent-loop/learning \
    --draft ".agent-loop/recall/${task_id}/recall-log.draft.json" \
    --by "${actor}" \
    --commit "${GITHUB_SHA}"

  "${loop}" workflow status "${task_id}" --format=json > "${EVIDENCE_ROOT}/learning-before-finish.json"
  review_digest="$(jq -r '.manifest.references.review.source.sha256 // empty' "${EVIDENCE_ROOT}/learning-before-finish.json")"
  test -n "${review_digest}"

  set +e
  "${loop}" finish "${task_id}" \
    --format=json \
    --reviewed-report-sha256 "${review_digest}" \
    --by "${actor}" \
    > "${EVIDENCE_ROOT}/learning-decision-gate.json"
  gate_exit=$?
  set -e
  test "${gate_exit}" -eq 1

  jq -e '.complete == false' "${EVIDENCE_ROOT}/learning-decision-gate.json" >/dev/null
  jq -e '.manifest.references.verification.gate == "learning_decision"' "${EVIDENCE_ROOT}/learning-decision-gate.json" >/dev/null
  jq -e '.manifest.references.verification.learning_disposition.evaluate_reusable_learning == true' "${EVIDENCE_ROOT}/learning-decision-gate.json" >/dev/null
  jq -e '.next_action_kind == "command_template"' "${EVIDENCE_ROOT}/learning-decision-gate.json" >/dev/null
  jq -e '.next_action_invocation.executable == "agent-loop"' "${EVIDENCE_ROOT}/learning-decision-gate.json" >/dev/null
  jq -e '.next_action_invocation.template == true' "${EVIDENCE_ROOT}/learning-decision-gate.json" >/dev/null
  jq -e '(.next_action_invocation.arguments | index("--learning")) != null and (.next_action_invocation.arguments | index("--finding")) != null' "${EVIDENCE_ROOT}/learning-decision-gate.json" >/dev/null

  # The Learning reflection is driven by discovered machine evidence. If this
  # predicate stops being true, fail instead of manufacturing a Finding for #640.
  jq -e '
    .discovery.projected_read_only_role_count > 0
    and .discovery.multi_agent_feature.enabled == true
    and .discovery.role_preserves_parent_sandbox_permissions == true
    and .discovery.role_sandbox_mode_is_non_controlling == true
    and .discovery.child_reapplies_parent_permission_profile == true
  ' codex-subagent-authority-evidence.json >/dev/null

  session_id="$(jq -r '.manifest.references.session.session_id // empty' "${EVIDENCE_ROOT}/learning-decision-gate.json")"
  test -n "${session_id}"

  "${loop}" learn capture \
    --task "${task_id}" \
    --session "${session_id}" \
    --by "${actor}" \
    --scope .codex/agents \
    --scope src/Init/HostCapabilityMatrix.php \
    --confidence high \
    --observation 'Codex 0.157.0 projected sandbox_mode=read-only roles do not establish child read-only enforcement: the exact release preserves parent sandbox permissions, excludes sandbox_mode from role-controlled authority, and reapplies the parent permission profile to children.' \
    --hypothesis 'Agent-loop must distinguish Codex read-only role projection from child sandbox enforcement and require separate host-runtime evidence before claiming enforcement.' \
    --evidence 'Observed from the installed codex-cli 0.157.0 feature surface plus exact rust-v0.157.0 role_tests.rs and child_config.rs; see codex-subagent-authority-evidence.json and recorded source digests.' \
    > "${EVIDENCE_ROOT}/learning-finding-capture.json"

  finding_id="$(jq -r '.id // empty' "${EVIDENCE_ROOT}/learning-finding-capture.json")"
  test -n "${finding_id}"
  jq -e '.status == "candidate" and .validation_status == "unverified"' "${EVIDENCE_ROOT}/learning-finding-capture.json" >/dev/null

  "${loop}" learn finding-transition "${finding_id}" validated \
    --by "${reviewer}" \
    --conclusion 'Exact Codex 0.157.0 release evidence reproduces the authority boundary: role sandbox_mode does not replace the parent permission profile.' \
    > "${EVIDENCE_ROOT}/learning-finding-validated.json"
  jq -e '.status == "validated" and .validation_status == "validated"' "${EVIDENCE_ROOT}/learning-finding-validated.json" >/dev/null

  "${loop}" finish "${task_id}" \
    --format=json \
    --learning findings_recorded \
    --finding "${finding_id}" \
    --by "${actor}" \
    > "${EVIDENCE_ROOT}/learning-complete.json"

  jq -e '.complete == true and .next_action == "none"' "${EVIDENCE_ROOT}/learning-complete.json" >/dev/null
  jq -e '.manifest.references.learning.state == "decided"' "${EVIDENCE_ROOT}/learning-complete.json" >/dev/null
  jq -e '.manifest.references.learning.decision == "findings_recorded"' "${EVIDENCE_ROOT}/learning-complete.json" >/dev/null
  decision_path="$(jq -r '.manifest.references.learning.source.path // empty' "${EVIDENCE_ROOT}/learning-complete.json")"
  test -n "${decision_path}"
  jq -e --arg finding_id "${finding_id}" '.finding_ids | index($finding_id) != null' "${decision_path}" >/dev/null

  cp ".agent-loop/learning/findings/validated/${finding_id}.json" "${EVIDENCE_ROOT}/${finding_id}.json"
)
