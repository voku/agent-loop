# Repository workflow-state layout

`agent-loop` keeps repository-local workflow state below one `.agent-loop/`
directory. This is the default layout.

The point is deliberately mundane: using several focused packages should not
spray several unrelated-looking state directories across the repository root.
The packages may have separate responsibilities; their local state still has
one obvious home.

## Default

For a new repository:

```bash
vendor/bin/agent-loop init scaffold
```

This creates the compact state tree:

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

`layout: compact` may still be written explicitly, but it is no longer needed:

```json
{
  "version": 1,
  "layout": "compact"
}
```

The directory is a **workflow-state root**, not the project root. PHP source,
Composer files, tests, templates, and every other project file remain where
they already are. In particular, `agent-map` still indexes the real repository
root; only its generated index/cache lives below `.agent-loop/map/`.

Direct umbrella commands resolve the same paths as governed workflow commands,
including `board`, `session`, `recall`, `learn`, `map`, `verify`, `review`, and
`edit`.

## Breaking change from the historical defaults

Older releases used several default paths such as `todo/`, `tasks/`,
`session_plan/`, `infra/doc/agent-learning/`, `recall/`, and `.agent-map/`.
Those locations are no longer the default and are not silently merged into the
new state tree.

The canonical mapping is:

```text
todo/                         -> .agent-loop/todo/
tasks/                        -> .agent-loop/tasks/
session_plan/                 -> .agent-loop/sessions/
infra/doc/agent-learning/     -> .agent-loop/learning/
recall/                       -> .agent-loop/recall/
.agent-map/                   -> .agent-loop/map/
```

After moving existing state, run `agent-loop verify` before removing the old
locations. Explicit CLI path options still override the defaults.

## Explicit legacy mode

A repository that deliberately needs the historical paths can pin them:

```json
{
  "version": 1,
  "layout": "legacy"
}
```

`legacy` is a compatibility mode. It is no longer what an absent `layout`
field means.

`agent-loop` does not automatically copy, symlink, or dual-write historical
state. Doing so would hide ambiguous ownership and make a breaking migration
look successful while two sources of truth quietly diverge.

## Host projections stay explicit

Client-specific projections such as `.codex/`, `.claude/`, `.agents/`, or
`.github/agents/` are not workflow state. They are created only when their
corresponding `init install-assets` / sync command is explicitly run.

The default `.agent-loop/` layout does not create host projections merely
because the repository uses `agent-loop`.

## Library/package repositories

A library can exclude the complete workflow state from Git archive / Composer
dist packages with one rule:

```gitattributes
/.agent-loop export-ignore
```

If the repository keeps a separate Composer project solely to install
`agent-loop` as development tooling, exclude that directory independently, for
example:

```gitattributes
/tools/agent-loop export-ignore
```

That leaves application/library source distribution independent from local
agent workflow machinery without maintaining an inventory of every focused
package's internal path.
