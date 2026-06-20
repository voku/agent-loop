# basic-loop fixture

Minimal, boring proof that the wrapper can orchestrate the underlying
packages without tripping over its own shoelaces. `SmokeLoopTest` copies this
directory to a temp location, then runs the real loop against the copy:

```text
task exists            tasks/task.001.md (committed)
  -> session starts    agent-loop session start --task task.001 ...
  -> recall compiles   agent-loop recall compile --task task.001 ...
  -> learn validates   agent-loop learn validate --root learning-root
  -> verify reports    agent-loop verify
```

`session_plan/`, `recall/`, and `learning-root/findings/` start empty
(`.gitkeep` only) — the test writes the session and recall artifacts into its
temp copy so committed fixtures don't carry date-stamped session IDs.

No `TODO.md` is included: the kanban board format is a separate,
house-style-specific contract owned by `voku/agent-kanban` and is out of
scope for this generic smoke fixture. `agent-loop verify` reports `[SKIP]`
for the board check here, which is itself part of what the test asserts.
