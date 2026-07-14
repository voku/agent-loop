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
php bin/agent-loop init validate --kind=all
php bin/agent-loop init install-plan --profile=wsl2 --agent=codex
php bin/agent-loop init sync-skills --agent=codex --dry-run
```

The same commands also work through the Composer proxy:

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init install-plan --profile=linux --agent=codex
vendor/bin/agent-loop init install-plan --profile=windows --agent=codex
vendor/bin/agent-loop init sync-subagents --agent=copilot --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
```

## Current Boundaries

`init doctor`:

- reads local repo state
- resolves source paths from defaults, config, and CLI overrides
- checks for migration-compatible Makefile targets
- does not write files
- does not install tools

`init validate --kind=skills|subagents|hooks|all`:

- validates the resolved `skills`, `subagents`, and `codex-hooks` source roots
- rejects unsafe skill directory names
- rejects empty or unreadable canonical asset files

`init sync-skills`, `init sync-subagents`, and `init sync-hooks`:

- copy canonical repo-managed assets into client target directories
- keep a local manifest of managed entries
- remove only stale manifest-managed entries
- refuse to overwrite unmanaged targets unless `--force` is given
- support `--dry-run` for host-repo review before copying

`init install-plan`:

- prints a reviewed Linux, WSL2, or Windows setup plan
- prompts installation and verification of ripgrep (`rg`)
- prompts installation and verification of RTK and Caveman
- does not execute the printed commands

## ctx Historical Search Preflight

[ctx](https://github.com/ctxrs/ctx) is an optional local agent-history search tool. Use it before
non-trivial workflow, migration, or guidance tasks when prior coding-agent
sessions may contain useful decisions, failed attempts, commands, or review
context.

Typical workflow:

```bash
ctx status
ctx sources
ctx search "<task / module / failure / command>"
ctx show event <ctx-event-id> --window 5
ctx locate event <ctx-event-id>
```

Keep the package boundary clear:

- `ctx` retrieves historical raw material from local sessions.
- `agent-loop` orchestrates task workflow and recall handoffs.
- `agent-learning` validates findings, proposals, and decisions.

Do not make `agent-loop` install ctx, run ctx setup, own the ctx SQLite
database, scrape transcripts, or treat ctx hits as durable memory. If ctx
material changes a learning conclusion, cite only bounded
`agent_history_reference` evidence in the learning artifact and verify it
against the current repository.

## RTK And Nested Shell Boundaries

Install-plan output should prompt `ripgrep` before RTK/Caveman usage, because
`rg` is the baseline fast-search command expected by coding agents. Verify it
with:

```bash
rg --version
```

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

## Remaining Reserved Command

This command is still intentionally reserved today and exits `1` with
`not implemented yet`:

- `init scaffold --profile=wsl2 --agent=...`

Host-repo migration docs should treat `scaffold` as backlog, while
`validate --kind=subagents|hooks|all` and `sync-*` are now runnable.

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
    "tools_root": "infra/doc/agents/tools",
    "recall_root": "infra/doc/agent-learning/recall-output"
  },
  "agents": {
    "gemini": {
      "status": "legacy_alias",
      "maps_to": "antigravity"
    }
  }
}
```

Then the host repo can move validation and sync first:

```bash
vendor/bin/agent-loop init doctor --config=.agent-loop/init.json
vendor/bin/agent-loop init validate --kind=skills --config=.agent-loop/init.json
vendor/bin/agent-loop init validate --kind=subagents --config=.agent-loop/init.json
vendor/bin/agent-loop init validate --kind=hooks --agent=codex --config=.agent-loop/init.json
vendor/bin/agent-loop init sync-skills --agent=codex --config=.agent-loop/init.json
```

See:

- `docs/agents/skills/agent-guidance-maintenance/SKILL.md`
- `docs/agents/skills/agent-learning/SKILL.md`
- `docs/agents/skills/agent-loop-workflow/SKILL.md`

## Operational agent-loop skills

These skills are shipped by `agent-loop` for coding agents working in
**consuming repositories**. They teach the agent how to operate the loop,
not how to develop `agent-loop` itself.

| Skill | Purpose |
| --- | --- |
| `agent-loop-task-start` | Start a governed task, open session working memory, compile initial recall context |
| `agent-loop-l2-context` | Compile and use recall/L2 meta-prompt artifacts without mistaking them for executed agent actions |
| `agent-loop-task-progress` | Record decisions, checkpoints, validation results, scope changes, and blocked states during implementation |
| `agent-loop-review-close` | Review, verify, and close a task safely, including accepted-risk handling |
| `agent-loop-learning-boundary` | Handle reusable knowledge after a task closes — capture findings, move through the proposal pipeline, respect the boundary between workflow evidence and durable guidance |

`agent-loop-task-progress` fills the middle of the loop — between task start
and review/close — where agents most often lose track of decisions, forget
scope changes, or silently accept risk without a record. Without it, the loop
has a head and a tail but no working memory discipline during the actual work.

`agent-loop-learning-boundary` closes the loop after review/close: it teaches
agents how to carry a finding forward without self-approving it as durable
guidance. The boundary rule — findings are not durable memory — is explicit,
and the skill tells agents when to skip the learning step entirely.

`agent-loop-workflow` remains the broad overview skill for understanding the
full command vocabulary and workflow shape. The five operational skills above
are smaller, focused activation targets for real agent sessions — a coding
agent picks the one that matches its current step rather than loading the full
workflow doc at every stage.

Host repositories can sync these skills into their own agent client directories
with:

```bash
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
```
