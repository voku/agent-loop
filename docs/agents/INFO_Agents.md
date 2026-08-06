# Agent Assets In agent-loop

## Purpose

This document defines the portable, repository-managed agent-asset layout that
`agent-loop init` validates and synchronizes.

Canonical source roots:

- `docs/agents/skills/`
- `docs/agents/subagents/`
- `docs/agents/codex-hooks/`
- `docs/agents/tools/`

Host repositories may override these roots through `.agent-loop/init.json` or
CLI path options. This lets an older layout such as `infra/doc/agents/...`
adopt the same command surface without forking it.

## Current Commands

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
vendor/bin/agent-loop init install-plan --profile=linux --agent=codex
vendor/bin/agent-loop init install-plan --profile=windows --agent=codex
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init sync-subagents --agent=copilot --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
```

The same commands work through `php bin/agent-loop` when developing this
repository directly.

## Current Boundaries

`init doctor`:

- reads local repository state;
- resolves source paths from CLI options, config, then defaults;
- checks migration-compatible repository hints;
- does not write files or install tools.

`init validate --kind=skills|subagents|hooks|all`:

- validates resolved canonical source roots;
- rejects unsafe directory names;
- rejects missing, empty, or unreadable assets.

`init sync-skills`, `init sync-subagents`, and `init sync-hooks`:

- copy canonical assets into client target directories;
- track managed entries in a local manifest;
- remove only stale manifest-managed entries;
- refuse to overwrite unmanaged targets unless `--force` is given;
- support `--dry-run` before any copy.

`init install-plan`:

- prints a reviewed Linux, WSL2, or Windows setup plan;
- prompts installation and verification of ripgrep (`rg`);
- prompts installation of Caveman for concise human-facing replies;
- prompts installation of Ponytail for minimal, requirement-scoped code;
- does not execute the printed commands;
- does not install a shell or output rewriting proxy.

`init tools`:

- probes `rg`, `git`, `php`, `composer`, and `docker`;
- reports whether the local agent-map index exists;
- stores the bounded result in a git-ignored cache.

## Communication, Implementation, And Evidence

Three concerns must remain separate:

1. **Communication:** keep agent progress and final replies concise enough for a
   human to review.
2. **Implementation:** prefer YAGNI, existing repository code, PHP standard
   library, platform features, installed dependencies, and the shortest correct
   diff.
3. **Evidence:** keep source files, diffs, test output, static-analysis output,
   and generated verification artifacts complete and unmodified.

Caveman helps with the first concern. Ponytail helps with the second. The
repo-managed `agent-loop-php-discipline` skill adapts both ideas to strict PHP
and the `agent-*` package boundaries while enforcing the third.

Do not place lossy output rewriting between an agent and evidence it must inspect.
A compressed `git diff` is not the diff. A rewritten redirected file is not the
file the harness intended to preserve. A summary may guide a human to the raw
artifact; it must not replace the artifact.

When a harness stores large output in a file, read that file as the source of
truth. Verify size, line count, or hash when completeness is material.

## ctx Historical Search Preflight

[ctx](https://github.com/ctxrs/ctx) is an optional local history search tool.
Use it before non-trivial workflow, migration, or guidance tasks when prior
sessions may contain relevant decisions, failed attempts, commands, or review
context.

```bash
ctx status
ctx sources
ctx search "<task / module / failure / command>"
ctx show event <ctx-event-id> --window 5
ctx locate event <ctx-event-id>
```

Keep package ownership clear:

- `ctx` retrieves historical raw material;
- `agent-loop` orchestrates task workflow and recall handoffs;
- `agent-learning` validates findings, proposals, and decisions.

Do not make `agent-loop` install ctx, own its database, scrape transcripts, or
treat search hits as durable memory. When history affects a learning conclusion,
record only bounded references and verify them against the current repository.

## Bind-Mounted Repository Files

When Docker Compose mounts the repository into a container, a host-written file
inside the repository is already visible under the matching repo-relative path.
Use a git-ignored scratch directory instead of copying the same file into the
container:

```bash
mkdir -p .agent-loop/tmp
printf '%s' "$payload" > .agent-loop/tmp/input.json
docker compose exec -T php php scripts/consume.php .agent-loop/tmp/input.json
```

Add `/.agent-loop/tmp/` to `.gitignore`. A real copy remains correct when the
container does not mount the repository.

## Minimal Workflow Scaffold

`init scaffold` creates the minimum board, task, session, and learning-root
structure plus a `DEMO-1` example. It preserves existing files and supports
`--dry-run`.

Asset validation and synchronization remain separate commands. Scaffolding a
workflow must not silently install agent plugins or overwrite client settings.

## Host-Repository Migration Pattern

A host repository with assets under `infra/doc/agents/` can check in:

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

Then migrate validation and synchronization first:

```bash
vendor/bin/agent-loop init doctor --config=.agent-loop/init.json
vendor/bin/agent-loop init validate --kind=skills --config=.agent-loop/init.json
vendor/bin/agent-loop init validate --kind=subagents --config=.agent-loop/init.json
vendor/bin/agent-loop init validate --kind=hooks --agent=codex --config=.agent-loop/init.json
vendor/bin/agent-loop init sync-skills --agent=codex --config=.agent-loop/init.json
```

Do not edit generated client copies first. Update canonical sources, validate,
then sync.

## Operational Skills

These skills are shipped for coding agents working in consuming repositories:

| Skill | Purpose |
| --- | --- |
| `agent-loop-task-start` | Start governed work and compile initial bounded recall |
| `agent-loop-l2-context` | Compile and inspect recall/L2 artifacts without pretending they executed actions |
| `agent-loop-task-progress` | Record decisions, checkpoints, exact validation results, scope changes, and blockers |
| `agent-loop-review-close` | Review, verify, and close a task safely |
| `agent-loop-learning-boundary` | Carry reusable findings forward without self-approving durable guidance |
| `agent-loop-php-discipline` | Keep PHP changes minimal, typed, package-correct, and evidence-preserving while keeping human-facing replies concise |
| `agent-loop-workflow` | Explain the full governed command flow and its boundaries |

The focused skills are activation targets for the current workflow step. The
broad workflow skill remains the overview.

Sync them with:

```bash
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
```

## Validation

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
vendor/bin/phpunit --filter 'Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
```
