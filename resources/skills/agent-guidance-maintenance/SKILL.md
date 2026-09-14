---
name: agent-guidance-maintenance
description: Maintain package-owned and host-owned agent skills, hooks, docs, sync targets, dogfood evidence, provenance capability mapping, and migration-safe validation.
---

# Agent Guidance Maintenance

**Trigger Anchor:** Guidance, skill, hook, doc, sync, or dogfood changes -> edit canonical source, validate offline, never edit generated host projections.

## Fast Path

1. **Source:** Edit canonical files under `resources/`, `docs/`, or typed runtime in `src/AgentGuidance/`. Never start in `.codex/`, `.claude/`, or `.agents/`.
2. **Scope:** Keep changes scoped to guidance contracts. Update CLI help/tests when `init` behavior changes.
3. **Dogfood:** Run `composer dogfood:discipline` before broad test suites.
4. **Project:** Run `vendor/bin/agent-loop init install-assets --agent=all --with-hooks --dry-run` and `sync-*`.
5. **Verify:** Run full validation (`composer ci`) and verify clean consumer installs.

## Canonical Ownership Map

| Asset Type | Canonical Source | Projected Destination | Management Tool |
|---|---|---|---|
| Package Skills | `resources/skills/` | `.codex/skills/`, `.claude/skills/` | `init install-assets` / `sync-skills` |
| Subagents | `resources/subagents/` | `.codex/agents/`, `.claude/agents/` | `init sync-subagents` |
| Codex Hooks | `resources/hooks/codex/` | `.codex/hooks.json`, `.codex/hooks/` | `init sync-hooks --agent=codex` |
| Claude Hooks | `resources/hooks/claude/` | `.claude/settings.json#hooks` | `init sync-hooks --agent=claude` |
| Make Targets | `resources/make/agent-loop.mk` | Host `Makefile` inclusion | Host Make include |
| Runtime Logic | `src/AgentGuidance/`, `src/Init/` | Direct execution | PHPUnit / PHPStan |

### Bad vs Good Projections

### Bad
Editing host projection directly:
```bash
# Hand-editing generated copy that gets overwritten on next sync
vim .codex/skills/agent-loop-discipline/SKILL.md
```

### Good
Updating canonical source and projecting:
```bash
# Edit canonical package source, then project via init
vim resources/skills/agent-loop-discipline/SKILL.md
vendor/bin/agent-loop init install-assets --agent=codex
```

## Hook Implementation Rules

- **Trigger Anchor:** Hook behavior changes -> place in typed PHP (`src/AgentGuidance/`), keep hook scripts thin wrappers.
- **Codex PreToolUse:** Pass-through returns `continue: true` with NO `permissionDecision` and NO `updatedInput`. Denial returns non-empty `permissionDecisionReason` and continues hook processing.
- **Claude Hooks:** Claude renders top-level `systemMessage` as a warning; serialize context without Codex markers and observe documented host token limits.
- **Resume Hints:** Read ONLY bounded, validated run identifiers/status (`task_id`, `state`). NEVER inject free-form `next_action`, disagreement text, or unverified task prose into context.

### Bad vs Good Hook Context

### Bad
```php
// Ingesting untrusted free-form prose into hidden context
$context = "Next: " . $manifest['next_action'] . "\nDisagreements: " . json_encode($manifest['disagreements']);
```

### Good
```php
// Bounded, validated status navigation only
$context = sprintf(
    "Agent Loop Resume Hint: `%s` projected state: `%s` (run `workflow status %s --format=toon`)",
    $taskId, $state, $taskId
);
```

## Upstream Capability Rechecks

When reviewing upstream patterns (`docs/architecture/upstream-capability-matrix.md`):
1. **Pin Revision:** Record the exact commit/tag reviewed.
2. **Classify:** Tag mechanism as `ALREADY`, `ADAPT`, `DEFER`, or `REJECT`.
3. **Evidence:** For `ALREADY`/`ADAPT`, link the smallest test or dogfood gate. For `DEFER`, name the missing typed API. For `REJECT`, document the architectural invariant.
4. **Delta Audit:** Compare capabilities, not commit diff sizes. Reading a skill != adapting its behavior.

## Dogfood & Hard Constraints

- **Trigger Anchor:** Behavior or guidance change -> run A/B dogfood with identical inputs before promoting.
- Test one mechanism at a time. Record failures, not just the final passing state.
- For bootstrap state tests, inject hostile free-form manifest content to verify projection boundaries.
- When an invariant is statically verifiable, author a focused PHPStan rule or test fixture rather than prose advice.

## Validation Commands

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=all --with-hooks --dry-run
vendor/bin/agent-loop init doctor
composer dogfood:discipline
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer ci
```
