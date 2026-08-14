#!/usr/bin/env bash
set -euo pipefail

task='SELF-SHAPE'
planner='agent-loop-self-shape'
learning_root='.agent-loop/learning'
# Resolved from the product after APPROVE: agent-loop owns where Recall output
# lives, so the harness must not assert a second, hardcoded location for it.
recall_root=''
base_ref="${GITHUB_BASE_REF:-main}"
goal="${SELF_SHAPE_GOAL:-Govern and validate the current agent-loop diff through agent-loop itself.}"
approval_actor="${SELF_SHAPE_APPROVER:-ci-self-shape-approval-fixture}"
pr_number="${SELF_SHAPE_PR_NUMBER:-}"

for argument in "$@"; do
  case "${argument}" in
    --base-ref=*) base_ref="${argument#--base-ref=}" ;;
    --goal=*) goal="${argument#--goal=}" ;;
    --approver=*) approval_actor="${argument#--approver=}" ;;
    --pr-number=*) pr_number="${argument#--pr-number=}" ;;
    *) echo "Unknown option: ${argument}" >&2; exit 2 ;;
  esac
done

if [[ -z "${base_ref}" || "${base_ref}" == *'..'* || ! "${base_ref}" =~ ^[A-Za-z0-9._/-]+$ ]]; then
  echo "Invalid --base-ref value: ${base_ref}" >&2
  exit 2
fi
if [[ -z "${goal//[[:space:]]/}" ]]; then
  echo 'Self-shape requires a non-empty goal.' >&2
  exit 2
fi
if [[ -z "${approval_actor//[[:space:]]/}" || "${approval_actor}" == "${planner}" ]]; then
  echo 'Self-shape approval fixture must name an actor distinct from the planner.' >&2
  exit 2
fi
if [[ -n "${pr_number}" && ! "${pr_number}" =~ ^[0-9]+$ ]]; then
  echo "Invalid --pr-number value: ${pr_number}" >&2
  exit 2
fi

agent_loop=(php bin/agent-loop)
base="$(git merge-base HEAD "origin/${base_ref}")"
head="$(git rev-parse HEAD)"
mapfile -t changed_files < <(git diff --name-only --diff-filter=ACMRTUXB "${base}" HEAD --)
if [[ "${#changed_files[@]}" -eq 0 ]]; then
  echo 'Self-shape requires at least one changed file between the merge-base and HEAD.' >&2
  exit 1
fi

# Findings are watched across every state directory, not just validated/.
# `learn finding-transition` moves a consolidated finding out of validated/,
# which is the normal end of its lifecycle - watching only that one directory
# made a pull request that ran the Learning pipeline look as if it had recorded
# no findings at all, while MEMORY.md still forced the findings_recorded status.
mapfile -t recorded_finding_files < <(
  git diff --name-only --diff-filter=AR "${base}" HEAD -- '.agent-loop/learning/findings/*/finding.*.json' \
    | sed 's#.*/##; s#\.json$##' \
    | sort -u
)
memory_changed=0
if ! git diff --quiet "${base}" HEAD -- MEMORY.md; then
  memory_changed=1
fi

learning_status='no_durable_learning'
learning_reason='The self-shape gate observed no new reusable guidance beyond the durable changes already represented by this pull request.'
if [[ "${#recorded_finding_files[@]}" -gt 0 ]]; then
  learning_status='findings_recorded'
  learning_reason='The pull-request evidence recorded project findings; cite that evidence in the governed Run.'
elif [[ "${memory_changed}" -eq 1 ]]; then
  # The repository's own promotion rule: a durable memory row is backed by a
  # finding. Changing MEMORY.md with no finding evidence is the contradiction,
  # not a status to be chosen around.
  echo 'MEMORY.md changed but no project finding was recorded; a durable rule needs evidence.' >&2
  exit 1
fi

mkdir -p build
raw_diff='build/self-shape-raw.diff'
git diff --no-ext-diff --binary "${base}" HEAD -- > "${raw_diff}"
if [[ ! -s "${raw_diff}" ]]; then
  echo 'Self-shape raw diff evidence is empty.' >&2
  exit 1
fi

plan_goal="${goal}"
if [[ -n "${pr_number}" ]]; then
  plan_goal="PR #${pr_number}: ${goal}"
fi

php -r '
array_shift($argv);
[$task, $planner, $approvalActor, $base, $head, $prNumber, $goal] = array_splice($argv, 0, 7);
echo json_encode([
    "task" => $task,
    "planner" => $planner,
    "approval" => [
        "actor" => $approvalActor,
        "evidence_kind" => "ci_pr_author_fixture",
        "independent_human_review" => false,
    ],
    "base" => $base,
    "head" => $head,
    "pr_number" => $prNumber === "" ? null : (int) $prNumber,
    "goal" => $goal,
    "changed_files" => array_values($argv),
    "raw_diff" => "build/self-shape-raw.diff",
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
' "${task}" "${planner}" "${approval_actor}" "${base}" "${head}" "${pr_number}" "${plan_goal}" "${changed_files[@]}" \
  > build/self-shape-input.json

"${agent_loop[@]}" learn validate --root "${learning_root}"

plan=(
  "${agent_loop[@]}" workflow plan "${task}"
  --by "${planner}"
  --base-commit "${base}"
)
for file in "${changed_files[@]}"; do
  plan+=(--file "${file}")
done
plan+=(
  --goal "${plan_goal}"
  --non-goal 'Do not mutate the PR checkout, move package ownership, auto-promote findings to durable memory, or represent the CI approval fixture as independent human review.'
  --validation 'composer ci'
  --behavior-anchor 'real pull-request diff -> durable Contract -> governed Run -> bounded context -> observed validation -> review -> durable Learning decision -> Verification receipt -> pruneable Session'
)
"${plan[@]}"

"${agent_loop[@]}" workflow approve "${task}" --by "${approval_actor}"

recall_root="$("${agent_loop[@]}" workflow report "${task}" --format json | php -r '
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$meta = $data["recall"]["meta_path"] ?? null;
if (!is_string($meta) || $meta === "") { exit(1); }
echo dirname($meta, 2);
')"
if [[ -z "${recall_root}" ]]; then
  echo 'Unable to resolve the Recall output root from agent-loop.' >&2
  exit 1
fi

status_before="$("${agent_loop[@]}" workflow status "${task}" --format json)"
run_id="$(php -r '
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$run = $data["manifest"]["run_id"] ?? null;
if (!is_string($run) || !str_starts_with($run, "run:")) { exit(1); }
echo $run;
' <<< "${status_before}")"

"${agent_loop[@]}" session checkpoint "${task}" \
  --title 'CI approval fixture boundary' \
  --body "The approval transition was exercised with '${approval_actor}'. This proves gate mechanics only; it is not independent human review evidence for the pull request."

"${agent_loop[@]}" workflow context "${task}" \
  --format json \
  > build/self-shape-context.json

validation_started_ms="$(php -r 'echo (string) ((int) round(microtime(true) * 1000));')"
composer ci
validation_finished_ms="$(php -r 'echo (string) ((int) round(microtime(true) * 1000));')"
validation_duration_ms=$((validation_finished_ms - validation_started_ms))
if [[ "${validation_duration_ms}" -lt 0 ]]; then
  echo 'Observed composer ci duration cannot be negative.' >&2
  exit 1
fi

"${agent_loop[@]}" session validation record "${task}" \
  --contract-revision 1 \
  --command 'composer ci' \
  --status passed \
  --exit-code 0 \
  --duration-ms "${validation_duration_ms}" \
  --by "${planner}"

set +e
"${agent_loop[@]}" review blindspots "${task}"
initial_review_exit=$?
set -e
if [[ "${initial_review_exit}" -ne 0 && "${initial_review_exit}" -ne 1 ]]; then
  exit "${initial_review_exit}"
fi

"${agent_loop[@]}" recall log-outcome \
  --root "${learning_root}" \
  --draft "${recall_root}/${task}/recall-log.draft.json" \
  --by "${planner}" \
  --commit "${head}"

"${agent_loop[@]}" session checkpoint "${task}" \
  --title 'Review close-out' \
  --body "Recall outcomes were logged and the deterministic blind-spot report was inspected against ${base}."

"${agent_loop[@]}" review blindspots "${task}"
"${agent_loop[@]}" review code "${task}"
code_review_prompt="${recall_root}/${task}/reviews/${task}.code.prompt.md"
if [[ ! -s "${code_review_prompt}" ]]; then
  echo "Missing code-review prompt: ${code_review_prompt}" >&2
  exit 1
fi

blindspot_report="${recall_root}/${task}/reviews/${task}.blindspots.json"
if [[ ! -s "${blindspot_report}" ]]; then
  echo "Missing blind-spot report: ${blindspot_report}" >&2
  exit 1
fi

raw_diff_sha="$(sha256sum "${raw_diff}" | awk '{print $1}')"
code_review_prompt_sha="$(sha256sum "${code_review_prompt}" | awk '{print $1}')"
# What the deterministic review actually said is recorded here. `review
# blindspots` exits 0 on `warn`, and `workflow close` owns the gate that
# refuses a missing, invalid or `fail` report - so the harness does not assert
# a second copy of that gate. It captures the residual status instead, because
# an unrecorded `warn` is a review result nobody can inspect afterwards.
php -r '
$report = json_decode((string) file_get_contents($argv[5]), true, 512, JSON_THROW_ON_ERROR);
$status = is_array($report) ? ($report["status"] ?? null) : null;
if (!is_string($status)) {
    fwrite(STDERR, "Blind-spot report has no machine-readable status.\n");
    exit(1);
}
$findings = [];
foreach (is_array($report["findings"] ?? null) ? $report["findings"] : [] as $finding) {
    $id = is_array($finding) ? ($finding["id"] ?? null) : null;
    if (is_string($id)) {
        $findings[] = $id;
    }
}
echo json_encode([
    "raw_diff" => ["path" => $argv[1], "sha256" => $argv[2], "complete" => true],
    "code_review_prompt" => ["path" => $argv[3], "sha256" => $argv[4]],
    "blindspot_review" => [
        "path" => $argv[5],
        "status" => $status,
        "residual_findings" => $findings,
    ],
    "correctness_review" => [
        "status" => "external_required",
        "note" => "CI preserves the complete raw diff and bounded review input; it does not claim independent human/model correctness review occurred.",
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
' "${raw_diff}" "${raw_diff_sha}" "${code_review_prompt}" "${code_review_prompt_sha}" "${blindspot_report}" \
  > build/self-shape-review-evidence.json

learning=(
  "${agent_loop[@]}" workflow learn "${task}"
  --status "${learning_status}"
  --by "${planner}"
  --reason "${learning_reason}"
)
for finding_id in "${recorded_finding_files[@]}"; do
  learning+=(--finding "${finding_id}")
done
"${learning[@]}"

memory_review="$("${agent_loop[@]}" memory review --file=MEMORY.md)"
printf '%s\n' "${memory_review}"
grep -Fq 'Rows still needing promotion review: 0' <<< "${memory_review}"

"${agent_loop[@]}" workflow manifest "${task}" \
  --write \
  --format json \
  > build/self-shape-manifest-before-close.json

"${agent_loop[@]}" verify --task-id="${task}"

report=(
  "${agent_loop[@]}" workflow report "${task}"
  --format json
)
for file in "${changed_files[@]}"; do
  report+=(--changed-file "${file}")
done
"${report[@]}" > build/self-shape-report.json

"${agent_loop[@]}" workflow close "${task}" --status done

status_file='build/self-shape-status.json'
"${agent_loop[@]}" workflow status "${task}" --format json > "${status_file}"
php -r '
$path = $argv[1];
$expectedRun = $argv[2];
$expectedLearning = $argv[3];
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$manifest = $data["manifest"] ?? null;
$refs = is_array($manifest) ? ($manifest["references"] ?? null) : null;
if (
    !is_array($manifest)
    || ($manifest["run_id"] ?? null) !== $expectedRun
    || ($manifest["state"] ?? null) !== "complete"
    || ($manifest["next_action"] ?? null) !== "none"
    || ($refs["session"]["state"] ?? null) !== "done"
    || ($refs["verification"]["state"] ?? null) !== "passed"
    || ($refs["learning"]["state"] ?? null) !== "decided"
    || ($refs["learning"]["decision"] ?? null) !== $expectedLearning
) {
    fwrite(STDERR, "Final workflow projection is not complete and owner-consistent.\n");
    exit(1);
}
' "${status_file}" "${run_id}" "${learning_status}"

"${agent_loop[@]}" session prune --keep-days 0 --status done
post_prune_file='build/self-shape-status-post-prune.json'
"${agent_loop[@]}" workflow status "${task}" --format json > "${post_prune_file}"
php -r '
$path = $argv[1];
$expectedRun = $argv[2];
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$manifest = $data["manifest"] ?? null;
$refs = is_array($manifest) ? ($manifest["references"] ?? null) : null;
if (
    !is_array($manifest)
    || ($manifest["run_id"] ?? null) !== $expectedRun
    || ($manifest["state"] ?? null) !== "complete"
    || ($refs["session"]["state"] ?? null) !== "missing"
    || ($refs["verification"]["state"] ?? null) !== "passed"
    || ($refs["learning"]["state"] ?? null) !== "decided"
) {
    fwrite(STDERR, "Pruning Session working memory changed durable Run semantics.\n");
    exit(1);
}
' "${post_prune_file}" "${run_id}"

"${agent_loop[@]}" workflow report "${task}" \
  --format json \
  > build/self-shape-report-post-prune.json

grep -Fq '"source": "verification_receipt"' build/self-shape-report-post-prune.json

printf 'Self-shape dogfood: PASSED\nBase: %s\nHead: %s\nRun: %s\nChanged files: %d\nGoal: %s\nApproval fixture: %s\nValidation duration: %d ms\nLearning decision: %s\nSession prune replay: passed\n' \
  "${base}" "${head}" "${run_id}" "${#changed_files[@]}" "${plan_goal}" "${approval_actor}" "${validation_duration_ms}" "${learning_status}"
