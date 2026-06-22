# Basic loop example

A tiny, fake task you can run the whole `agent-loop` command surface
against right now, with no project of your own required. Every command
and every line of output below was run for real against this directory
— nothing here is aspirational.

## Setup

From the repository root, install dependencies once, then `cd` into this
directory so `agent-loop` resolves `tasks/`, `session_plan/`, `recall/`,
etc. relative to the fake project below (not the `agent-loop` repo
itself):

```bash
composer install
cd examples/basic-loop
AGENT_LOOP=../../bin/agent-loop
```

(`../../bin/agent-loop`, not `vendor/bin/agent-loop` — Composer only
mirrors a package's `bin/` entry into `vendor/bin/` for *dependencies*.
For the root checkout itself — which is what you have here — the
binary stays at its own `bin/` path. If you instead `composer require
voku/agent-loop` into a separate project, `vendor/bin/agent-loop` is
the right path there.)

The fake project already contains:

```text
examples/basic-loop/
├── todo/
│   ├── board.md          # board metadata: project prefix "DEMO"
│   └── cards/DEMO-1.md   # one local READY card (Markdown, no Jira involved)
├── tasks/DEMO-1.md        # the task `agent-loop verify` checks against
└── learning-root/
    └── findings/          # empty — a valid, empty learning root
```

`todo/cards/` is the local Markdown card directory — nothing in this
example talks to Jira, and nothing has to. `todo/jira/` also still works
(`voku/agent-kanban` checks `todo/cards/` first, then falls back to
`todo/jira/` for boards that already use it); this example uses the
preferred name. There is no `TODO.md` entrypoint file. `board summary` /
`next-pull` / `ticket` read straight from `todo/cards/*.md`, so they
don't need one — but `agent-loop verify`'s board check does, and will
report `[SKIP]` here. The kanban entrypoint format is
`voku/agent-kanban`'s own house-style contract and out of scope for this
generic example (see `tests/fixtures/basic-loop` for the same call).
`board jira-sync` is the only `board` command that needs a Jira
connection (a host-wired `JiraIssueProvider`), and this example doesn't
run it.

## Walkthrough

### 1. See what's on the board

```bash
$AGENT_LOOP board summary
```

```text
TODO board summary
==================

Lane counts
-----------
READY:   1
DOING:   0
VERIFY:  0
BLOCKED: 0
BACKLOG: 0

WIP health
----------
Active non-done cards:             1
Selected + planning + progress + test: 1
Blocked / waiting:                 0
Backlog candidates:                0

Board snapshot
--------------
Backlog:                           0
Selected for Development:          1
In Planung:                        0
in Progress:                       0
in Test:                           0
Warten:                            0
Fertig:                            0
```

### 2. Start a session for the task

```bash
$AGENT_LOOP session start --task DEMO-1 --by demo-agent --base-commit "$(git rev-parse HEAD 2>/dev/null || echo 0000000)"
```

```text
Started session: 2026-06-22-demo-1
- path: .../session_plan/2026-06-22-demo-1
- working-memory files: plan.md, assumptions.md, decisions.md, validation.md, checkpoints/
```

`session start` prints its own generated **session id** on the first
line (`2026-06-22-demo-1` here — yours will carry today's date). You
don't need to capture it for the steps below: `session
record`/`checkpoint`/`close`/`claim`/`show` also accept the task id
(`DEMO-1`) you started the session with, and `agent-loop` resolves it to
the matching session id before delegating.

### 3. Compile a task-scoped briefing

```bash
$AGENT_LOOP recall compile --root learning-root --task DEMO-1 --file src/Signup.php
```

```text
Briefing compiled successfully under: .../examples/basic-loop/recall/DEMO-1/
- compilation ID: compilation.DEMO-1.2026-06-22-143325.8ea1299a
- system.md (selected guidance: 0, selected constraints: 0)
- validation-plan.md
- recall-log.draft.json

[NOTE] Recall artifacts were written for review or harness ingestion.
[ACTION REQUIRED] Pass system.md / validation-plan.md into your agent workflow manually unless your harness consumes them automatically.
```

No `--output-dir` needed: with `--task DEMO-1` and no `--output-dir`,
`agent-loop` defaults it to `recall/DEMO-1/` under the project root,
which is exactly where `agent-loop verify`'s recall-coverage check looks
for `recall/DEMO-1/meta.json`. Pass `--output-dir` explicitly only if you
want the briefing written somewhere else.

The `[NOTE]`/`[ACTION REQUIRED]` lines are `agent-loop`'s own reminder, not
`voku/agent-recall-compiler`'s: compiling a briefing only writes the files
above to disk, it does not feed them into any running agent. Reading
`system.md` and `validation-plan.md` into the actual coding session is up to
whatever drives that session — a human, an editor integration, or a
`voku/housekeeping`-style harness.

### 4. Record a decision on the session

```bash
$AGENT_LOOP session record DEMO-1 --kind decision --title "Keep validation scoped" --body "Only touch Signup.php for this pass."
```

```text
Recorded decision on session '2026-06-22-demo-1'.
```

### 5. Verify before closing

```bash
$AGENT_LOOP verify
```

```text
agent-loop verify - cross-package consistency check

[OK] package delegates: board, learn, recall, session commands all resolve to an installed package
[OK] tasks: 1 task file(s) parsed: DEMO-1
[SKIP] board: no TODO.md at .../examples/basic-loop/TODO.md
[OK] sessions: 1 session(s) parsed, 1 active and consistent
[OK] learning root: validated .../examples/basic-loop/learning-root (0 finding(s), 0 proposal(s), outcome/decision history parsed)

[OK] agent-loop verify: no drift detected.
```

Run `verify` while the session is still open — a closed session is no
longer checked for recall coverage, so this is the point where a missing
or stale briefing would actually be caught.

### 6. Close the session

```bash
$AGENT_LOOP session close DEMO-1 --status done
```

```text
Closed session '2026-06-22-demo-1' as done.
```

### 7. Validate the learning root

```bash
$AGENT_LOOP learn validate --root learning-root
```

```text
Validated agent learning root: .../examples/basic-loop/learning-root
Findings: 0
Proposals: 0
Recall selections: 0
Guidance outcomes: 0
```

## Cleanup

Steps 2–6 wrote `session_plan/` and `recall/` into this directory
(both git-ignored here). Remove them to reset the example:

```bash
rm -rf session_plan recall
```
