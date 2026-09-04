# Upgrading agent-loop

Pre-1.0. Breaking changes are deliberate and there are no compatibility
fallbacks: a wrong path or a stale artifact fails loudly instead of being
guessed at.

To see where your project actually keeps its state, ask:

```bash
vendor/bin/agent-loop init paths            # human
vendor/bin/agent-loop init paths --format=json   # agents and scripts
```

Do not hardcode `.agent-loop/` anywhere. It is the default, not the contract.

---

## Package assets moved to `resources/`

`docs/` is human explanation. `resources/` is what the package ships. Every
package-owned asset moved accordingly, with no forwarding copies:

| Before | After |
| --- | --- |
| `docs/agents/skills/` | `resources/skills/` |
| `docs/agents/subagents/` | `resources/subagents/` |
| `docs/agents/codex-hooks/` | `resources/hooks/codex/` |
| `docs/agents/claude-hooks/` | `resources/hooks/claude/` |
| `docs/agents/tools/` | `resources/tools/` |
| `docs/agents/project-instructions.md` | `resources/instructions/project-instructions.md` |
| `docs/agents/recall-documents.json` | `resources/recall-documents.json` |
| `githooks/` | `resources/githooks/` |
| `make/agent-loop.mk` | `resources/make/agent-loop.mk` |
| `resources/operating-prompts.json` | `resources/prompts/operating-prompts.json` |

**Action:** update anything in your repository that names a path below
`vendor/voku/agent-loop/`. The common one is the Make include:

```make
-include vendor/voku/agent-loop/resources/make/agent-loop.mk
```

Ordinary `init` usage needs no change: the commands resolve their own sources.

The default source roots also moved. If `.agent-loop/init.json` does not set
`paths.skills_root`, `paths.subagents_root`, `paths.codex_hooks_root`,
`paths.claude_hooks_root` or `paths.tools_root`, they now resolve to
`resources/skills`, `resources/subagents`, `resources/hooks/codex`,
`resources/hooks/claude` and `resources/tools` inside *your* repository instead
of `docs/agents/...`.

**Action:** if your repository keeps its own skills, subagents, hooks or tool
templates at the previous default location, either move them to the matching
`resources/...` directory or name the old location explicitly:

```json
{
  "version": 1,
  "paths": {
    "skills_root": "docs/agents/skills"
  }
}
```

Then re-run the installation and read the report:

```bash
vendor/bin/agent-loop init install-assets --agent=<your-agent> --dry-run
vendor/bin/agent-loop init status
```

Human documentation moved to category paths at the same time:
`docs/agents/LIFECYCLE.md` is now `docs/workflow/lifecycle.md`,
`docs/agents/INFO_Agents.md` is `docs/reference/agent-assets.md`,
`docs/agents/PROMPT_PRIMITIVES.md` is `docs/reference/prompt-primitives.md`,
`docs/agents/THIRD_PARTY_NOTICES.md` is `docs/reference/third-party-notices.md`,
the capability matrices are under `docs/architecture/`, policies under
`docs/policies/`, and dogfood reports under `docs/dogfood/`.

---

## Activation is resolved against your repository

`init status` now reports activation, not just sources: the resolved CLI path,
whether skills are projected into a host at all, and whether `core.hooksPath`
and `commit.template` are active. Its `Next:` lines are the exact commands for
your repository. `init install-assets` additionally activates the local Git
integration when the repository tracks `.agent-loop/githooks.json`; pass
`--skip-git-config` to keep the previous behaviour of installing hook files
without touching Git configuration.

**Action:** rerun the asset installation once, then read the activation report:

```bash
vendor/bin/agent-loop init install-assets --agent=<your-agent>
vendor/bin/agent-loop init status
```

Repositories that adopted the package's hook sources under a directory of their
own get the execute bit repaired on the next `init sync-githooks` run.

---

## Project instructions now route agents into installed capabilities

`install-assets` now projects a small package-managed router into root
`AGENTS.md`. Claude and Gemini-compatible hosts use thin import shims instead of
copying the router. Project-owned instructions outside the agent-loop marker
block are preserved when package guidance changes.

**Action:** existing repositories must rerun the asset installation once after
upgrading, before starting the next coding-agent session:

```bash
vendor/bin/agent-loop init install-assets --agent=<your-agent>
```

To update only the always-on instruction entrypoint, use:

```bash
vendor/bin/agent-loop init sync-instructions --agent=<your-agent>
```

Do not hand-edit text between the `agent-loop:project-instructions` markers.
Put repository-specific rules outside them. A session that started before the
router was installed cannot retroactively consume the new project instructions;
start a fresh agent session after the migration.

## Workflow state is configurable

`ProjectLayout` owns every state path. Override any of them in
`.agent-loop/init.json`:

```json
{
  "version": 1,
  "paths": {
    "state_root": "var/agent-state",
    "learning_root": "infra/doc/agent-learning"
  }
}
```

| Key | Default | Moves |
| --- | --- | --- |
| `state_root` | `.agent-loop` | the whole tree at once |
| `sessions_root` | `<state_root>/sessions` | pruneable working memory |
| `learning_root` | `<state_root>/learning` | durable Learning close-out |
| `recall_root` | `<state_root>/recall` | compiled briefings |

Relative values resolve against the project root; absolute values are used
as-is, so a Learning repository can live outside the repo. Everything else
(`runs/`, `contracts/`, `edit/`, `risks/`, `tasks/`, `map/`) follows
`state_root`.

**Action:** if you previously relied on `recall_root` only, nothing changes. If
your project keeps state somewhere non-default, set `state_root` once instead
of passing path flags on every command.

## A governed Run records its Learning root

`.agent-loop/runs/<task>/run.json` requires a `learning_root` field, written by
`workflow approve` from `paths.learning_root` (or the project-layout default).
`close`, `learn`, `report` and the Run projection all read that binding. Workflow
commands no longer accept a second per-command Learning-root authority.

The value is a **project-relative path, or `null`** — never absolute. A
checkout sits at a different absolute path on every machine, so an absolute
path would make a completed Run unexplainable after a clone. `null` means the
Learning repository lives outside the project and its location is owned by
`paths.learning_root` in `.agent-loop/init.json`, re-resolved on every read.
Consequently an out-of-project Learning root must be configured.

**Action:** Run artifacts written before this change are rejected by name. Re-run
`agent-loop workflow approve <task-id> --by <actor>` to re-prepare the Run
against the same approved Contract revision. The durable
Contract, verification receipt and Learning decision are untouched.

Configure `paths.learning_root` before approval when the project does not use
the default. `workflow plan` owns no Learning state or Learning-root option.

## Repository-local state lives under one root

```text
.agent-loop/
  init.json  todo/  tasks/  sessions/  recall/  learning/  map/  runs/  edit/
```

Source code, tests and Composer files do not move.

**Action:** move old state explicitly, then verify before deleting anything.

```text
todo/                      -> .agent-loop/todo/
tasks/                     -> .agent-loop/tasks/
session_plan/              -> .agent-loop/sessions/
infra/doc/agent-learning/  -> .agent-loop/learning/
recall/                    -> .agent-loop/recall/
.agent-map/                -> .agent-loop/map/
```

```bash
vendor/bin/agent-loop verify
```

`.agent-loop/map/` is derived state — rebuilding it is usually better than
moving `.agent-map/`. There is no copy, symlink, dual-write or historical-path
discovery between old and new roots.

Owning packages follow the same convention: `agent-kanban` → `todo/`,
`agent-session` → `sessions/`, `agent-learning` → `learning/`,
`agent-recall-compiler` → `learning/` and `recall/<task-id>/`, `agent-map` →
`map/`.

## Keeping state out of releases

```gitattributes
/.agent-loop export-ignore
```

If `agent-loop` is installed as a separate nested Composer project so it stays
out of your library's own dependencies, exclude that directory too:

```gitattributes
/tools/agent-loop export-ignore
```

See `docs/quick-start.md` for the first-task walkthrough.
