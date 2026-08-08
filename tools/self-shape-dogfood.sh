#!/usr/bin/env bash
set -euo pipefail

task='SELF-SHAPE'
planner='agent-loop-self-shape'
learning_root='infra/doc/agent-learning'
recall_root="${learning_root}/recall-output"
base_ref="${GITHUB_BASE_REF:-main}"
goal="${SELF_SHAPE_GOAL:-}"
if [[ -z "${goal//[[:space:]]/}" ]]; then
  goal='Govern and validate the current agent-loop diff through agent-loop itself.'
fi
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

learning_status='no_durable_learning'
for file in "${changed_files[@]}"; do
  if [[ "${file}" == infra/doc/agent-learning/findings/* || "${file}" == 'MEMORY.md' ]]; then
    learning_status='findings_recorded'
    break
  fi
done

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
  --learning-root "${learning_root}"
  --base-commit "${base}"
)
for file in "${changed_files[@]}"; do
  plan+=(--file "${file}")
done
plan+=(
  --goal "${plan_goal}"
  --non-goal 'Do not mutate the PR checkout, move package ownership, auto-promote findings to durable memory, or represent the CI approval fixture as independent human review.'
  --validation 'composer ci'
  --behavior-anchor 'real pull-request diff -> governed brief -> bounded context -> observed validation -> review artifacts -> learning decision -> verify -> close'
)
"${plan[@]}"

"${agent_loop[@]}" workflow approve "${task}" \
  --by "${approval_actor}" \
  --learning-root "${learning_root}"

"${agent_loop[@]}" session checkpoint "${task}" \
  --title 'CI approval fixture boundary' \
  --body "The approval transition was exercised with '${approval_actor}'. This proves gate mechanics only; it is not independent human review evidence for the pull request."

"${agent_loop[@]}" workflow context "${task}" \
  --format json \
  --learning-root "${learning_root}" \
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
  --brief-revision 1 \
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
  --title 'log-outcome + review blindspots close-out' \
  --body "recall log-outcome completed; reviewed the deterministic review blindspots report for this change against ${base}."

"${agent_loop[@]}" session learning decide "${task}" \
  --status "${learning_status}" \
  --by "${planner}"

"${agent_loop[@]}" review blindspots "${task}"
review_report="${recall_root}/${task}/reviews/${task}.blindspots.json"
php -r '
$path = $argv[1];
$json = file_get_contents($path);
if ($json === false) {
    fwrite(STDERR, "Missing final blind-spot report: {$path}\n");
    exit(1);
}
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
if (($data["status"] ?? null) !== "ok") {
    fwrite(STDERR, "Final blind-spot review must be status=ok:\n{$json}\n");
    exit(1);
}
' "${review_report}"

"${agent_loop[@]}" review code "${task}"
code_review_prompt="${recall_root}/${task}/reviews/${task}.code.prompt.md"
if [[ ! -s "${code_review_prompt}" ]]; then
  echo "Missing code-review prompt: ${code_review_prompt}" >&2
  exit 1
fi

raw_diff_sha="$(sha256sum "${raw_diff}" | awk '{print $1}')"
code_review_prompt_sha="$(sha256sum "${code_review_prompt}" | awk '{print $1}')"
php -r '
echo json_encode([
    "raw_diff" => ["path" => $argv[1], "sha256" => $argv[2], "complete" => true],
    "code_review_prompt" => ["path" => $argv[3], "sha256" => $argv[4]],
    "correctness_review" => [
        "status" => "external_required",
        "note" => "CI preserves the complete raw diff and generates bounded review input; it does not claim an independent human/model correctness review occurred.",
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
' "${raw_diff}" "${raw_diff_sha}" "${code_review_prompt}" "${code_review_prompt_sha}" \
  > build/self-shape-review-evidence.json

memory_review="$("${agent_loop[@]}" memory review --file=MEMORY.md)"
printf '%s\n' "${memory_review}"
grep -Fq 'Rows still needing promotion review: 0' <<< "${memory_review}"

"${agent_loop[@]}" workflow manifest "${task}" \
  --write \
  --format json \
  > build/self-shape-manifest-before-close.json

"${agent_loop[@]}" verify

report=(
  "${agent_loop[@]}" workflow report "${task}"
  --format json
  --learning-root "${learning_root}"
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
$expectedLearning = $argv[2];
$json = file_get_contents($path);
if ($json === false) {
    fwrite(STDERR, "Missing final workflow status: {$path}\n");
    exit(1);
}
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$manifest = $data["manifest"] ?? null;
$references = is_array($manifest) ? ($manifest["references"] ?? null) : null;
$session = is_array($references) ? ($references["session"] ?? null) : null;
$review = is_array($references) ? ($references["review"] ?? null) : null;
$learning = is_array($references) ? ($references["learning"] ?? null) : null;
if (
    !is_array($manifest)
    || ($manifest["state"] ?? null) !== "complete"
    || ($manifest["next_action"] ?? null) !== "none"
    || !is_array($session)
    || ($session["state"] ?? null) !== "done"
    || !is_array($review)
    || ($review["state"] ?? null) !== "ok"
    || !is_array($learning)
    || ($learning["state"] ?? null) !== $expectedLearning
) {
    fwrite(STDERR, "Final workflow projection is not complete/done/clean/consistent:\n{$json}\n");
    exit(1);
}
' "${status_file}" "${learning_status}"

printf 'Self-shape dogfood: PASSED\nBase: %s\nHead: %s\nChanged files: %d\nGoal: %s\nApproval fixture: %s\nValidation duration: %d ms\nLearning decision: %s\n' \
  "${base}" "${head}" "${#changed_files[@]}" "${plan_goal}" "${approval_actor}" "${validation_duration_ms}" "${learning_status}"
