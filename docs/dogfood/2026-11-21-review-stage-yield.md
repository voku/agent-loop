# Review-stage yield and cost in real governed tasks (#1031 evidence)

Date: 2026-11-21. Input: the governed tasks one operator session ran on `voku/agent-loop` and `voku/php-runtime-audit` on this date. This complements the [risk-based validation trial](2026-11-21-risk-based-validation-trial.md) and the [T10 record](2026-11-21-recall-map-real-use.md) from the same day. It is evidence for #1031, not a capability decision.

**In one line:** Correctness review stopped every code task at least once, with 4 blocking defects in 3 tasks. Blindspot review stopped one, but it produced most of the real follow-ups. Neither review found anything blocking in the six runs on documentation or test-only deltas. The two defects in test files that did reach CI were caught by deterministic gates (slop-scan, PHPStan), not by either review. Recall made up about 85 % of every review stage prompt, and no review report cited it.

## Setup and bias

- **Host and reviewers:** the operator was a Claude Code session. Every review stage ran as a separate fresh-context subagent in the same container. Model:
  - default model for the larger code tasks;
  - `sonnet` for the documentation and test-delta revisions, so those runs are not model-matched with the others.
- **Reviewer prompts:** the operator wrote them, each with a checklist of areas to verify. This is the biggest bias: the yield measures "fresh reviewer plus operator checklist", not a bare stage prompt.
- **Local verification:** PHPUnit and PHPStan dev dependencies could not be installed in this container. Reviewers ran tests through a minimal PHPUnit stand-in (no data providers), and PHPStan through scratch configs on `src` only. Exact-head CI was the real gate.
- **Cost figures:**
  - `tokens` is the subagent total reported by the host, including tool output, not the prompt alone.
  - `time` is the subagent's wall-clock duration.
  - Where the host did not show them, the value is `unknown`.
- **Sample size:** n is small: 4 tasks and 18 governed review stage runs (the rows of the table below), plus 6 replay reviewers. Read the results as direction, not as rates.

## Per task and stage

| Task (PR) | Stage, attempt | Outcome | Tokens / time | What it found |
| --- | --- | --- | --- | --- |
| PHP-SETUP-GITHOOKS (#1119) | correctness 1 | **changes_required** | unknown | A bare `init sync-githooks` drops the `--hooks-dir`, `--commit-template` and `--adopt-existing` arguments. |
| | correctness 2 | pass | unknown | – |
| | blindspot 1 | pass | unknown | Observations: the guide names a bare `sync-githooks`, and hook files are written into a directory Git already uses. **CodeRabbit later rated these same two points Minor and Major.** |
| REVIEW-OBSERVATIONS (#1121) | correctness 1 | **changes_required** | 171,421 / 304 s | "Latest result per stage" erased observations after a rework loop. Proven with a CLI probe. |
| | correctness 2 | pass, 7 observations | 168,994 / 352 s | – |
| | blindspot 1 | pass, 9 observations | 139,683 / 224 s | 8 were new and were recorded as typed observations; one repeated a correctness observation. That makes 15 in total, listed in #1120. Among them: the installed `agent-loop-learning-boundary` skill steered hosts into the new refusal. It was a real P1, later fixed in #1122. |
| | rev 3 (test delta, sonnet) | pass / pass | 73,590 / 15 s; 81,083 / 22 s | – |
| DECISION-CARD (#1127) | correctness 1 | **changes_required** | 187,893 / 421 s | Card choices used the gate's unrelated action as their command. |
| | correctness 2 | **changes_required** | 213,454 / 464 s | An unguarded Run lookup made `workflow plan` throw on an unreadable `run.json`. Proven with a probe. |
| | correctness 3 | pass, 6 observations | 179,512 / 320 s | – |
| | blindspot 1 | **changes_required** | 155,990 / 254 s | `card.language` claimed `fr-fr` while the text was English. |
| | correctness 4 | pass, 4 observations | 133,348 / 183 s | – |
| | blindspot 2 | pass, 4 observations | 143,431 / 200 s | The follow-ups from all DECISION-CARD reviews are in #1126. |
| | rev 3 (test delta, sonnet) | pass / pass | 90,442 / 26 s; 82,246 / 28 s | – |
| CARD-GUIDANCE (#1131, docs only) | correctness 1 (sonnet) | pass, 3 observations | 98,328 / 49 s | One observation was the CHANGELOG regional-tag wording. **CodeRabbit later reported the same point as its only Minor.** |
| | blindspot 1 (sonnet) | pass, 3 observations | 96,111 / 26 s | – |

The review-round cap (3) was reached once, in DECISION-CARD. The owner chose one more round, and the extra round found nothing blocking.

## Defects that reached CI after both reviews passed

| PR | Gate that caught it | Defect | Why the reviews missed it |
| --- | --- | --- | --- |
| #1121 | slop-scan (`php.empty-catch`) | an empty `catch` in a new test | Reviewers did not run slop-scan on the test file. |
| #1127 | PHPStan (`nullsafe.neverNull`) | two `?->` uses on a value already narrowed, in a new test | PHPStan on tests could not run in this container. |

Both defects were in test files, were found by deterministic gates, and each cost one extra governed revision with two more review stages.

## Replay control (php-runtime-audit #32)

The code before the fix (`5bf451c`) went to 6 fresh reviewers with no notes. Each used 124,823–143,854 tokens and 180–302 s.

| Defect | Reviewers that found it | Also found by |
| --- | --- | --- |
| `runtime.packages` evidence IDs exceed the 512 limit (the major CodeRabbit finding) | 6 of 6 | CodeRabbit |
| FIFO hang on the dpkg status file | 3 of 6 | CodeRabbit |
| multi-arch duplicate | 6 of 6 | – |
| silent status filter (transitional and `deinstall ok installed` states dropped) | 5 of 6 | – (distinct from the earlier CodeRabbit `hold ok installed` finding, which was fixed in `e82078f` before `5bf451c`) |
| field-name regex | 4 of 6 | – |
| symlink race on parent directories | 4 of 6 | – |

In real use this PR had **no** governed review, because the lifecycle could not run in the merge environment. The miss came from the review stage being absent, not from a gap in reviewer knowledge.

## Stage prompt composition

Measured from the saved stage prompts of the four tasks:

| Stage prompts | Total bytes | Recall block (`# Governed Recall reference data` to the end) |
| --- | --- | --- |
| PHP-SETUP-GITHOOKS | 24,445 | 21,145 (86 %) |
| REVIEW-OBSERVATIONS | 29,071–30,934 | 24,933–26,609 (86 %) |
| DECISION-CARD | 34,472–34,539 | 29,176–29,238 (85 %) |
| CARD-GUIDANCE | 29,046–29,051 | 25,111 (86 %) |

No review report in these tasks cited Recall content. This does not prove the block was unused, because a reviewer may read context without citing it. It does match the trial's finding that 0 guidance was selected.

## What this does and does not show

- **Correctness review:** blocking findings in 3 of 3 code tasks. Every one was real; each was reproduced or the fix verified in code.
- **Blindspot review:** blocking once in 3 code tasks. It did, however, produce the observations that external review later rated highest (#1119), plus the real P1 skill contradiction (#1121).
  - Its value in this sample lies mostly in non-blocking findings.
  - Those findings only reach Learning because of the observation gate shipped in #1121.
- **Severity:** a review can be right about *what* and wrong about *how serious*. In #1119, two points marked non-blocking were rated Major/Minor externally.
- **Documentation and test-only deltas:** 6 stage runs, all `sonnet`, found nothing blocking.
  - The two test-file defects that existed were caught by deterministic gates, not by reviews.
  - This does **not** contradict the trial's R7 result, where a small test-only task had real defects in review. Both samples are tiny, and change type alone remains an unsupported rule.
  - What the data does support: for test deltas, running slop-scan and PHPStan on tests *before* review would have prevented two extra governed revisions.
- **Cost:**
  - Code-task review stages: 133k–213k tokens and 3–8 minutes each.
  - Small-delta stages on `sonnet`: 73k–98k tokens and under 1 minute.
  - About 85 % of the stage prompt was the Recall block.
- **Not measured:**
  - whether a smaller prompt (no Recall block) keeps the same hit rate;
  - whether `surgical` (one review) would have caught the blindspot-only defect.

Both remain `unknown`.

## Suggested next bounded step (not executed)

Replay one fresh correctness reviewer on frozen candidates with a **known** blocking defect, comparing the full stage prompt against the same prompt without the Recall block. Candidates:
- the DECISION-CARD attempt-1 candidate (`3fcd9d5`);
- the REVIEW-OBSERVATIONS attempt-1 candidate (`7d5330b`).

Run n = 3 per arm. Record hit or miss against the known defect, false alarms, tokens and time. Set the arms up with a script, not by hand: in this session a hand-built arm got a wrong file path, and the control lost its manipulation (the #32 replay). This tests the trial's "shrink the prompt" hypothesis directly, without changing any default.
