# Upgrading agent-loop

## Repository-local state moves to `.agent-loop/`

This is an intentional breaking change across the coordinated `agent-*` stack.
The canonical repository-local state tree is:

```text
.agent-loop/
  init.json
  todo/
  tasks/
  sessions/
  recall/
  learning/
  map/
  runs/
  edit/
```

The project/source root does not move. Source code, tests, Composer files,
templates, and other project files stay where they are.

### Migration mapping

```text
todo/                         -> .agent-loop/todo/
tasks/                        -> .agent-loop/tasks/
session_plan/                 -> .agent-loop/sessions/
infra/doc/agent-learning/     -> .agent-loop/learning/
recall/                       -> .agent-loop/recall/
.agent-map/                   -> .agent-loop/map/
```

Move durable/session state explicitly. `agent-map` output is derived state, so
rebuilding `.agent-loop/map/` is also valid and often preferable to moving the
old `.agent-map/` directory.

After migration run:

```bash
vendor/bin/agent-loop verify
```

Do that before deleting historical directories. There is no automatic copy,
symlink, historical-path discovery, compatibility mode, fallback merge, or
dual-write between old and new state roots.

### Package-specific defaults

The coordinated owning packages use the same repository-state convention:

- `agent-kanban` -> `.agent-loop/todo/`
- `agent-session` -> `.agent-loop/sessions/`
- `agent-learning` -> `.agent-loop/learning/`
- `agent-recall-compiler` -> `.agent-loop/learning/` and
  `.agent-loop/recall/<task-id>/`
- `agent-map` -> `.agent-loop/map/`

Explicit path options in the focused packages remain authoritative when a
repository genuinely needs a custom location.

### Composer/Git archives

Library repositories can exclude all workflow state with one archive rule:

```gitattributes
/.agent-loop export-ignore
```

If `agent-loop` itself is installed through a separate nested Composer project,
exclude that tooling directory independently, for example:

```gitattributes
/tools/agent-loop export-ignore
```

See `docs/quick-start.md` for the complete first-task workflow using the
canonical layout.
