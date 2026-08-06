---
name: agent-loop-dogfood
description: Evaluate agent guidance against real agent-* tasks with clean, comparable runs and observable artifact metrics instead of invented token savings.
---

# Agent Loop Dogfood

Use this skill when changing agent guidance, hooks, recall, edit orchestration, or
`agent-map` navigation behavior.

## Method

1. Choose a real bounded task from `agent-loop`, `agent-map`, or a release-set fixture.
2. Record the baseline task, repository revision, model, tools, and validation.
3. Run the baseline and candidate guidance in separate clean sessions.
4. Keep task wording, repository state, model, and validation identical.
5. Compare observable artifacts, not hidden reasoning.
6. Keep the candidate only when quality is unchanged or better and the workflow is simpler.

## Metrics

Record:

- files changed;
- added and removed lines;
- new dependencies;
- unrequested behavior added;
- clarification stalls;
- broad file reads before map use;
- validation commands actually run;
- response words and repeated explanations;
- review findings and regressions.

Do not claim saved reasoning tokens without provider telemetry. Do not infer a
counterfactual line count from code that was never generated.

## Required Cases

- exact PHP symbol change where `map` plus mechanical edit should win;
- shared bug where callers must be inspected before the root-cause change;
- documentation-only task where no product code should be added;
- review task where the full diff must remain visible;
- trivial task where guidance overhead may cost more than it saves.

## Iteration

For each failure, change one guidance or runtime mechanism, rerun the same case,
and record the effect. Stop when the candidate is stable, not when one run looks
convincing.
