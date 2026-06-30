---
name: agent-loop-review-close
description: Review, verify, and close an agent-loop task safely after implementation, including blind-spot review, strict verification, and explicit accepted-risk handling.
---

# Agent Loop Review Close

Use this skill when implementation is done or nearly done and the task needs to
be reviewed, verified, and closed in a governed way.

## Fast Path

Run blind-spot review, then verify, then inspect status, then close:

```bash
vendor/bin/agent-loop review blindspots <task-id>
vendor/bin/agent-loop verify
vendor/bin/agent-loop workflow status <task-id>
vendor/bin/agent-loop workflow close <task-id> --status done
```

All four steps are required. Do not skip steps or reorder them.

## Blind-Spot Review

```bash
vendor/bin/agent-loop review blindspots <task-id>
```

This is a deterministic check, not an LLM call. It writes Markdown/JSON
reports under `.agent-recall/reviews/` using task, session, and recall
artifacts as context. It warns when session notes do not show that
`review blindspots` itself was checked.

Review output is not human approval. It does not approve code. It does not
approve durable learning. Human review remains required.

## Verify

```bash
vendor/bin/agent-loop verify
```

Checks cross-package consistency: sessions, recall coverage, board, and
learning state. Each check prints `[OK]`, `[SKIP]`, or `[FAIL]`.

In CI or repos where all parts of the stack are expected to be present,
use `--strict` to turn baseline skips into failures:

```bash
vendor/bin/agent-loop verify --strict
```

`--strict` fails when `tasks/` or `session_plan/` is missing entirely.
Use it in expected-complete repos; omit it when the repo only wires part
of the stack.

## Close

```bash
vendor/bin/agent-loop workflow close <task-id> --status done
```

`workflow close` is gated: it requires recall metadata, a blind-spot review
report, and a passing `verify` before accepting `--status done`. If the gate
is not satisfied, the command exits with an error describing what is missing.

## Accepted Risk

If verify fails or a gate is not satisfied and you need to close anyway,
accepted risk is explicit and written to disk:

```bash
vendor/bin/agent-loop workflow close <task-id> \
  --status done \
  --accept-risk "Manual review by Lars for urgent legacy hotfix."
```

`--accept-risk` writes `.agent-loop/risks/<task-id>.accepted-risk.md`.

Never use `--accept-risk` as a lazy bypass. It is for situations where
the risk is real, understood, and explicitly owned by a named actor.
If verification fails and the root cause is not understood, fix it first.

## When Verification Fails

1. Read the `[FAIL]` output to understand what is missing.
2. Fix the underlying issue (recompile recall, run the missing review step,
   resolve the session state).
3. Record a checkpoint explaining the resolution:
   ```bash
   vendor/bin/agent-loop session checkpoint <task-id> --title "Verify fix" --body "..."
   ```
4. Re-run `verify` to confirm it passes.
5. If the failure is understood and fixing it is not feasible before the
   deadline, accept risk explicitly with `--accept-risk` and a named actor.

## Close Is Not Durable Learning Approval

Closing a task with `workflow close` is not an approval of durable learning.
Findings and learning candidates remain review inputs. Only reviewed decisions
become durable guidance. See `docs/workflow/learning-boundary.md`.

## Validation

- Blind-spot review report exists under `.agent-recall/reviews/`
- `vendor/bin/agent-loop verify` passes (or accepted risk is explicit and named)
- `vendor/bin/agent-loop workflow status <task-id>` shows a closeable state
- `vendor/bin/agent-loop workflow close <task-id> --status done` succeeds

## Skill Boundary

This skill owns:

- the review and close step of a governed agent-loop task in a consuming repo
- understanding the verify gate and its `--strict` mode
- understanding accepted-risk as an explicit, named, last-resort path
- knowing that close is not durable learning approval

This skill does not own:

- the task opening step (see `agent-loop-task-start`)
- recall compilation and L2 context (see `agent-loop-l2-context`)
- developing `agent-loop` itself

## Example Triggers

- "Close this agent-loop task."
- "Run the review/verify gate."
- "Can I mark this done?"
- "Accept the risk and close."
