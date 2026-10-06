---
name: agent-loop-workflow
description: Operate a governed agent-loop task end to end, including implementation requests, by routing through enter/finish and obeying the lifecycle kernel's structured next step instead of reproducing workflow policy in host prose.
---

# Agent Loop Workflow

**Trigger Anchor:** Governed lifecycle execution -> route through `enter` and `finish`, obey canonical `next_action_kind` / `next_action`, present exact evidence for human decisions, never reproduce workflow state in chat.

## Ordinary Host Contract

Start or resume through the front door:
```bash
vendor/bin/agent-loop enter <task-id> --format=json
```

Obey `next_action_kind` / `next_action` from that result. `AGENTS.md` already defines how to treat each kind, so this skill does not restate it: a third copy of a rule the router and the result both carry is a second source of truth that can drift from its authority.

A task router may choose a specialist skill, never a lifecycle phase. Only the current result routes task start/progress, Recall or repair, Learning, review, and close.

## End-to-End Implementation Front Door

When this skill is invoked for an end-to-end implementation request, keep the request ergonomic without creating a second workflow:

- use the supplied stable task id with `enter`; if no stable id exists, obtain one through the repository's task owner rather than inventing lifecycle state in chat;
- treat the user's request as intent to drive the governed task, not as authority to skip approval, verification, review, Learning, or any other gate the current result requires;
- do not predeclare a private sequence such as branch -> specs -> code -> PR -> merge; the current lifecycle result decides the next governed action;
- continue through `enter`, host work, and `finish` until the result reports completion or a real `decision_required` boundary.

This is the implementation front door. Do not add a parallel `implement` skill that copies the same lifecycle semantics.

When host-native mutation is complete, reconcile deterministic close-out through:
```bash
vendor/bin/agent-loop finish <task-id> --format=json
```

Then obey the returned `next_action_kind` / `next_action` until `none` / complete.

## Decision & Review Presentation

Before asking for any `decision_required` action, surface the smallest complete developer-readable projection of the decision subject:
- **Contract Approval:** Show candidate Contract revision (goal, scope, non-goals, acceptance criteria, validation commands). Refresh context if needed via:
  ```bash
  vendor/bin/agent-loop workflow context <task-id> --format=json
  ```
  Approval is the task-authority gate. Once approved, ordinary implementation, validation, review acknowledgement, Learning disposition, and closeout proceed without ceremonial re-approval.
- **Review Acknowledgement:** Render the deterministic HTML review workbench or read the report:
  ```bash
  vendor/bin/agent-loop workflow review <task-id>
  ```
  Surface the HTML path, review SHA-256, and findings before filling the review `command_template`.

## Prompt Controls & Reflection

- **Controls:** `checkpoint-autonomy` and `momentum` tune execution behavior without changing lifecycle authority.
- **Review Loop:** `RETURN_TO_REVIEW` routes identified review defects back through the lifecycle kernel.
- **Reflection:** Reflection is deliberately **not** another lifecycle phase. Use it only when additional scrutiny is useful:
  ```bash
  vendor/bin/agent-loop workflow reflect <task-id> --scope task
  vendor/bin/agent-loop workflow reflect <task-id> --scope project
  ```

## Post-completion Future Work

Future-work reflection is allowed only after the current task is already `complete` (or explicitly `ready_to_close` for manual reflection). Apply `future_work.mode` exactly:
- `focus` — stop. Do not proactively search for adjacent future work.
- `discover` — run one bounded project reflection with `vendor/bin/agent-loop workflow reflect <task-id> --scope project`, report the strongest evidence-backed direction (or explicitly report that no worthwhile investment direction was found), and do not prepare or execute follow-up work.
- `invest` — run the same bounded project reflection. When it identifies an evidence-backed direction worth preparing, use the repository's existing task/Kanban owner to prepare at most `future_work.max_follow_up_slices` independent follow-up candidates. Do not approve or execute them automatically.

Follow-up execution requires its own normal governance/approval.

## Durable Handoff

When preserving bounded conversation context for a future agent in a task/card:
```bash
vendor/bin/agent-loop workflow handoff <task-id> --context '<bounded handoff notes>'
```
This binds notes to the governed Run/Session and compiles Recall's `todo-card-handoff` recipe into `system.md`.

### Bad vs Good Workflow Execution

### Bad
```text
# Chat-based lifecycle invention: skipping enter/finish, fabricating approvals
"I'll skip agent-loop enter and just edit the code directly, then close the ticket."
```

### Good
```text
# Canonical lifecycle routing: enter -> host work -> finish -> complete
RESULT: Parser updated to handle multiline docblocks.
STATE: ready_to_close TASK-1 rev 1
NEXT: vendor/bin/agent-loop finish TASK-1 --format=json
```

## Progress Receipt

Report only verified state after meaningful progress:
```text
RESULT: <verified result>
STATE: <current persisted lifecycle state>
NEXT: <canonical next step or explicit human gate>
```
Derive `STATE` and `NEXT` directly from the structured lifecycle result, never from a remembered phase diagram.
