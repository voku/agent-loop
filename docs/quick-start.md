# Your first governed task

This is the shortest supported path from an installed `agent-loop` to one
governed coding task. The host follows the lifecycle kernel instead of carrying
its own map/Session/Recall/review choreography.

## 1. Bootstrap the repository and host

```bash
vendor/bin/agent-loop init scaffold --demo
vendor/bin/agent-loop init install-assets --agent=codex
vendor/bin/agent-loop init doctor
```

Replace `codex` with the host you actually use. For a real repository, prefer its
real project prefix instead of tutorial state:

```bash
vendor/bin/agent-loop init scaffold --prefix=PROJECT
```

Project agent assets before starting the agent session that should consume them.
Installation proves projection, not that an already-running host retroactively
loaded the files.

## 2. Start through `enter`

The ordinary lifecycle front door is:

```bash
vendor/bin/agent-loop enter DEMO-1 --format=json
```

For a new task, the result will normally be incomplete and provide a canonical
next step. Read:

```text
mutation_ready
next_action_kind
next_action
manifest.references
```

Treat `next_action_kind` as follows:

- `command` — execute `next_action` as written;
- `command_template` — fill model-owned placeholders from the actual request and
  current repository evidence, then execute it without asking a human merely
  because placeholders exist;
- `decision_required` — a genuine human-authority decision is required; show the
  exact current Contract/review/Learning/risk subject before asking for it;
- `host_work` — perform the described implementation/model work; the text is not
  a command;
- `none` — the lifecycle has no further action.

Do not infer a different sequence from this tutorial. If the kernel says a map
repair, approval, execution contract, review acknowledgement, Learning decision,
or another action is next, follow that exact result.

## 3. Define durable task intent when requested

For an unplanned task, `enter` returns a `command_template` PLAN similar to:

```text
agent-loop workflow plan DEMO-1 --by <actor> --file <path> --goal <goal> --validation <validation>
```

Replace every placeholder with a real value from the user's request and current
repository evidence before executing it. This is model-owned task construction,
not a human approval gate. For example:

```bash
vendor/bin/agent-loop workflow plan DEMO-1 \
  --by "$(git config user.name)" \
  --file composer.json \
  --goal "Add a small validated change." \
  --behavior-anchor "composer configuration -> Composer validation result" \
  --validation "composer test"
```

A validation value must be an executable repository-supported command, not prose
or an unresolved placeholder. Add repeatable `--acceptance` values when concrete
outcomes must survive the task.

For non-trivial work, do one bounded read-only scope pass before persisting the
first Contract so an accidental first-file guess does not become an approval
churn machine. Prefer the smallest stable honest boundary: exact files for an
isolated change, or focused implementation/test paths when the request is
structurally multi-file. Do not default to repository-wide scope merely to avoid
future prompts.

After PLAN, call `enter --format=json` again. Do **not** manually insert a map
build or approval because an older quick-start used to list one. Existing PHP
scope may require discovery; when it does, the lifecycle kernel returns the
owner-produced map repair as `next_action` before it asks for approval.

## 4. Respect authority-bearing decisions

When `next_action_kind=decision_required`, the decision is human-owned. Do not
fabricate an approver, review acknowledgement, Learning disposition, accepted-risk
owner, or other explicitly human authority.

Do not ask for an opaque “confirm?” either. Before Contract approval, show the
exact candidate revision including goal, scope, non-goals, acceptance criteria
and validation. Before review acknowledgement, render the deterministic developer
workbench and show the exact report identity being acknowledged:

```bash
vendor/bin/agent-loop workflow review DEMO-1
```

The generated HTML is presentation only; the exact owner-bound identity remains
the authority.

Approval seals one exact Contract revision. It does not create the governed Run,
Session, or Recall output. The next `enter` performs deterministic preparation
through the package owners and returns bounded current context.

## 5. Implement when authorized

When the result says implementation work is authorized (`mutation_ready=true` or
`next_action_kind=host_work`), make the smallest correct change in the approved
scope using normal repository tools.

`agent-map` can be used for precise source navigation when useful, but it is not
a mandatory tutorial phase. A discovery repair required by policy comes from the
canonical next step; optional navigation remains host/tool choice.

## 6. Finish through the kernel

After host-native mutation, run:

```bash
vendor/bin/agent-loop finish DEMO-1 --format=json
```

Then obey the returned `next_action_kind` and `next_action` until the lifecycle
reports `none` / complete.

`finish` owns deterministic validation/review/Learning/close-out ordering. The
quick-start intentionally does not list those gates, because a duplicated prose
checklist can drift from executable policy.

A failing validation may return:

```text
next_action_kind = host_work
next_action = change the implementation so the declared validation passes: ...
```

That is deliberate: re-running the command that just observed the same failing
implementation would not make progress. Fix the implementation, then call
`finish` again.

A model-fillable close-out template is `command_template`; fill it from current
evidence without inventing a human gate. Review acknowledgement, Learning
ownership or accepted risk may instead be `decision_required`; show the exact
subject first and keep human authority truthful.

## 7. Confirm completion

A complete structured result has no further lifecycle action. Useful diagnostic
surfaces remain available:

```bash
vendor/bin/agent-loop workflow status DEMO-1 --format=json
vendor/bin/agent-loop workflow report DEMO-1 --format=json
vendor/bin/agent-loop verify --task-id=DEMO-1
```

These are diagnostics/read-only evidence, not another required happy-path
sequence.

## Optional L2 operating prompts

Select a reusable operating prompt only when it actually matches the task, and
select it in the Contract before approval. The package that owns the recipe owns
its schema and construction semantics. Example:

```bash
vendor/bin/agent-loop workflow plan PROJECT-1 \
  --by "$(git config user.name)" \
  --file src/SomeOwningClass.php \
  --goal "Find and fix the smallest evidenced workflow integration gap." \
  --validation "composer ci" \
  --operating-prompt-manifest vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json \
  --operating-prompt '{"id":"missingness-audit","arguments":{}}'
```

After that, return to `enter --format=json`. If the selected policy requires a
project-specific execution contract, the lifecycle result surfaces that next
step; do not copy Recall's construction rules into this tutorial. Construction
from approved evidence is a `command_template` unless the lifecycle explicitly
names a human authority boundary.

## Lower-level tools

`board`, `map`, `session`, `recall`, `learn`, `review`, `edit`, and `verify` remain
available for navigation, diagnostics, specialist work, CI, and recovery. They
are not mandatory phases simply because the CLI exposes them.

The ordinary host contract is intentionally small:

```text
enter
  -> obey canonical next step
  -> host-native mutation when authorized
  -> finish
  -> obey canonical next step
  -> complete
```

If following a canonical command deterministically returns the same refusal and
the same next action, record that as a workflow defect rather than teaching the
host a private workaround.