---
name: agent-learning
description: Capture reusable lessons about agent-loop workflow, validation, and migration boundaries before promoting them into durable agent guidance.
---

# Agent Learning

Use this skill to turn a completed implementation or migration pass into
reviewable guidance updates for `agent-loop` itself, without over-promoting
one local fix into a broad rule.

## Fast Path

For a normal post-task learning pass:

1. Check whether the lesson is already captured in `README.md`, `CHANGELOG.md`, `docs/agents/`, or `docs/workflow/`.
2. Keep the lesson bounded to one reusable workflow or migration seam.
3. Prefer updating an existing skill or doc over creating a new broad rule.
4. Validate the affected command or docs path after the change.
5. Record the migration seam in `docs/agents/migration/` when the lesson comes from a real host repository.

## Skill Boundary

This skill owns:

- learning from `agent-loop` workflow and `init` dogfooding
- learning from host-repo migration seams that should become portable guidance
- deciding whether a lesson belongs in `docs/agents/`, `docs/workflow/`, README, or changelog

This skill does not own:

- changing host-repo product logic
- inventing durable rules without current repo evidence
- treating reserved `sync-*` commands as already solved

## Canonical Files

- `docs/agents/skills/agent-guidance-maintenance/SKILL.md`
- `docs/agents/INFO_Agents.md`
- `docs/agents/migration/`
- `docs/workflow/learning-boundary.md`
- `README.md`
- `CHANGELOG.md`
- `src/Init/`
- `tests/Init*`

## When To Use

Use this skill when the task:

- exposed a reusable `init` validation or migration lesson
- showed a gap between current behavior and the docs
- imported real host-repo practices that should shape future `agent-loop` features

Do not use this skill when the change is routine and teaches nothing beyond
one narrow local edit.

## Workflow

### 1. Check existing guidance first

Inspect:

- `docs/agents/INFO_Agents.md`
- the relevant skill under `docs/agents/skills/`
- `docs/agents/migration/...`
- `README.md`

If the rule already exists, refine the existing home instead of creating a
duplicate note.

### 2. Keep the lesson specific

Good lessons:

- point to a real command, path, or migration seam
- include current-state verification
- explain whether the behavior works now or is still reserved
- distinguish shell-boundary RTK help from deeper nested Make/Docker noise

Weak lessons:

- generic advice with no command or file anchor
- host-specific behavior presented as the portable default

### 3. Validate the claimed behavior

Use the smallest proof that matches the lesson:

```bash
php bin/agent-loop init doctor
php bin/agent-loop init validate --kind=skills
vendor/bin/phpunit --filter 'Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
```

### 4. Promote into the owning guidance file

Promotion targets:

- `docs/agents/skills/...` for repeatable agent workflow behavior
- `docs/agents/INFO_Agents.md` for shared operational guidance
- `docs/agents/migration/...` for host-repo migration notes
- `README.md` for public package behavior
- `CHANGELOG.md` for released or unreleased package changes

## Validation

- `php bin/agent-loop init doctor`
- `php bin/agent-loop init validate --kind=skills`
- `vendor/bin/phpunit --filter 'Init|DispatcherTest'`
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`

## Example Triggers

- "This workaround should become portable guidance."
- "Capture what we learned from migrating private agent wrappers to init."
- "Make sure the docs match what init actually does today."
