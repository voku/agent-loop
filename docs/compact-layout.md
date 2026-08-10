# Compact repository layout

`agent-loop` can keep its repository-local workflow state below one directory instead of spreading focused-package state across several top-level paths.

This is opt-in so existing repositories keep their current layout and historical state exactly where it is.

## Enable it

For a new repository:

```bash
vendor/bin/agent-loop init scaffold --layout=compact
```

Or configure an existing empty workflow explicitly in `.agent-loop/init.json`:

```json
{
  "version": 1,
  "layout": "compact"
}
```

Do not flip an existing repository with historical workflow state and expect an automatic migration. `agent-loop` deliberately does not duplicate, symlink, or silently move old state.

## Resulting state tree

```text
.agent-loop/
  init.json
  todo/          # when the Kanban board is used
  tasks/         # when task files are used
  sessions/
  recall/
  learning/
  map/
  runs/
  edit/
```

The directory is a **workflow-state root**, not the project root. PHP source, Composer files, tests, templates, and every other project file remain where they already are. In particular, `agent-map` still indexes the real repository root; only its generated index/cache lives below `.agent-loop/map/`.

Direct umbrella commands resolve the same compact paths as governed workflow commands, including `board`, `session`, `recall`, `learn`, `map`, `verify`, `review`, and `edit`.

## Host projections stay explicit

Client-specific projections such as `.codex/`, `.claude/`, `.agents/`, or `.github/agents/` are not workflow state. They are created only when their corresponding `init install-assets` / sync command is explicitly run.

Compact layout does not create them merely because the repository uses `agent-loop`.

## Library/package repositories

A library can exclude the complete workflow state from Git archive / Composer dist packages with one rule:

```gitattributes
/.agent-loop export-ignore
```

If the repository keeps a separate Composer project solely to install `agent-loop` as development tooling, exclude that directory independently as well, for example:

```gitattributes
/tools/agent-loop export-ignore
```

That leaves application/library source distribution independent from local agent workflow machinery without maintaining an inventory of every internal focused package path.

## Legacy layout

When `layout` is absent or set to `legacy`, existing paths remain unchanged, including `session_plan/`, `.agent-map/`, and the detected learning/recall roots. This is intentional compatibility behavior, not a migration mode.
