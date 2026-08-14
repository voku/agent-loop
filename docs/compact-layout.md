# Repository workflow-state layout

`agent-loop` keeps repository-local workflow state below one `.agent-loop/`
directory.

The point is deliberately mundane: using several focused packages should not
spray several unrelated-looking state directories across the repository root.
The packages may have separate responsibilities; their local state still has
one obvious home.

## Canonical layout

For a new repository:

```bash
vendor/bin/agent-loop init scaffold
```

This creates the workflow-state tree:

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
Those locations are no longer auto-discovered or silently merged into the new
state tree.

The canonical mapping is:

```text
todo/                         -> .agent-loop/todo/
tasks/                        -> .agent-loop/tasks/
session_plan/                 -> .agent-loop/sessions/
infra/doc/agent-learning/     -> .agent-loop/learning/
recall/                       -> .agent-loop/recall/
.agent-map/                   -> .agent-loop/map/
```

### One path the mapping does not cover

`agent-map` writes its PHPStan result cache to a hardcoded
`.agent-map/phpstan-cache/`, with no option to redirect it, so `map build`
recreates that directory even in a fully migrated repository. It is a cache,
not workflow state, and it is regenerated on demand — but it is several
megabytes and it lands outside `.agent-loop/`, so a library repository has to
exclude it explicitly:

```gitignore
/.agent-map/
```

```gitattributes
/.agent-map export-ignore
```

Until `agent-map` accepts a cache location, this is the one place where the
mapping above is aspirational rather than complete. Do not read the leftover
`/.agent-map/` line in this repository's own `.gitignore` as migration residue:
it is load-bearing for exactly this reason.

After moving existing state, run `agent-loop verify` before removing the old
locations. Focused package CLI options can still select a genuinely custom path
explicitly.

`agent-loop` does not automatically copy, symlink, dual-write, or maintain a
legacy layout mode. Doing so would hide ambiguous ownership and leave two
sources of truth quietly diverging.

## Host projections stay explicit

Client-specific projections such as `.codex/`, `.claude/`, `.agents/`, or
`.github/agents/` are not workflow state. They are created only when their
corresponding `init install-assets` / sync command is explicitly run.

Using `.agent-loop/` does not create host projections merely because the
repository uses `agent-loop`.

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
