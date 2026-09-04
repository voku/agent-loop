# Workflows

This document names the common end-to-end flows in `agent-loop`.

It is intentionally practical. The lifecycle kernel remains authoritative for
ordering, current state, and mutation readiness. These examples explain the
shape of the workflow without becoming another hidden state machine written in
Markdown.

## 1. Ordinary durable coding task

Use this for normal issue/board work that should survive across sessions.

```text
request
  -> enter
  -> plan/approve/prepare as returned by the kernel
  -> host-native implementation
  -> finish
  -> validation/review/Learning/close-out as returned by the kernel
  -> complete
```

Start:

```bash
vendor/bin/agent-loop enter TASK-1 --format=json
```

Then obey `next_action_kind` / `next_action` until implementation is authorized.
After mutation:

```bash
vendor/bin/agent-loop finish TASK-1 --format=json
```

Continue until `next_action_kind=none` and the result is complete.

### Human boundary

The human owns explicit authority decisions such as Contract approval, changed
approved intent, accepted risk, irreversible actions, or other decisions the
kernel classifies as `decision_required`.

The agent owns implementation mechanics inside approved authority.

## 2. New task without a prepared Contract

A new task may cause `enter` to return a planning `command_template`.

The agent should fill model-owned placeholders from the actual request and
current repository evidence, for example goal, scope, behavior anchor, and
validation command.

That is not automatically a human decision.

After creating the candidate task plan, call `enter` again. The kernel decides
whether discovery, approval, preparation, or implementation is next.

Do not manually insert Map, Session, Recall, or review phases from remembered
workflow versions.

## 3. Read-only exploration

Use this when investigating a repository without creating durable task
semantics.

```text
question
  -> ephemeral session
  -> focused reads/search/Map as useful
  -> evidence-backed answer
  -> discard temporary working state
```

Do not invent a board card or approval merely to inspect code.

Navigation should be adaptive:

- known/local/literal/config/template question: focused reads or `rg` first;
- structural/relational question: Map when it provides useful evidence;
- fresh existing Map: reuse it rather than paying the build cost again.

## 4. Validation failure and implementation repair

A failing validation is not automatically a workflow failure.

```text
finish
  -> validation observes failure
  -> kernel returns host work
  -> fix implementation
  -> finish again
```

Do not loop the same validation command when the implementation has not changed.
The next useful action is to repair the cause.

If an advertised canonical command deterministically refuses and returns the
same impossible next step, that is different: record it as a workflow defect
instead of teaching the host an undocumented workaround.

## 5. Premise challenge / same-intent replan

Use this only when concrete evidence challenges the current implementation
framing.

Possible triggers include:

- supporting machinery grows faster than the requested product change;
- repeated repairs concern the measurement/workflow rather than the product;
- supposedly exclusive approaches prove complementary;
- current repository authority contradicts an old task premise;
- a materially simpler route preserves the approved outcome.

Check:

1. approved outcome;
2. assumption causing complexity;
3. whether current evidence still supports it;
4. simpler route preserving Goal, acceptance, scope, and authority.

Outcome:

- `CONTINUE`: premise still holds;
- `REPLAN`: same approved intent, simpler implementation strategy;
- `HUMAN_DECISION_REQUIRED`: the change would alter human-owned intent or risk.

A same-intent `REPLAN` does not require a new lifecycle phase.

## 6. Cross-package owner change

Use this when Loop needs a semantic capability owned by another `agent-*`
package.

```text
consumer exposes missing capability
  -> identify semantic owner
  -> smallest typed/public owner change
  -> owner regression evidence
  -> consume released/current owner surface as appropriate
  -> integration evidence in Loop
```

Examples of ownership:

- Kanban: board/card semantics;
- Session: working-memory identity/checkpoints;
- Map: repository facts and refactoring plans;
- Recall: bounded context and prompt recipes;
- Learning: durable Findings/Proposals/Learning state;
- Loop: lifecycle authority and canonical next actions;
- Runner: execution adapters;
- UI: presentation/control plane.

Do not copy another owner's private files or policy into Loop to avoid making the
owner change.

## 7. Learning return loop

Learning happens after evidence, not because every task feels educational.

```text
observed task evidence
  -> Finding / Proposal when justified
  -> human-governed Learning decision where required
  -> durable LearningNote in agent-learning
  -> Recall consumes a low-authority precedent projection
  -> later tasks may receive bounded relevant precedent
```

A one-off implementation `REPLAN` is not automatically durable Learning.
Repeated evidence is the reason to promote guidance, not the mere existence of a
new idea.

## 8. Host asset install / repair

Use this when repository-host integration needs setup or repair.

```bash
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init install-assets --agent=all
vendor/bin/agent-loop init host-status --format=json
vendor/bin/agent-loop init doctor
```

`host-status` is the front door for repository-owned integration actions.
Runtime/user-owned host settings remain outside repository authority.

## 9. Human review of one task

Humans should inspect the decision/evidence boundary, not replay every internal
step.

Useful views:

```bash
vendor/bin/agent-loop workflow status TASK-1 --format=json
vendor/bin/agent-loop workflow report TASK-1 --format=json
vendor/bin/agent-loop verify --task-id=TASK-1
```

For review, surface the exact current report/verdict/findings and the diff or
other raw evidence that supports them.

## 10. Release work

A release is ordinary governed repository work plus release-specific evidence.
Do not treat a missing tag as mystical external weather.

Current repositories use marker-driven releases where configured:

```text
release-ready code/changelog
  -> exact-head validation
  -> merge
  -> immutable release marker
  -> tag created from the intended immutable commit
  -> verify tag/release evidence
```

Existing tags are immutable evidence. Never retarget them.

Release work should not silently become product redesign. If the release exposes
a real compatibility or dependency defect, fix that defect as its own evidenced
change.

## Authority rule across every workflow

The Markdown here explains intent. The current structured lifecycle result owns
ordering and authority.

When they disagree:

```text
current kernel result > remembered procedure > prose example
```

That rule is what keeps workflow documentation useful without making every docs
edit a lifecycle migration.