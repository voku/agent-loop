# Start Here

`agent-loop` has several audiences. Pick the shortest route instead of reading
every architecture document in the repository like a punishment ritual.

## I am using agent-loop as a developer

Read:

1. [README.md](README.md) for installation and product overview.
2. [HUMANS.md](HUMANS.md) for what you decide and what the agent should handle.
3. [WORKFLOWS.md](WORKFLOWS.md) when you want the end-to-end flow for a specific
   kind of work.

For a detailed first task, use [docs/quick-start.md](docs/quick-start.md).

## I am a coding agent working in this repository

Read:

1. [AGENTS.md](AGENTS.md) for repository-specific authority, ownership, and
   implementation rules.
2. [AI.md](AI.md) for the compact operating loop.
3. [WORKFLOWS.md](WORKFLOWS.md) when the task crosses planning, validation,
   recovery, Learning, release, or package-owner boundaries.

Then use the current lifecycle result as authority:

```text
enter <task>
  -> obey next_action_kind / next_action
  -> perform host-native work when authorized
finish <task>
  -> obey next_action_kind / next_action
  -> complete
```

## I am trying to understand the architecture

Start with the ownership map in [AGENTS.md](AGENTS.md), then use the focused docs
under `docs/architecture/` and the compatibility/state contract in
[docs/compatibility.md](docs/compatibility.md).

Do not start by reading every historical issue or changelog entry. Git already
stores history; current docs should describe the current system.

## I am debugging a workflow

Use current structured evidence first:

```bash
vendor/bin/agent-loop init host-status --format=json
vendor/bin/agent-loop workflow status TASK-1 --format=json
vendor/bin/agent-loop workflow report TASK-1 --format=json
vendor/bin/agent-loop verify --task-id=TASK-1
```

A repeated canonical command that deterministically refuses without changing the
next action is a workflow defect, not an invitation to invent a private sequence.

## Documentation rule

These top-level files are allowed to repeat the important path. Discoverability
wins over perfect DRY documentation.

Deeper documents may explain details, but they should not require a human or
agent to reconstruct the ordinary workflow from scattered implementation notes.