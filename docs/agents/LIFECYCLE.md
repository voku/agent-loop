# The governed lifecycle across the agent-* packages

This document describes what the packages **currently do**, not what they should
eventually do. It was written by running the loop end to end in a real
repository and recording each transition, its artifacts and its failure modes.
Where a transition is optional or where the current behaviour is surprising, it
says so rather than smoothing it over.

Only `agent-loop` knows the whole lifecycle. Every other package owns one
concern and can be used on its own.

| Package                 | Owns                                                       |
| ----------------------- | ---------------------------------------------------------- |
| `agent-kanban`          | durable work-item state                                     |
| `agent-session`         | task-local mutable working state, work briefs, approvals    |
| `agent-map`             | repository facts, source-backed context, hybrid search      |
| `agent-recall-compiler` | governed briefing and verification contracts                |
| `agent-loop`            | transitions and orchestration                               |
| `agent-learning`        | reviewed durable findings and proposals                     |

## Transitions

| Transition | Owner                                | Input                                    | Output                                        |
| ---------- | ------------------------------------ | ---------------------------------------- | --------------------------------------------- |
| DISCOVER   | `agent-map`                          | question or symbol name                  | file/range candidates, no state                |
| PLAN       | `agent-loop` + `agent-session`       | task id, goal, scope, validation         | session + candidate work brief revision        |
| APPROVE    | `agent-loop` + `agent-recall-compiler` | exact brief revision                   | approval record + compiled briefing            |
| PREPARE    | `agent-map` + `agent-recall-compiler` | approved brief + repository snapshot     | recall bundle, verification plan and key       |
| EXECUTE    | `agent-loop`                         | prepared bundle                          | edit bundle under `.agent-loop/edit/<task-id>` |
| VERIFY     | `agent-loop`                         | edit bundle + verification key           | `verification-result.json`                     |
| REVIEW     | `agent-loop`                         | task artifacts                           | blind-spot report                              |
| LEARN      | `agent-session` + `agent-learning`   | recall draft, session evidence           | outcome history, findings, proposals           |
| CLOSE      | `agent-loop`                         | all of the above                         | closed session, gates enforced                 |

### DISCOVER — `agent-map`

```bash
vendor/bin/agent-map query "D3"                       # exact-ish symbol lookup
vendor/bin/agent-map search "Wie werden Anträge storniert?" --semantic
```

- **Preconditions:** a built index (`.agent-map/php-symbols.json`); the derived
  search index (`.agent-map/search.sqlite`) only for `search`.
- **Produces:** nothing durable. Discovery is read-only by design.
- **Failure:** a stale index reports staleness rather than answering from it.
- **Recovery:** `agent-map refresh`, then `agent-map search-index refresh`.
- **Routing:** "does X exist for Y" is answered by `query` on the bare
  identifier; "how/where does X happen" by `search`. Measured, not assumed: a
  natural-language search for an accounting question ranked accounting readers
  first but never revealed that the other system existed, which `query` found in
  one call.

### PLAN — `agent-loop` + `agent-session`

```bash
vendor/bin/agent-loop workflow plan <task-id> --by <actor> --file <path> \
  --goal "..." --validation "..." [--tag <label>] [--behavior-anchor <text>] [--ephemeral]
```

- **Produces:** `session_plan/<session-id>/` (working memory), work brief
  revision 1 in state `candidate`, and a refreshed run projection under
  `.agent-loop/runs/<task-id>/manifest.json`.
- **State:** task-local and mutable. Sessions are working memory, not evidence.
- **Failure:** a missing `--file`, `--goal` or `--validation` is refused; a
  second active session for the same task is refused.
- **Recovery:** `agent-loop session close <session-id> --status dropped`.
- **`--ephemeral`:** declares the session an experiment. Repository-wide gates
  skip it. Use it whenever the session exists to try a command out - without it,
  an unfinished throwaway fails `agent-loop verify` for *every* other session in
  the repository until it is dropped.

### APPROVE — `agent-loop`

```bash
vendor/bin/agent-loop workflow approve <task-id> --by <human-actor>
```

- **Preconditions:** a candidate brief revision exists, or the exact current
  revision is already approved and recall compilation needs to be resumed.
- **Produces:** an approval bound to that exact revision, then a compiled
  briefing under the recall output root: `system.md`, `validation-plan.md`,
  `recall.bundle.json`, `facts.json`, `selection-report.json`,
  `recall-log.draft.json`, and - when a map target resolves -
  `verification-plan.json` plus the verifier-owned `verification-key.json`.
- **Projection:** the approved state is persisted before compilation and the
  compiled state after success.
- **Superseding:** approving a new revision archives any previous canonical
  recall directory instead of letting old metadata or reviews masquerade as
  evidence for the new brief.
- **Automatic context:** when `.agent-map/php-symbols.json` exists it is passed
  as `--map-index`, and `.agent-map/search.sqlite` as `--map-search-index`, so
  the briefing carries map facts and ranked candidates without the host
  orchestrating anything.
- **Failure:** approving a revision that has since been revised is refused. If
  compilation fails after approval, the approval remains valid and the same
  command resumes compilation without approving identical scope twice.
- **Recovery:** fix the compiler input and rerun `workflow approve`.

### PREPARE — `agent-map` + `agent-recall-compiler`

Usually part of APPROVE. Standalone:

```bash
vendor/bin/agent-recall-compiler compile --root <learning-root> --task <task-id> \
  --map-index .agent-map/php-symbols.json --map-root "$PWD" \
  --map-search-index .agent-map/search.sqlite --document-manifest <manifest>
```

- **Binding:** every artifact records the map snapshot it was compiled against.
  A search index built from a different map is refused, not silently ranked
  against.
- **Failure:** unsupported schema versions, conflicting active rules, or a
  scope-relevant rejected proposal block the compile instead of writing a
  misleading briefing.

### EXECUTE — `agent-loop`

```bash
vendor/bin/agent-loop edit <Class::method> ... -- "instruction"
```

- **Produces:** `.agent-loop/edit/<task-id>/` with the bounded briefing and the
  execution result.
- **Optional:** many tasks never run `edit`. A task without an edit bundle is
  never asked for one.

### VERIFY — `agent-loop`

```bash
vendor/bin/agent-loop edit verify --bundle=.agent-loop/edit/<task-id>   # per bundle
vendor/bin/agent-loop verify --task-id <task-id>                        # cross-package
```

- **Produces:** `verification-result.json` with a status.
- **Cross-package `verify`** checks session/brief/recall coherence for the whole
  repository, or for one task with `--task-id`. Ephemeral sessions are skipped.
- **Failure:** an edit bundle that exists but was never verified blocks CLOSE.
  A bundle that never existed does not.

### REVIEW — `agent-loop`

```bash
vendor/bin/agent-loop review blindspots <task-id>
```

- **Produces:** a Markdown report, a JSON report and an L2 review prompt under
  `<recall-root>/<task-id>/reviews/`.
- **Note:** the review is required before CLOSE, and the first run legitimately
  warns that no review checkpoint exists yet. Record a checkpoint and re-run.

### LEARN — `agent-session` + `agent-learning`

```bash
vendor/bin/agent-loop session learning decide <session-id> --by <actor> \
  --status findings_recorded|no_durable_learning|follow_up_required
vendor/bin/agent-recall-compiler log-outcome --root <learning-root> \
  --draft <recall-log.draft.json> --by <actor> --commit <sha>
```

- **Produces:** append-only `history/recall-selections.jsonl` and
  `history/outcomes.jsonl`; optionally findings and proposals under the learning
  root.
- **Contract:** every selected rule needs an explicit signal. Selection is not
  evidence of use, so `not_used` and `irrelevant` are first-class answers and
  padding them into `helpful` corrupts the promotion evidence.

### CLOSE — `agent-loop`

```bash
vendor/bin/agent-loop workflow close <task-id> --status done
```

Gates, all enforced:

1. cross-package `verify` passes for this task;
2. the current brief revision is approved;
3. every existing edit bundle has a passing `verification-result.json`;
4. a blind-spot review report exists;
5. a learning decision is recorded;
6. every selected guidance rule has an explicit recall outcome.

- **Produces:** a closed session and a final refreshed run projection.
- **Recovery:** the failure names the missing artifact and the command that
  produces it. `agent-loop workflow status <task-id>` projects board, session,
  brief, approval, map/search, recall, edit, verification, review and learning
  state and prints one next command. `--format=json` exposes the same projection
  for coding agents. A contradictory or blocking state exits `2` instead of
  returning an ornamental green status.
- **Accepted risk:** `--accept-risk "<reason>" --accept-risk-by "<name>"`
  records who overrode which gates, in Markdown and JSON.

## Run identity and projection

A governed run is identified by the relationship between:

```text
task/card id
+ session id
+ work-brief revision and approval
+ map/search snapshot
+ recall compilation
+ edit and verification artifacts
+ review
+ learning decision and outcome lineage
```

`agent-loop workflow manifest <task-id>` builds this relationship as a read-only
projection. `--write` atomically persists or repairs it at
`.agent-loop/runs/<task-id>/manifest.json`; normal status reads remain read-only.
The projection stores references and digests, not duplicate mutable domain state.
A run created before manifest support remains inspectable as `legacy_inferred`
and missing links are not fabricated.

PLAN, APPROVE and CLOSE refresh the stored projection after their owning
artifacts change. If a projection write fails, the command reports that domain
state may already have changed and names the explicit manifest/status recovery
path rather than pretending the transition was rolled back.

`workflow status` consumes the same projector. It also reports whether a stored
manifest is `missing`, `current`, or `stale`, so the human and agent paths no
longer maintain separate lifecycle interpretations.

## Where the model is still uneven

Recorded from real runs, so that the gaps are visible rather than rediscovered:

- **Guidance versioning.** Nothing verifies that installed skills match the
  installed runtime. A consumer can hold guidance describing a command its
  vendored package no longer accepts.
- **Focused inspection contracts.** The projection still reads some package
  paths directly. Board, session, map, recall and learning need small versioned
  reference APIs so `agent-loop` does not learn their private file layouts.
- **Direct mutations.** Package commands invoked outside the governed workflow
  can still make a stored projection stale. Status detects that drift; not every
  focused package mutation is expected to update the umbrella projection.
- **Durable outcome lineage.** The projection marks it `contract_pending`
  instead of guessing across immutable histories.
- **Consumer glue.** Hosts still add Make targets for sequencing and shell
  quoting. Every one of those is a candidate for upstream behaviour.
