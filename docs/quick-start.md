# Your first governed task

This is the shortest supported path from an installed `agent-loop` to one
reviewable task. Repository-local agent workflow state lives below one
`.agent-loop/` directory instead of spreading package-specific directories
across the project root.

## 1. Bootstrap the repository and agent host

Run this from the root of an existing Composer project:

```bash
vendor/bin/agent-loop init status
vendor/bin/agent-loop init scaffold
vendor/bin/agent-loop init install-assets --agent=codex
vendor/bin/agent-loop init status
```

`init status` is the entry point, before and after. Its `Activation:` section
reports the resolved CLI path, whether skills are projected into a host at all,
and whether `core.hooksPath`/`commit.template` are active; its `Next:` lines are
the exact commands for *this* repository. Use `init doctor` for the wider
environment. A projected skill is readable by the host - that is not the same as
a session having consumed it.

Replace `codex` with the host you are actually going to use. Use `--agent=all`
only when the repository intentionally manages every supported host. Project the
assets **before starting the agent session that should use them**. Installing a
skill during an already-running session proves installation, not that the
current agent retroactively consumed it.

The scaffold creates the canonical layout:

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
without writing anything. There is no layout switch: `.agent-loop/` is the
workflow-state root.

For package/library repositories, local workflow state can be kept out of
Composer/Git archives with one rule:

```gitattributes
/.agent-loop export-ignore
```

When the repository tracks `.agent-loop/githooks.json`, `install-assets` already
installed the package-owned local Git hooks and pointed `core.hooksPath` and
`commit.template` at them. Use `--skip-git-config` to install the hook files
without touching Git configuration, or run the activation on its own:

```bash
vendor/bin/agent-loop init sync-githooks --commit-template=.gitmessage
```

Local Git hooks run only for a local Git commit. GitHub API/connector writes do
not execute `pre-commit` or `commit-msg`; use the repository validation gates as
the authority in such a host instead of pretending the hook ran.

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

The board command resolves `.agent-loop/todo/`. If the cross-package verifier
should govern `PROJECT-1`, add the matching task file at
`.agent-loop/tasks/PROJECT-1.md` with a top-level heading.

## 3. Build navigation evidence before approval

For PHP projects, build the semantic map before `workflow approve`. Approval is
where Loop compiles Recall, so a map built afterwards cannot influence that
briefing without another explicit approval/compile cycle.

```bash
vendor/bin/agent-loop map build --paths=src,tests
vendor/bin/agent-loop map search-index build
vendor/bin/agent-loop map summary
```

The defaults are `.agent-loop/map/php-symbols.json` and
`.agent-loop/map/search.sqlite`. Explicit agent-map path options remain
available for genuinely custom locations.

Use the installed `agent-loop-investigate` skill or bounded map queries to locate
real source before broad reads:

```bash
vendor/bin/agent-loop map query Foo
vendor/bin/agent-loop map related Foo
```

## 4. Plan, optionally select an L2 recipe, approve, and inspect context

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

Select an L2 operating prompt only when it matches the task. Selection belongs in
the Contract **before approval**. For example, a real self-discovery task looking
for missing workflow integration can select Recall's `missingness-audit` recipe:

```bash
vendor/bin/agent-loop workflow plan PROJECT-1 \
  --by "$(git config user.name)" \
  --file src/SomeOwningClass.php \
  --goal "Find and fix the smallest evidenced workflow integration gap." \
  --validation "composer ci" \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"missingness-audit","arguments":{}}'
```

Approval then compiles the selected L2 recipe together with current repository
and map evidence. A deterministic harness that merely checks the generated
`system.md` proves compilation. A behavioral dogfood claim additionally needs an
agent host that actually receives and acts on that briefing.

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
contract, construct that L1 from the generated Recall instructions and the
project evidence the agent actually received. Persist it with exactly these
non-empty H2 sections:

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

## 5. Make and validate the change

Make the scoped change, run the validation declared in the plan, then record
the actual result. The first Contract has revision `1`:

```bash
composer test

vendor/bin/agent-loop session validation record DEMO-1 \
  --contract-revision 1 \
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

vendor/bin/agent-loop workflow learn DEMO-1 \
  --status no_durable_learning \
  --by "$(git config user.name)" \
  --reason "No reusable finding from this bounded task."

vendor/bin/agent-loop verify --task-id=DEMO-1
vendor/bin/agent-loop workflow close DEMO-1 --status done
vendor/bin/agent-loop workflow status DEMO-1 --expect complete
```

Read the review output before recording the checkpoint. If verification still
reports drift, fix the drift rather than turning the gate into ceremonial
paperwork. Likewise, use `no_durable_learning` only when there genuinely is no
reusable lesson.

`workflow status --expect complete` proves the governed task is complete. It is
**not** evidence that a candidate commit was merged, shipped, or released. Those
claims require exact candidate/integration evidence, for example:

```bash
vendor/bin/agent-loop verify \
  --candidate-sha=<full-source-candidate-sha> \
  --integrated-sha=<full-integrated-sha> \
  --target-ref=main \
  --format=json
```

Add `--release-tag=<exact-tag>` when the claim is specifically that the result
was released.

## Upgrading an existing repository

This path change is intentionally breaking. Existing state is **not**
automatically copied, symlinked, or discovered from historical locations.
Migrate repository-local state once:

```text
todo/                         -> .agent-loop/todo/
tasks/                        -> .agent-loop/tasks/
session_plan/                 -> .agent-loop/sessions/
infra/doc/agent-learning/     -> .agent-loop/learning/
recall/                       -> .agent-loop/recall/
.agent-map/                   -> .agent-loop/map/
```

After migration, run `vendor/bin/agent-loop verify` before deleting or ignoring
the old directories. Focused package CLI path options continue to allow
explicit custom locations where needed.
