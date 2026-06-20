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
│   └── jira/DEMO-1.md     # one READY card
├── tasks/DEMO-1.md        # the task `agent-loop verify` checks against
└── learning-root/
    └── findings/          # empty — a valid, empty learning root
```

There is no `TODO.md` entrypoint file. `board summary` / `next-pull` /
`ticket` read straight from `todo/jira/*.md`, so they don't need one —
but `agent-loop verify`'s board check does, and will report `[SKIP]`
here. The kanban entrypoint format is `voku/agent-kanban`'s own
house-style contract and out of scope for this generic example (see
`tests/fixtures/basic-loop` for the same call).

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
Started session: 2026-06-20-demo-1
- path: .../session_plan/2026-06-20-demo-1
- working-memory files: plan.md, assumptions.md, decisions.md, validation.md, checkpoints/
```

`session start` prints the **session id** on the first line
(`2026-06-20-demo-1` here — yours will carry today's date). Every later
`session` command takes that id, not the task id. Capture it:

```bash
SESSION_ID=2026-06-20-demo-1   # the id printed above, not "DEMO-1"
```

### 3. Compile a task-scoped briefing

```bash
$AGENT_LOOP recall compile --root learning-root --task DEMO-1 --file src/Signup.php --output-dir recall/DEMO-1
```

```text
Briefing compiled successfully under: recall/DEMO-1/
- compilation ID: compilation.DEMO-1.2026-06-20-211958.5b51f59f
- system.md (selected guidance: 0, selected constraints: 0)
- validation-plan.md
- recall-log.draft.json
```

`--output-dir` matters: `recall compile` defaults to writing into the
current directory. Passing `--output-dir recall/<task-id>` is what lets
`agent-loop verify` find the briefing it expects at
`recall/DEMO-1/meta.json`.

### 4. Record a decision on the session

```bash
$AGENT_LOOP session record "$SESSION_ID" --kind decision --title "Keep validation scoped" --body "Only touch Signup.php for this pass."
```

```text
Recorded decision on session '2026-06-20-demo-1'.
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
$AGENT_LOOP session close "$SESSION_ID" --status done
```

```text
Closed session '2026-06-20-demo-1' as done.
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
