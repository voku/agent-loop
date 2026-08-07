#!/usr/bin/env bash
set -euo pipefail

task='SELF-SHAPE'
actor='agent-loop-self-shape'
learning_root='infra/doc/agent-learning'
recall_root="${learning_root}/recall-output"
base_ref="${GITHUB_BASE_REF:-main}"

for argument in "$@"; do
  case "${argument}" in
    --base-ref=*) base_ref="${argument#--base-ref=}" ;;
    *) echo "Unknown option: ${argument}" >&2; exit 2 ;;
  esac
done

if [[ -z "${base_ref}" || "${base_ref}" == *'..'* || ! "${base_ref}" =~ ^[A-Za-z0-9._/-]+$ ]]; then
  echo "Invalid --base-ref value: ${base_ref}" >&2
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

mkdir -p build

"${agent_loop[@]}" learn validate --root "${learning_root}"

plan=(
  "${agent_loop[@]}" workflow plan "${task}"
  --by "${actor}"
  --learning-root "${learning_root}"
  --base-commit "${base}"
)
for file in "${changed_files[@]}"; do
  plan+=(--file "${file}")
done
plan+=(
  --goal 'Shape agent-loop with agent-loop: reduce internal orchestration glue while preserving behavior and persist evidence-backed project learning.'
  --non-goal 'Do not move package ownership, add a shared core package, or expand the public workflow surface.'
  --validation 'composer ci'
  --behavior-anchor 'agent-loop workflow command -> typed package API -> persisted governed state -> validation -> review -> learning -> close'
)
"${plan[@]}"

"${agent_loop[@]}" workflow approve "${task}" \
  --by "${actor}" \
  --learning-root "${learning_root}"

"${agent_loop[@]}" workflow context "${task}" \
  --format json \
  --learning-root "${learning_root}" \
  > build/self-shape-context.json

composer ci

"${agent_loop[@]}" session validation record "${task}" \
  --brief-revision 1 \
  --command 'composer ci' \
  --status passed \
  --exit-code 0 \
  --duration-ms 0 \
  --by "${actor}"

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
  --by "${actor}" \
  --commit "${head}"

"${agent_loop[@]}" session checkpoint "${task}" \
  --title 'log-outcome + review blindspots close-out' \
  --body "recall log-outcome completed; reviewed the deterministic review blindspots report for this change against ${base}."

"${agent_loop[@]}" session learning decide "${task}" \
  --status no_durable_learning \
  --by "${actor}"

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
"${agent_loop[@]}" workflow status "${task}" --format json \
  > build/self-shape-status.json

printf 'Self-shape dogfood: PASSED\nBase: %s\nHead: %s\nChanged files: %d\n' \
  "${base}" "${head}" "${#changed_files[@]}"
