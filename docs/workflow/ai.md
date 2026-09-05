# Coding-agent field guide

This is a compact operating guide for coding agents using `agent-loop`. It routes into executable lifecycle authority; it does not replace the lifecycle kernel, installed package skills, or repository-specific `AGENTS.md` instructions.

## Durable work

For a durable task, use the lifecycle front doors:

```bash
vendor/bin/agent-loop enter <task-id> --format=json
```

Read the returned `next_action_kind`, `next_action`, mutation readiness, and owner-backed references. Follow that current result instead of reconstructing a remembered Map/Session/Recall/review sequence.

When host-native implementation work is authorized, make the smallest correct change using the repository's normal tools. Do not invent approval, risk ownership, or another human decision.

After host-native mutation, return through:

```bash
vendor/bin/agent-loop finish <task-id> --format=json
```

Obey the returned lifecycle result until it reports no further action.

In short:

```text
enter -> current next action -> work when authorized -> finish -> current next action -> complete
```

The kernel owns exact ordering and close readiness. This document intentionally does not enumerate lifecycle gates.

## Use specialized front doors only when they fit

`quick`, `repair`, and `pipeline` are host conveniences, not permission to bypass the current task authority.

- Use `quick` only for a genuinely surgical micro-task that fits the command's bounded file/scope constraints. If the fast-path refuses because the change grew beyond those bounds, move to an ordinary governed task instead of weakening the bounds.
- Use `repair` only after validation has recorded a concrete failure. Apply the projected repair instruction, return through `finish`, and escalate when the bounded repair budget is exhausted rather than looping privately.
- Use `pipeline` only when the task has a governed execution profile. Follow its projected stage, mutation flag, accepted outcomes, handoff, and attention state; when it reports complete, return through `finish`.

Do not reconstruct the internal stage sequence from this prose. Consume the current structured result from the command you are using.

## Treat authority explicitly

- `decision_required` means present the exact decision subject and current evidence to the human.
- model-owned templates and implementation choices inside approved authority are agent work, not ceremonial human approval points.
- generated or derived evidence does not override the semantic owner that decides readiness.
- if a canonical action deterministically refuses without changing the state or next action, report the workflow defect instead of creating a private workaround.

For the exact structured lifecycle semantics, read [lifecycle.md](lifecycle.md).

## Work from current evidence

Inspect the current checkout, task state, diff, validation, CI, and owner projections relevant to the request. Do not treat old chat context, historical issue prose, or remembered repository layout as fresher evidence than the repository itself.

Never claim a check passed, CI is green, a pull request merged, or a release shipped without current evidence.

## Keep specialist tools specialist

Use Map, Recall, Session, Learning, review, edit, and recovery commands when the current task or lifecycle result needs them. Their existence does not make them mandatory phases.

For read-only investigation without durable task authority, use the repository's exploration/session facilities rather than inventing a Contract or approval state.

## Repository-specific work

When contributing to `voku/agent-loop` itself, [`AGENTS.md`](../../AGENTS.md) is the repository-specific ownership and implementation guide. In other repositories, follow that repository's own host-discovered instructions instead.

See [../quick-start.md](../quick-start.md) for a concrete first task and [README.md](README.md) for named workflow routes.
