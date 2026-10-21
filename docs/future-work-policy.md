# Repository future-work policy

`agent-loop` keeps the current governed task separate from optional work that becomes attractive only because repository context is still fresh after completion.

Configure that behavior in `.agent-loop/init.json`:

```json
{
  "workflow": {
    "future_work": {
      "mode": "focus",
      "max_follow_up_slices": 1
    }
  }
}
```

## Reflection eligibility

Task-level reflection can return an unfinished task to review. Project-level
future-work reflection is available only after the current task is complete,
and its output is not authorization to create or execute follow-up work.

## Modes

### `focus`

Default. After the current task is complete, stop. Do not proactively inspect adjacent future work.

This is the conservative choice for stable products, legacy codebases, narrowly scoped work repositories, or projects where unsolicited backlog growth costs more than preserved context is worth.

### `discover`

After the current task is complete, run one bounded project-level `workflow reflect` pass while the implementation/review context is still fresh. Report the strongest evidence-backed future direction, if any, but do not prepare or execute follow-up work.

Use this when future opportunities are useful to capture but task creation or prioritization should remain fully deliberate.

### `invest`

After the current task is complete, run the same bounded project-level reflection. When it identifies a worthwhile evidence-backed direction, the host may prepare up to `max_follow_up_slices` independent follow-up candidates through the repository's existing task/Kanban owner when that owner and the required identifiers are unambiguous.

Preparation is not approval. The completed Contract stays closed, and every follow-up execution still requires its own normal governed Contract/approval.

Identity comes from the Kanban owner, not from the host. A follow-up candidate is created with `agent-loop board card create --generate-id --title <title> --format=json`; the card ID in the result is then used unchanged as the follow-up's task id (`workflow plan <that ID>`), so card, Contract, Run, Session and Learning provenance share one stable identity. Do not compute "highest number + 1": independent Git branches created from the same base compute the same value, collide on the card file and leave every task-keyed record pointing at the wrong task. The projection `future_work.follow_up_task_identity` carries the command and rule so hosts do not rely on remembering this. Task ids that are not cards remain valid `ad_hoc_task`s and are never linked to a card.

This fits fast-moving libraries and active open-source projects where hot context can make one or two adjacent follow-ups unusually cheap to discover or prepare.

## Invariants

- Default is `focus`.
- `max_follow_up_slices` must be an integer from 1 to 10; invalid configuration falls back to conservative defaults with a warning.
- Future-work policy never expands the current Contract.
- Future-work reflection is optional post-completion work, never another close gate.
- Project-level reflection is blocked until the current task is complete; task-level reflection remains available at `ready_to_close` to surface genuine gaps for review.
- Reflection output does not authorize creating follow-up cards, Contracts, issues, or PRs, or executing follow-up work.
- `workflow reflect` remains Recall-owned; Loop decides when repository policy permits proactive reflection, not how the reflection prompt is written.
- A configured budget is a ceiling, not a quota. Do not manufacture backlog merely to consume it.
- Human/owner/risk authority is not created by `discover` or `invest`.

The current policy is projected by:

```bash
vendor/bin/agent-loop workflow context <task-id> --format=json
```

under `future_work`, so hosts do not need to parse `.agent-loop/init.json` directly.
