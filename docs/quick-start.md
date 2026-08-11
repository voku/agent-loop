# Your first governed task

This is the shortest supported path from an installed `agent-loop` to one
reviewable task. The default repository layout is intentionally boring: local
agent workflow state lives below one `.agent-loop/` directory instead of
spreading package-specific directories across the project root.

## 1. Bootstrap the repository

Run this from the root of an existing Composer project:

```bash
vendor/bin/agent-loop init scaffold
```

The default scaffold creates the compact layout:

```text
.agent-loop/
  init.json
  todo/
    board.md
    cards/
      DEMO-1.md
  tasks/
    DEMO-1.md
  sessions/
  learning/
    findings/
```

Recall output, map indexes, run manifests, and edit bundles are created below
`.agent-loop/recall`, `.agent-loop/map`, `.agent-loop/runs`, and
`.agent-loop/edit` when those capabilities are used.

Existing files are left untouched. Use `--dry-run` to inspect the scaffold
without writing anything.

`compact` is now the default even when `.agent-loop/init.json` does not contain
a `layout` field. `--layout=compact` is therefore optional. `--layout=legacy`
exists only for repositories that intentionally keep the historical top-level
paths.

For package/library repositories, this also means local workflow state can be
kept out of Composer/Git archives with one rule:

```gitattributes
/.agent-loop export-ignore
```

## 2. Inspect or create a task

`DEMO-1` is a normal example task, not a special workflow mode:

```bash
vendor/bin/agent-loop board card show DEMO-1
```

Create a real board card when you are ready:

```bash
vendor/bin/agent-loop board card create PROJECT-1 \
  --title="Add a small validated change" \
  --lane=READY \
  --status=Selected
```

The board command now resolves `.agent-loop/todo/` by default. If the
cross-package verifier should govern `PROJECT-1`, add the matching task file at
`.agent-loop/tasks/PROJECT-1.md` with a top-level heading.

## 3. Plan, approve, and inspect context

Choose the real file or files you intend to change. `composer.json` is used
below only because every Composer project has one.

```bash
vendor/bin/agent-loop workflow plan DEMO-1 \
  --by "$(git config user.name)" \
  --file composer.json \
  --goal "Add a small validated change." \
  --behavior-anchor "composer configuration -> Composer validation result" \
  --validation "composer test"

vendor/bin/agent-loop workflow approve DEMO-1 \
  --by "$(git config user.name)"

vendor/bin/agent-loop workflow context DEMO-1
vendor/bin/agent-loop workflow status DEMO-1
```

Sessions are written below `.agent-loop/sessions/`, learning state below
`.agent-loop/learning/`, and compiled recall below `.agent-loop/recall/`.
Direct `session`, `learn`, `recall`, `board`, `map`, `review`, and `verify`
commands use the same defaults as the governed workflow.

For a behavioral change, add one or more `--behavior-anchor` values that name
the request, runtime, consumer, data, or integration boundary that owns the
behavior. Skip it deliberately for documentation-only or static-only work.
Material context claims should remain distinguishable as verified, inferred,
assumed, blocked, or contradicted instead of being flattened into confidence.

If `workflow status` says the selected policy requires an L1 execution
contract, create a Markdown file with exactly these non-empty H2 sections:

```markdown
## Goal
What will change.

## Context
The repository/runtime facts that matter.

## Constraints
What must remain true and what is out of scope.

## Verification
The commands or evidence that prove the change.

## Done When
The observable completion criteria.
```

Then bind it to the task:

```bash
vendor/bin/agent-loop workflow contract DEMO-1 \
  --status ready \
  --by "$(git config user.name)" \
  --from .agent-loop/contract-DEMO-1.md

vendor/bin/agent-loop workflow status DEMO-1
```

Do not create a contract merely to satisfy a gate. It should be the concrete
execution boundary produced from the approved plan and selected guidance.

## 4. Use the map without another project-root directory

For PHP projects, `agent-map` still scans the actual repository root. Only its
generated state is compacted:

```bash
vendor/bin/agent-loop map build
vendor/bin/agent-loop map summary
```

The defaults are `.agent-loop/map/php-symbols.json` and
`.agent-loop/map/search.sqlite`; no `.agent-map/` directory is created unless
you explicitly pass an old/custom path.

## 5. Make and validate the change

Make the scoped change, run the validation declared in the plan, then record
the actual result. The first work brief has revision `1`:

```bash
composer test

vendor/bin/agent-loop session validation record DEMO-1 \
  --brief-revision 1 \
  --command "composer test" \
  --status passed \
  --exit-code 0 \
  --duration-ms 0 \
  --by "$(git config user.name)"
```

Use the real exit code and duration when available. The values above are only
a compact example for a fast local command.

## 6. Review, learn, verify, close

```bash
vendor/bin/agent-loop review blindspots DEMO-1

vendor/bin/agent-loop session checkpoint DEMO-1 \
  --title "Review" \
  --body "review blindspots DEMO-1 was checked."

vendor/bin/agent-loop review blindspots DEMO-1

vendor/bin/agent-loop session learning decide DEMO-1 \
  --status no_durable_learning \
  --by "$(git config user.name)"

vendor/bin/agent-loop verify
vendor/bin/agent-loop workflow close DEMO-1 --status done
```

Read the review output before recording the checkpoint. If verification still
reports drift, fix the drift rather than turning the gate into ceremonial
paperwork. Likewise, use `no_durable_learning` only when there genuinely is no
reusable lesson.

## Upgrading an existing repository

This default-path change is intentionally breaking. Existing state is **not**
automatically copied, symlinked, or silently discovered from all historical
locations. Either keep the old layout explicitly:

```json
{
  "version": 1,
  "layout": "legacy"
}
```

or migrate repository-local state to the new canonical tree:

```text
todo/                         -> .agent-loop/todo/
tasks/                        -> .agent-loop/tasks/
session_plan/                 -> .agent-loop/sessions/
infra/doc/agent-learning/     -> .agent-loop/learning/
recall/                       -> .agent-loop/recall/
.agent-map/                   -> .agent-loop/map/
```

After migration, run `vendor/bin/agent-loop verify` before deleting or ignoring
the old directories. Explicit CLI path options continue to override all
defaults.
