# Coding-Agent Guide

This file is a compact operating guide for coding agents working in a repository
that uses `agent-loop`.

It does not replace `AGENTS.md`, installed skills, or the lifecycle kernel.
`AGENTS.md` contains repository-specific implementation and ownership rules. The
kernel remains authoritative for lifecycle ordering and mutation readiness.

## 1. Start from the current repository, not memory

Before implementation, inspect the current checkout and current task evidence.
Do not assume a previous conversation, old issue description, or remembered
workflow is still current.

If host integration is uncertain:

```bash
vendor/bin/agent-loop init host-status --format=json
```

Follow its `next_action_kind` / `next_action` until no repository-owned host
integration action remains.

## 2. Enter durable work through the lifecycle

For a durable task:

```bash
vendor/bin/agent-loop enter TASK-1 --format=json
```

Read the returned result. Do not invent an intermediate sequence.

`next_action_kind` means:

- `command`: execute `next_action` as written;
- `command_template`: fill model-owned placeholders from the actual request and
  repository evidence, then execute it;
- `decision_required`: present the exact human-owned decision and evidence;
- `host_work`: perform the described implementation/model work;
- `none`: there is no further lifecycle action.

Never fabricate approval, accepted risk, or another human-owned decision.

## 3. Navigate with the cheapest reliable mechanism

Do not build Map merely because Map exists.

Prefer focused source reads, `rg`, and `rg --files` for known files/symbols,
literals, config/templates, exception text, and local tests.

Use `agent-map` when the question is structural or relational, for example:

- unknown implementation ownership;
- callers/callees;
- cross-file impact;
- provenance/value flow;
- refactoring scope;
- related production/test symbols.

If a relevant fresh Map already exists, use it earlier because its build cost is
already paid. Avoid repeating equivalent discovery with both Map and grep merely
to satisfy a ritual.

## 4. Implement the smallest approved solution

Inside approved scope:

- prefer a small direct change over a new abstraction;
- use the owning package/API instead of reconstructing another package's private
  files or JSON semantics;
- keep derived evidence non-authoritative until the owning gate accepts it;
- fail closed on stale provenance or ambiguous authority before mutation;
- preserve raw evidence such as diffs, validation output, and decisive errors.

If a supposedly necessary approach starts producing avoidable machinery,
repeated repair, or contradictory evidence, challenge the premise before adding
more machinery.

The bounded premise check is:

1. What approved outcome must remain true?
2. Which assumption is making the current route complex?
3. Does current evidence still support that assumption?
4. Is there a materially simpler route preserving Goal, acceptance, scope, and
   authority?

Same-intent implementation replanning is agent-owned. Changing human-owned
intent or risk is not.

## 5. Finish through the lifecycle

After host-native mutation:

```bash
vendor/bin/agent-loop finish TASK-1 --format=json
```

Follow the returned next action until `next_action_kind=none` and the task is
complete.

Do not manually reconstruct validation, review, Learning, or close-out ordering
from documentation. `finish` owns the current deterministic sequence.

If validation fails and the next action is to fix the implementation, fix it and
call `finish` again. Re-running the same failing validation without changing the
cause is activity, not progress.

## 6. Use human attention only where authority requires it

Do not ask the human to approve model-owned mechanics merely because a command
contains placeholders or because multiple implementation choices exist.

Escalate when the lifecycle says `decision_required`, especially for:

- Contract approval or changed approved intent;
- changed Goal, acceptance, scope, or non-goals;
- accepted risk or irreversible actions;
- public/product-contract changes not already authorized.

Show the exact subject. Avoid opaque confirmation prompts.

## 7. Cross-package work follows ownership

When work exposes a missing semantic capability in another `agent-*` package:

```text
identify semantic owner
  -> make the smallest owner change
  -> prove it in the owner
  -> consume the public owner surface
  -> prove the integration
```

Do not copy private owner semantics into Loop just to avoid a cross-package
change.

## 8. Evidence before claims

Never claim a hook fired, validation passed, CI is green, a PR merged, or a
release shipped without current evidence.

When blocked, distinguish:

- implementation failure;
- workflow defect;
- unavailable host capability;
- genuine human-authority decision.

Do not hide a deterministic workflow refusal behind a private workaround.

## 9. Untracked exploration is different

For investigation without a durable task, use an ephemeral session instead of
inventing board/task authority. Read-only exploration does not need fake
approval state.

## 10. Primary references

- [AGENTS.md](AGENTS.md): repository ownership and implementation rules.
- [WORKFLOWS.md](WORKFLOWS.md): end-to-end workflow examples.
- [HUMANS.md](HUMANS.md): human authority and supervision model.
- [docs/quick-start.md](docs/quick-start.md): detailed first-task walkthrough.

When prose and the current lifecycle result disagree, the current lifecycle
result wins for ordering and authority.