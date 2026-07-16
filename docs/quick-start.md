# Your first governed task

This is the shortest supported path from an installed package to one audited
task. It is intentionally local: no tracker, API token, generated policy
bundle, or running agent integration is required.

## 1. Bootstrap the repository

Run this from the root of an existing Composer project:

```bash
vendor/bin/agent-loop init scaffold
```

The command creates only these workflow inputs when they do not already
exist:

```text
.agent-loop/init.json
todo/board.md
todo/cards/DEMO-1.md
tasks/DEMO-1.md
session_plan/
infra/doc/agent-learning/findings/
```

Existing files are left untouched. Use `--dry-run` to see the same plan
without writing anything.

`DEMO-1` is an example task rather than a magic workflow mode. You can inspect
it or create your own card with the supported board command:

```bash
vendor/bin/agent-loop board card show DEMO-1

vendor/bin/agent-loop board card create PROJECT-1 \
  --title="Add a small validated change" \
  --lane=READY \
  --status=Selected
```

For a new real task, also add a matching `tasks/PROJECT-1.md` with a top-level
heading before relying on the cross-package verifier.

## 2. Plan, approve, and inspect context

Choose a file you are going to change. `composer.json` makes this path work in
any Composer project, but a real source file is usually more useful.

```bash
vendor/bin/agent-loop workflow plan DEMO-1 \
  --by "$(git config user.name)" \
  --file composer.json \
  --goal "Add a small validated change." \
  --validation "composer test"

vendor/bin/agent-loop workflow approve DEMO-1 \
  --by "$(git config user.name)"

vendor/bin/agent-loop workflow context DEMO-1
```

The scaffolded `infra/doc/agent-learning` root is detected automatically. Use
`--learning-root <path>` only if your project keeps learning artifacts
elsewhere.

`workflow context` is read-only. It gives the coding agent a bounded view of
the approved goal and scope, session state, selected recall guidance, and
required validation; it does not regenerate recall or a symbol map.

## 3. Make and validate the change

Make the small change, run the command declared in the plan, and record the
actual result. The first work brief has revision `1`.

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

Use the real exit code and duration when you have them. `0` is only a compact
example for a fast local command.

## 4. Review and close

The close gate requires a blind-spot review and an explicit decision about
whether the session produced durable learning:

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

Read the first report before recording the checkpoint; rerunning it verifies
that review evidence is now present. If it still reports a failure, address it
before closing. Do not use `no_durable_learning` to discard an actual reusable
lesson: capture it through the learning commands and let the repository owner
review what becomes durable guidance.
