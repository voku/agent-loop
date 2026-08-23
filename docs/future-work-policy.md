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

This fits fast-moving libraries and active open-source projects where hot context can make one or two adjacent follow-ups unusually cheap to discover or prepare.

## Invariants

- Default is `focus`.
- `max_follow_up_slices` must be an integer from 1 to 10; invalid configuration falls back to conservative defaults with a warning.
- Future-work policy never expands the current Contract.
- Future-work reflection is optional post-completion work, never another close gate.
- `workflow reflect` remains Recall-owned; Loop decides when repository policy permits proactive reflection, not how the reflection prompt is written.
- A configured budget is a ceiling, not a quota. Do not manufacture backlog merely to consume it.
- Human/owner/risk authority is not created by `discover` or `invest`.

The current policy is projected by:

```bash
vendor/bin/agent-loop workflow context <task-id> --format=json
```

under `future_work`, so hosts do not need to parse `.agent-loop/init.json` directly.