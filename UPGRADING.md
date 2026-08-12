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

`.agent-loop/runs/<task>/run.json` now requires a `learning_root` field, written
by `workflow approve` from its resolved `--learning-root`. `close`, `learn`,
`report` and the Run projection all read it from there, and a `--learning-root`
that disagrees with the Run is refused rather than silently reading a different
repository.

The value is a **project-relative path, or `null`** — never absolute. A
checkout sits at a different absolute path on every machine, so an absolute
path would make a completed Run unexplainable after a clone. `null` means the
Learning repository lives outside the project and its location is owned by
`paths.learning_root` in `.agent-loop/init.json`, re-resolved on every read.
Consequently an out-of-project Learning root must be configured; passing one
per command is refused, because nothing durable could record it portably.

**Action:** Run artifacts written before this change are rejected by name. Re-run
`agent-loop workflow approve <task-id> --by <actor> --learning-root <path>` to
re-prepare the Run against the same approved Contract revision. The durable
Contract, verification receipt and Learning decision are untouched.

`workflow plan` still accepts `--learning-root` and ignores it — PLAN owns no
Learning state.

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
