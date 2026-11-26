# Human authority

`agent-loop` keeps humans at authority boundaries without turning them into shell-command remote controls.

The executable lifecycle result is authoritative. This document describes the supervision boundary; it does not decide when a gate is satisfied or what action comes next.

## Human-owned decisions

When the lifecycle returns `next_action_kind=decision_required`, the agent must present the exact current decision subject and supporting evidence.

Typical human-owned subjects include:

- approval of the exact durable task intent;
- a material change to Goal, scope, acceptance criteria, or non-goals;
- acceptance of meaningful risk;
- destructive or irreversible choices;
- another authority-bearing decision explicitly surfaced by the lifecycle.

The examples are not a second gate list. The current lifecycle result decides whether human authority is required.

## Agent-owned work inside approved authority

Once current authority permits the work, the agent should normally handle model and implementation mechanics itself: repository navigation, filling model-owned command templates from current evidence, implementation choices inside scope, validation repair, and other delegated actions returned by the lifecycle.

Do not ask a human to approve a shell command merely because the agent can execute it, and do not fabricate approval merely because continuing would be convenient.

### Who decided and who recorded it

A decision record names the decision-maker in its existing actor field (`approved_by`, `rejected_by`, `acknowledged_by`). When someone else enters that decision, for example an agent recording a human's explicit approval from the chat, the recording command takes `--recorded-by <recorder>` (`workflow approve`, `workflow reject`, `workflow request-changes`, `quick`, and `finish --reviewed-report-sha256`). The record then also stores `recorded_by` and `authority_evidence: {"status": "self_asserted"}`.

Both fields are attribution, not authenticated identity: nothing verifies that the named human decided or that the recorder is who it says. `self_asserted` is the only status any command can write; `workflow status` shows it as "recorded by <recorder> (self-asserted, not verified)". A record without the fields only means that no separate recorder was declared, not that the decision-maker entered it. Records written without `--recorded-by` are byte-identical to earlier versions.

## What evidence should return to the human

For non-trivial work, report concrete evidence rather than confidence:

- what changed and why;
- the validation or other decisive evidence actually observed;
- any current blocker or human-owned decision;
- the current lifecycle outcome, including the next action when work is not complete.

Claims such as CI being green, a pull request being merged, or a release being shipped require current evidence.

## Advisory decision recommendations

The host-neutral human-decision JSON projection includes a typed `recommendation` for every
`decision_required` outcome, including Contract approval, review
acknowledgement, Learning disposition, risk acceptance, supersession, and
otherwise unknown human authority gates. A recommendation may abstain.
Conservative deterministic advice is available without provider calls.

A model adapter is **optional, off by default**, and provider-neutral at the
PHP interface. For the currently verified System One choice protocol,
the project can explicitly configure
`interaction.decision_advice` in `.agent-loop/init.json`:

```json
{
  "interaction": {
    "decision_advice": {
      "mode": "shadow",
      "provider": "system_one",
      "endpoint": "https://trusted-public-provider.example/v1/systemone",
      "data_classification": "public_metadata",
      "timeout_ms": 1000
    }
  }
}
```

The project configuration describes the *requested* provider, not the
operator's permission to contact it or read credentials. An auto-projected
human gate requires a separate, exact process-environment grant from the
trusted host/launcher, **not** another key in repository-owned `init.json`:

```bash
export AGENT_LOOP_DECISION_APPROVED_ENDPOINT='https://trusted-public-provider.example/v1/systemone'
# Only when init.json names token_env=DECISION_MODEL_TOKEN:
export AGENT_LOOP_DECISION_APPROVED_TOKEN_ENV='DECISION_MODEL_TOKEN'
```

Without matching grants the optional model remains disabled and the
conservative human decision stays available. A project's `token_env` can
never authorize reading that variable by itself. Keep host grants out of
committed scripts and project-controlled dotenv loaders. Explicit
`agent-loop decision route` CLI invocations are separately initiated by
their caller and must not be treated as automated human-gate permission.

Only decision type and bounded structural counts are transmitted, not source
code, task identifiers, goal text, review content, file paths, or private
Contract details. `shadow` records model output without replacing the
deterministic suggestion; explicit `assist` can display its bounded choice.
No inference is enabled by a credential merely being present. An unavailable
provider leaves the human question and its deterministic advice intact.

Model suggestions are **not approvals**. They do not change
`next_action_kind`, `mutation_ready`, review acknowledgement, accepted
risk, durable Learning, or the authorized Contract revision. Even a model
recommendation to approve must be verified against the exact human-readable
subject. Public structural metadata cannot establish that the proposed
work is safe, so treat model confidence as uncalibrated.

## Presenting a human decision

A useful decision request makes the authority boundary visible without asking the human to reconstruct repository state or operate the agent's shell.

Present, in this order:

1. the exact decision subject surfaced by the current lifecycle result;
2. the smallest evidence that materially bears on that decision;
3. the consequences of the available choices when they are not obvious;
4. the decision the human actually owns.

Do not replace the subject with a generic question such as "continue?" or "approve?". Do not include routine agent-owned commands as choices merely because they happen next mechanically.

For Contract approval, `agent-loop enter` renders the exact candidate, the
scope of approval, and what the available responses do. It offers three
distinct responses:
approve the exact revision, request changes and keep it unapproved, or decline
and leave the task blocked. Each response has its own command on the card:
`workflow approve`, `workflow request-changes` and `workflow reject`, all bound
to the exact revision and taking the named human as `--by`. A change request and
a rejection both end that revision without approval; the Contract records which
one it was (`rejection_kind`), and the next revision's card names the kind, the
actor and the reason. Record a response only when that human gave it. A
recommendation is not approval and must never be recorded as the human's choice.

Projects may set `interaction.decision_presentation` to `cli` (the default) or
`html` in `.agent-loop/init.json`. It prefers the existing agent-ui view for
Contract approval when the control plane is enabled and reports ready. The
current agent-ui decision labels are English, so a non-English configured
language keeps the full overview in the CLI. If the control plane is disabled,
missing, or unreachable, the CLI also shows the full decision overview. This
preference changes only Contract approval presentation.
`interaction.control_plane` continues to configure the local agent-ui endpoint.

### Example: approve durable task intent

If the current decision is approval of a new or revised Contract, show the proposed authority-bearing intent rather than a shell command:

```text
Decision required: approve Contract revision 3 for TASK-123.

Goal:
Expose the current owner-backed status in the UI.

Scope:
- src/Feature/Status/
- tests/Feature/Status/

Acceptance:
- status comes from the typed owner API;
- existing unsupported-owner state remains neutral.

Changed since the approved revision:
- scope adds tests/Feature/Status/ for regression coverage;
- Goal and acceptance are unchanged.

Human decision:
approve this revision, request changes, or decline while leaving it unapproved.
```

The agent may explain why the revision is proposed, but it must not approve the revision itself.

### Example: accept meaningful residual risk

If validation exposes a real residual risk and the lifecycle asks the human to accept or reject it, present the observed evidence and the bounded consequence:

```text
Decision required: accept the current compatibility risk for TASK-456.

Observed evidence:
- PHP 8.3 and 8.4 validation passed;
- the PHP 8.5 lane cannot run because the required external service is unavailable;
- no evidence proves PHP 8.5 behavior either way.

Consequence:
accepting the risk allows work to proceed without PHP 8.5 evidence; rejecting it keeps the task blocked until equivalent evidence exists.

Human decision:
accept or reject that residual risk.
```

Do not rewrite missing evidence into a success claim. The lifecycle remains responsible for whether risk acceptance is a legal current action.

### Counterexample: agent-owned mechanics are not a decision

If the current result is `host_work`, `command`, or a model-owned `command_template`, the agent should normally execute or perform that work itself inside existing authority:

```text
Current action: run the repository's declared validation command.
```

Do not turn that into:

```text
May I run the validation command?
```

A human decision is required only when the lifecycle says authority is required, not whenever the next mechanical step is visible.

## Ordinary task shape

Humans should be able to think in terms of:

```text
state the intended outcome
  -> agent enters the durable task
  -> agent works inside approved authority
  -> agent finishes through the lifecycle
  -> receive evidence or an explicit authority decision
```

The human does not need to memorize Map, Session, Recall, validation, review, Learning, or close-out ordering. That ordering belongs to the lifecycle kernel.

See [lifecycle.md](lifecycle.md) for ownership and structured lifecycle semantics, and [../quick-start.md](../quick-start.md) for the concrete first-task walkthrough.
