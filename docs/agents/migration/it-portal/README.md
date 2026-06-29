# IT-Portal Migration Reference

This directory captures the real IT-Portal agent-asset workflow that
`agent-loop init` is intended to replace over time.

The goal here is not to claim feature parity today. The goal is to keep the
real source material close to `agent-loop` while we migrate the behavior in
small, testable steps.

## Imported Source Families

These are the main upstream sources used for this reference set:

- `~/PhpstormProjects/IT-Portal/infra/doc/agents/skills/itp-agent-guidance-maintenance/SKILL.md`
- `~/PhpstormProjects/IT-Portal/infra/doc/agents/skills/itp-agent-learning/SKILL.md`
- `~/PhpstormProjects/IT-Portal/infra/doc/INFO_Agents.md`
- `~/PhpstormProjects/IT-Portal/AGENTS.md`
- `~/PhpstormProjects/IT-Portal/Makefile`
- `~/PhpstormProjects/IT-Portal/scripts/private/agent_assets_lib.sh`
- `~/PhpstormProjects/IT-Portal/scripts/private/validate_agent_skills.sh`
- `~/PhpstormProjects/IT-Portal/scripts/private/validate_agent_subagents.sh`
- `~/PhpstormProjects/IT-Portal/scripts/private/install_*skills.sh`
- `~/PhpstormProjects/IT-Portal/scripts/private/install_*agents.sh`
- `~/PhpstormProjects/IT-Portal/scripts/private/validate_codex_hooks.sh`
- `~/PhpstormProjects/IT-Portal/scripts/private/install_codex_hooks.sh`
- `~/PhpstormProjects/IT-Portal/scripts/private/validate_codex_hooks.php`
- `~/PhpstormProjects/IT-Portal/scripts/private/install_codex_hooks.php`
- `~/PhpstormProjects/IT-Portal/scripts/private/codex_hooks_lib.php`

## What Was Adapted Into agent-loop

Portable starter guidance now lives in:

- `docs/agents/skills/agent-guidance-maintenance/SKILL.md`
- `docs/agents/skills/agent-learning/SKILL.md`
- `docs/agents/INFO_Agents.md`

These are adapted from the IT-Portal `itp-agent-*` pair and the
`INFO_Agents.md` operating model, but stripped of repo-specific PHP,
Docker, and REMONDIS details.

## RTK Audit Requirement For Host Docs

When migrating a host repo, do not stop at install instructions.

Also audit:

- `AGENTS.md`
- `README.md`
- agent-maintenance skills
- agent-facing Make targets

Look for missing RTK guidance where agents actually run commands.

Important distinction:

- RTK helps directly at the outer shell boundary
- Make and Docker can still emit noisy output one layer deeper

That means a host repo should usually document both:

- direct RTK command usage such as `rtk docker compose ps`
- dedicated low-noise `ai-*` Make targets for nested workflows

## Migration Matrix

Current implemented mappings:

| IT-Portal target | Current agent-loop replacement |
| --- | --- |
| `validate_agent_skills` | `vendor/bin/agent-loop init validate --kind=skills --config=.agent-loop/init.json` |
| `doctor`-style path/setup diagnosis | `vendor/bin/agent-loop init doctor --config=.agent-loop/init.json` |
| reviewed Linux/WSL2 setup notes | `vendor/bin/agent-loop init install-plan --profile=<wsl2|linux> --agent=<agent>` |

Current implemented sync and validation mappings:

| IT-Portal target | Current agent-loop replacement |
| --- | --- |
| `validate_agent_subagents` | `vendor/bin/agent-loop init validate --kind=subagents --config=.agent-loop/init.json` |
| `validate_codex_hooks` | `vendor/bin/agent-loop init validate --kind=hooks --agent=codex --config=.agent-loop/init.json` |
| `install_codex_skills` | `vendor/bin/agent-loop init sync-skills --agent=codex` |
| `install_copilot_skills` | `vendor/bin/agent-loop init sync-skills --agent=copilot` |
| `install_claude_skills` | `vendor/bin/agent-loop init sync-skills --agent=claude` |
| `install_gemini_skills` | `vendor/bin/agent-loop init sync-skills --agent=gemini` |
| `install_antigravity_skills` | `vendor/bin/agent-loop init sync-skills --agent=antigravity` |
| `install_copilot_agents` | `vendor/bin/agent-loop init sync-subagents --agent=copilot` |
| `install_gemini_agents` | `vendor/bin/agent-loop init sync-subagents --agent=gemini` |
| `install_antigravity_agents` | `vendor/bin/agent-loop init sync-subagents --agent=antigravity` |
| `install_codex_hooks` | `vendor/bin/agent-loop init sync-hooks --agent=codex` |

Still backlog:

| IT-Portal target | Planned agent-loop command |
| --- | --- |
| repo-local asset scaffolding | `vendor/bin/agent-loop init scaffold --profile=wsl2 --agent=codex --dry-run` |

## Real Used Code Shape

IT-Portal currently uses:

- a Makefile target layer
- thin Bash wrappers per target
- a shared Bash library in `scripts/private/agent_assets_lib.sh`
- PHP hook validation/install code for Codex hooks
- Python helpers for skill and subagent validation/rendering

For `agent-loop`, the migration direction is:

- keep thin host-repo wrappers optional
- move portable validation/sync logic into `src/Init/`
- keep host-repo path differences in config files
- prefer PHP for portable local tooling
- make RTK expectations explicit in host docs instead of assuming invisible Codex shell rewriting

## Suggested RTK Command Shapes For Host Repos

Direct commands:

```bash
rtk git status
rtk docker compose ps
rtk docker compose logs --tail=200 php
rtk docker compose logs --tail=200 db
rtk test docker compose exec -T php vendor/bin/phpunit
rtk test docker compose exec -T php vendor/bin/phpstan analyse --memory-limit=1G
rtk err docker compose exec -T php php scripts/private/check.php
```

Raw passthrough when needed:

```bash
rtk proxy docker compose logs php
```

And when the repo mostly uses Make and Docker, add `ai-*` targets rather
than assuming `rtk make ...` will fully compress nested output.

## Example Host-Repo Config

See:

- `examples/agent-assets/it-portal-migration/.agent-loop/init.json`
- `examples/agent-assets/it-portal-migration/Makefile.agent-assets.mk`
- `examples/agent-assets/it-portal-migration/scripts/private/`

These examples preserve the IT-Portal path shape while switching the
implemented validation flow to `agent-loop init`, and they include an
RTK-aware host-doc and Makefile pattern.
