# Upgrading agent-loop

## A governed Run records the Learning root it is governed against

`.agent-loop/runs/<task>/run.json` now carries a required `learning_root`
field. It is written by `workflow approve`, from that command's resolved
`--learning-root`, and it is the only place `workflow close`, `workflow learn`,
`workflow report` and the Run projection look for that Run's durable Learning
close-out.

Before this change the close gate read the caller-supplied `--learning-root`
while the durable Run projection re-derived the location from the layout
default. A project whose Learning repository is not at `.agent-loop/learning`
could therefore close successfully and still produce a manifest saying
`learning: missing` with `state: incomplete` — the durable evidence contradicted
the gate that produced it, and the contradiction survived Session pruning.

`--learning-root` is still accepted on `close`, `learn` and `report`, but a
value that disagrees with the Run's binding is now refused instead of silently
reading a different repository. `workflow plan` still accepts the option and
ignores it: PLAN owns no Learning state.

### Migration

Run artifacts written before this change have no `learning_root` and are
rejected with a message naming the file and the missing field. There is no
compatibility fallback on purpose: guessing the location is exactly the
behavior being removed. Re-run `agent-loop workflow approve <task-id> --by
<actor> --learning-root <path>` to re-prepare the Run for the same approved
Contract revision; the durable Contract, verification receipt and Learning
decision are untouched.

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
