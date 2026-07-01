---
name: agent-guidance-maintenance
description: Maintain repo-managed agent skills, shared agent docs, metadata roots, and migration-safe validation workflows around agent-loop init.
---

# Agent Guidance Maintenance

Use this skill for the repository-managed agent guidance itself: skills,
asset docs, validation flow, and host-repo migration notes.

## Fast Path

For a normal repo-managed guidance fix:

1. Edit the canonical source under `docs/agents/skills/` or `docs/agents/`.
2. Keep the fix small and scoped to the agent-guidance surface.
3. Validate the local source layout with the relevant `vendor/bin/agent-loop init validate --kind=...` command.
4. Re-run `vendor/bin/agent-loop init doctor` when path or migration guidance changed.
5. Update README, changelog, or migration notes when the public `init` contract changed.
6. When a host repo uses RTK, audit its `AGENTS.md`, `README.md`, and agent-facing Make targets for missing RTK guidance at Docker/Make boundaries.

## Skill Boundary

This skill owns:

- repo-managed skills under `docs/agents/skills/`
- shared agent docs under `docs/agents/`
- path-layout guidance for host repositories adopting `agent-loop init`
- migration notes for legacy Makefile and wrapper-script workflows
- RTK usage guidance at the outer shell boundary and nested Make/Docker layers
- validation guidance for `init doctor`, `init validate`, and `init sync-*`

This skill does not own:

- product implementation outside the agent-guidance surface
- host-repo local install targets as the source of truth
- pretending reserved `scaffold` behavior is implemented before it exists

## Canonical Files

- `docs/agents/skills/`
- `docs/agents/INFO_Agents.md`
- `docs/agents/migration/`
- `README.md`
- `CHANGELOG.md`
- `src/Init/`
- `tests/Init*`

## When To Use

Use this skill when the task:

- adds or edits a repo-managed skill
- changes default asset roots or host override behavior
- documents how a host repo should migrate from private wrappers to `agent-loop init`
- updates the public validation/sync/install-plan contract

Do not use this skill for ordinary library feature work that is unrelated
to agent assets or migration guidance.

## Workflow

### 1. Update the canonical source first

Edit:

- the owning skill under `docs/agents/skills/`
- `docs/agents/INFO_Agents.md` when the shared workflow changes
- `docs/agents/migration/...` when importing real host-repo practices
- README and changelog when the public command surface changes

Do not start by editing a host repo's local `.codex/`, `.github/skills/`,
or other installed copies.

### 2. Keep migration notes honest

When adapting a host workflow:

- preserve the real source path and wrapper shape in the migration notes
- separate what `agent-loop init` can do now from what `scaffold` still does not
- prefer `sync` wording for repeatable repo-managed asset updates
- keep Google client aliases mapped through canonical `antigravity`
- check whether the host repo's `AGENTS.md`, `README.md`, and shared skills are missing explicit RTK guidance
- distinguish RTK-wrapped outer commands from noisy inner commands hidden behind `make`, `docker compose exec`, or wrapper scripts
- recommend dedicated `ai-*` Make targets when host repos mainly drive validation through Make and Docker
- keep target-manifest safety explicit so `sync-*` removes only stale managed entries

### 2a. Turn Learnings into Hard Constraints

When a distilled lesson describes a pattern that can be statically verified (e.g., forbidding specific calls, enforcing parameter types, avoiding redundant casts, or blocking dangerous reflection):
1. **Prefer Hard Constraints over Soft Memories**: Do not just write a note in a generic memory file; build a custom static analysis rule (e.g. PHPStan rule or phpcs sniff).
2. **Register the Active Constraint**: Create a metadata JSON file under the repository's active constraints registry linking it to the source proposal.
3. **Regenerate Autoloader / Bootstrap**: Run the host repository's autoloader or configuration generators to register the new rule class.
4. **Baseline Legacy Violations**: If there are pre-existing violations in the codebase, create a matching baseline file and include it in the static analysis configuration so the build/CI stays green for existing files while blocking any new violations.
5. **Verify**: Run static analysis on affected files to verify it successfully flags violations and accepts baselined/correct files.

### 3. Validate after changes

Run:

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
php bin/agent-loop init doctor
vendor/bin/phpunit --filter 'Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
```

### 4. Use host overrides instead of hardcoding one repo

When documenting a host repo:

- keep the portable default under `docs/agents/...`
- show the host-specific `.agent-loop/init.json` override
- keep path precedence explicit: CLI > config > defaults
- add RTK guidance where agents actually read it: `AGENTS.md`, `README.md`, and the agent-maintenance skill/docs

## Validation

- `vendor/bin/agent-loop init validate --kind=all`
- `vendor/bin/agent-loop init sync-skills --agent=codex --dry-run`
- `php bin/agent-loop init doctor`
- `vendor/bin/phpunit --filter 'Init|DispatcherTest'`
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`

## Example Triggers

- "Update the related skill so future repos migrate this correctly."
- "Document how the legacy Makefile targets map to agent-loop init."
- "Bring the host repo's agent docs into portable starter guidance."
