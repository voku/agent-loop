# Agent Assets In agent-loop

## Purpose

This document defines the portable, repo-managed agent-asset layout that
`agent-loop init` is designed to validate and later sync.

The canonical source roots in this repository are:

- `docs/agents/skills/`
- `docs/agents/subagents/`
- `docs/agents/codex-hooks/`
- `docs/agents/tools/`

Host repositories may override these roots with `--config` or CLI path
options. This is how an older host layout such as
`infra/doc/agents/...` can adopt `agent-loop init` without forking the
command surface.

## Current Commands

Use the implemented `init` commands for the current portable workflow:

```bash
php bin/agent-loop init doctor
php bin/agent-loop init validate --kind=skills
php bin/agent-loop init install-plan --profile=wsl2 --agent=codex
```

The same commands also work through the Composer proxy:

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init validate --kind=skills
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init install-plan --profile=linux --agent=codex
```

## Current Boundaries

`init doctor`:

- reads local repo state
- resolves source paths from defaults, config, and CLI overrides
- checks for migration-compatible Makefile targets
- does not write files
- does not install tools

`init validate --kind=skills`:

- validates `*/SKILL.md` presence under the resolved skills root
- rejects unsafe skill directory names
- rejects empty or unreadable `SKILL.md` files

`init install-plan`:

- prints a reviewed Linux or WSL2 setup plan
- does not execute the printed commands

## RTK And Nested Shell Boundaries

RTK helps most at the shell boundary the agent actually executes.

That means these benefit directly:

```bash
rtk git status
rtk docker compose ps
rtk docker compose logs --tail=200 php
rtk test vendor/bin/phpunit --filter Init
```

But many real repo workflows are layered:

```bash
make phpstan
docker compose exec php php scripts/foo.php
docker compose logs db
```

In those cases, RTK wraps the outer command the agent sees, while noisy
output can still be produced one layer deeper by Make, Docker, or shell
scripts.

### Practical host-repo rule

When a host repo adopts `agent-loop init`, also audit its:

- `AGENTS.md`
- `README.md`
- agent-guidance skills
- Makefile targets used by agents

Look for missing RTK guidance around:

- `docker compose ps`
- `docker compose logs`
- `docker compose exec ...`
- `make test`
- `make phpstan`
- DB diagnostics and PHP script entrypoints

### Preferred command shapes

Prefer:

```bash
rtk docker compose ps
rtk docker compose logs --tail=200 db
rtk docker compose logs --tail=200 php
rtk test docker compose exec -T php vendor/bin/phpunit --filter Init
rtk err docker compose exec -T php php scripts/private/check.php
```

Use raw passthrough only when the filtered output is hiding something you
actually need:

```bash
rtk proxy docker compose logs php
```

### Prefer AI-oriented Make targets in host repos

If the real workflow is mostly `make` and `docker`, host repos should add
explicit low-noise targets for agents instead of assuming `rtk make ...`
will fully compress nested output.

Example pattern:

```makefile
.PHONY: ai-status
ai-status:
	rtk git status
	rtk docker compose ps

.PHONY: ai-phpstan
ai-phpstan:
	rtk test docker compose exec -T php vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: ai-tests
ai-tests:
	rtk test docker compose exec -T php vendor/bin/phpunit

.PHONY: ai-php-logs
ai-php-logs:
	rtk docker compose logs --tail=200 php

.PHONY: ai-db-logs
ai-db-logs:
	rtk docker compose logs --tail=200 db
```

For Codex specifically, do not rely on an invisible shell-rewrite story.
Keep the RTK preference explicit in repository docs such as `AGENTS.md`
and `README.md`.

## Reserved Commands

These commands are intentionally reserved today and exit `1` with
`not implemented yet`:

- `init validate --kind=subagents`
- `init validate --kind=hooks --agent=codex`
- `init validate --kind=all`
- `init sync-skills --agent=...`
- `init sync-subagents --agent=...`
- `init sync-hooks --agent=codex`
- `init scaffold --profile=wsl2 --agent=...`

Keep host-repo migration docs honest about this boundary. Do not present
`sync-*` as runnable until the internals exist in `agent-loop`.

## Host-Repo Migration Pattern

For a host repo that still stores agent assets under `infra/doc/agents/`,
check in a small config file such as:

```json
{
  "version": 1,
  "paths": {
    "skills_root": "infra/doc/agents/skills",
    "subagents_root": "infra/doc/agents/subagents",
    "codex_hooks_root": "infra/doc/agents/codex-hooks",
    "tools_root": "infra/doc/agents/tools"
  },
  "agents": {
    "gemini": {
      "status": "legacy_alias",
      "maps_to": "antigravity"
    }
  }
}
```

Then the host repo can move validation first:

```bash
vendor/bin/agent-loop init doctor --config=.agent-loop/init.json
vendor/bin/agent-loop init validate --kind=skills --config=.agent-loop/init.json
```

See:

- `docs/agents/skills/agent-guidance-maintenance/SKILL.md`
- `docs/agents/skills/agent-learning/SKILL.md`
- `docs/agents/skills/agent-loop-workflow/SKILL.md`
