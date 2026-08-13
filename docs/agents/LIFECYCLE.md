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

### DISCOVER â€” `agent-map`

```bash
vendor/bin/agent-map query "D3"                       # exact-ish symbol lookup
vendor/bin/agent-map search "Wie werden AntrÃ¤ge storniert?" --semantic
```

- **Preconditions:** a built index (`.agent-loop/map/php-symbols.json`); the derived
  search index (`.agent-loop/map/search.sqlite`) only for `search`.
- **Produces:** nothing durable. Discovery is read-only by design.
- **Failure:** a stale index reports staleness rather than answering from it.
- **Recovery:** `agent-map refresh`, then `agent-map search-index refresh`.
- **Routing:** "does X exist for Y" is answered by `query` on the bare
  identifier; "how/where does X happen" by `search`. Measured, not assumed: a
  natural-language search for an accounting question ranked accounting readers
  first but never revealed that the other system existed, which `query` found in
  one call.

### PLAN â€” `agent-loop` + `agent-session`

```bash
vendor/bin/agent-loop workflow plan <task-id> --by <actor> --file <path> \
  --goal "..." --validation "..." [--tag <label>] [--behavior-anchor <text>] [--ephemeral]
```

- **Produces:** a Session under the project's sessions root (working memory;
  `agent-loop init paths`), work brief
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

### APPROVE â€” `agent-loop`

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
- **Automatic context:** when `.agent-loop/map/php-symbols.json` exists it is passed
  as `--map-index`, and `.agent-loop/map/search.sqlite` as `--map-search-index`, so
  the briefing carries map facts and ranked candidates without the host
  orchestrating anything.
- **Project policy:** every manifest listed in
  `.agent-loop/init.json` under `recall.document_manifests` is passed as
  `--document-manifest`. Existing manifest scope and tag matching decides which
  policies reach this task; a configured missing or unsafe path blocks approval
  completion while leaving the governed Run resumable.
- **Failure:** approving a revision that has since been revised is refused. If
  compilation fails after approval, the approval remains valid and the same
  command resumes compilation without approving identical scope twice.
- **Recovery:** fix the compiler input and rerun `workflow approve`.

### PREPARE â€” `agent-map` + `agent-recall-compiler`

Usually part of APPROVE. Standalone:

```bash
vendor/bin/agent-recall-compiler compile --root <learning-root> --task <task-id> \
  --map-index .agent-loop/map/php-symbols.json --map-root "$PWD" \
  --map-search-index .agent-loop/map/search.sqlite --document-manifest <manifest>
```

- **Binding:** every artifact records the map snapshot it was compiled against.
  A search index built from a different map is refused, not silently ranked
  against.
- **Failure:** unsupported schema versions, conflicting active rules, or a
  scope-relevant rejected proposal block the compile instead of writing a
  misleading briefing.

### EXECUTE â€” `agent-loop`

```bash
vendor/bin/agent-loop edit <Class::method> ... -- "instruction"
```

- **Produces:** `.agent-loop/edit/<task-id>/` with the bounded briefing and the
  execution result.
- **Optional:** many tasks never run `edit`. A task without an edit bundle is
  never asked for one.

### VERIFY â€” `agent-loop`

```bash
vendor/bin/agent-loop edit verify --bundle=.agent-loop/edit/<task-id>   # per bundle
vendor/bin/agent-loop verify --task-id <task-id>                        # cross-package
```

- **Produces:** `verification-result.json` with a status.
- **Cross-package `verify`** checks session/brief/recall coherence for the whole
  repository, or for one task with `--task-id`. Ephemeral sessions are skipped.
- **Failure:** an edit bundle that exists but was never verified blocks CLOSE.
  A bundle that never existed does not.

### REVIEW â€” `agent-loop`

```bash
vendor/bin/agent-loop review blindspots <task-id>
```

- **Produces:** a Markdown report, a JSON report and an L2 review prompt under
  `<recall-root>/<task-id>/reviews/`.
- **Note:** the review is required before CLOSE, and the first run legitimately
  warns that no review checkpoint exists yet. Record a checkpoint and re-run.

### LEARN â€” `agent-session` + `agent-learning`

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

### CLOSE â€” `agent-loop`

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

`workflow status` consumes the same proyï¯m¢G§²ÚîÆ­yÒFÆV&æ–æu&ö÷BÒv÷&¶fÆ÷tÆV&æ–æu&ö÷C£§&W6öÇfR‚GF†—2Óç&ö÷EF‚ÂF÷F–öç5²vÆV&æ–æu&ö÷BuÒ“°¢G6W76–öâÒGF†—2Óç&W&U6W76–öâ‚F6öçG&7B“°¢G'VâÒ†æWrv÷fW&æVE'Vå7F÷&R‚GF†—2Óç&ö÷EF‚’’Óç&W&R‚F6öçG&7BÂG6W76–öâÂFÆV&æ–æu&ö÷B“°¢G&V6ÆÄ–çWBÒGF†—2Óçw&—FTv÷fW&æVE&V6ÆÄ–çWB‚G'VâÂF6öçG&7B“°¢V6†ò%´ôµÒv÷&¶fÆ÷r&÷fS¢v÷fW&æVB'Vâ²G'VâÓç'Vä–GÒ&W&VBf÷"6öçG&7B&Wf—6–öâ²F6öçG&7BÓç&Wf—6–öçÕÆâ#°¢V6†ò%´ôµÒv÷&¶fÆ÷r&÷fS¢v÷&¶–ær6W76–öâ²G6W76–öâÓæ–GÒGF6†VBFòv÷fW&æVB'Vâ²G'VâÓç'Vä–GÕÆâ#°¢V6†òu´ôµÒv÷&¶fÆ÷r&÷fS¢v÷fW&æVB'Vâ&÷VæBFòGW&&ÆRÆV&æ–ær&ö÷Bp¢âF…&W6öÇfW#£§&VÆF—fUFò‚GF†—2Óç&ö÷EF‚Âv÷&¶fÆ÷tÆV&æ–æu&ö÷C£¦f÷%'Vâ‚GF†—2Óç&ö÷EF‚ÂG'Vâ’’â%Æâ#° ¢FÖæ–fW7EF‚Ò†æWr'VäÖæ–fW7EG&ç6—F–öåw&—FW"‚GF†—2Óç&ö÷EF‚’’Óçw&—FR‚GF6´–BÓçfÇVR“°¢V6†ò%´ôµÒv÷&¶fÆ÷r&÷fS¢&÷fVB×7FFR'Vâ&ö¦V7F–öâ&Vg&W6†VBB²FÖæ–fW7EF‡ÕÆâ#° ¢G&V6ÆÄ&w2Ò°¢v6ö×–ÆRrÂrÒ×&ö÷BrÂFÆV&æ–æu&ö÷BÀ¢rÒ×F6²rÂGF6´–BÓçfÇVRÀ¢rÒ×F6²Ö'&–VbrÂG&V6ÆÄ–çWBÀ¢Ó°¢F÷W&F–æu&ö×DÖæ–fW7BÒGF†—2Óæ÷W&F–æu&ö×DÖæ–fW7B‚F6öçG&7B“°¢–b‚F÷W&F–æu&ö×DÖæ–fW7BÓÒçVÆÂ’°¢G&V6ÆÄ&w5µÒÒrÒÖ÷W&F–ær×&ö×BÖÖæ–fW7Bs°¢G&V6ÆÄ&w5µÒÒF÷W&F–æu&ö×DÖæ–fW7C°¢Ğ¢FÆ–÷WBÒæWr&ö¦V7DÆ–÷WB‚GF†—2Óç&ö÷EF‚“°¢FFö7VÖVçDÖæ–fW7G2ÒFÆ–÷WBÓç&V6ÆÄFö7VÖVçDÖæ–fW7G2‚“°¢FÆV&æ–ætFö7VÖVçDÖæ–fW7BÒ'G&–Ò‚FÆV&æ–æu&ö÷BÂròr’âr÷&V6ÆÂÖFö7VÖVçG2æ§6öâs°¢–b†—5öf–ÆR‚FÆV&æ–ætFö7VÖVçDÖæ–fW7B’bb–åö'&’‚FÆV&æ–ætFö7VÖVçDÖæ–fW7BÂFFö7VÖVçDÖæ–fW7G2ÂG'VR’’°¢FFö7VÖVçDÖæ–fW7G5µÒÒFÆV&æ–ætFö7VÖVçDÖæ–fW7C°¢Ğ¢f÷&V6‚‚FFö7VÖVçDÖæ–fW7G22FFö7VÖVçDÖæ–fW7B’°¢G&V6ÆÄ&w5µÒÒrÒÖFö7VÖVçBÖÖæ–fW7Bs°¢G&V6ÆÄ&w5µÒÒFFö7VÖVçDÖæ–fW7C°¢Ğ¢F¶æ&ä6öçFW‡BÒ†æWrv÷&¶fÆ÷t¶æ&ä6öçFW‡Ew&—FW"‚GF†—2Óç&ö÷EF‚’’Óçw&—FR‚GF6´–BÓçfÇVRÂG6W76–öâ“°¢–b‚F¶æ&ä6öçFW‡BÓÒçVÆÂ’°¢G&V6ÆÄ&w5µÒÒrÒÖ¶æ&âÖ6öçFW‡Bs°¢G&V6ÆÄ&w5µÒÒF¶æ&ä6öçFW‡C°¢Ğ ¢FÖ–æFW‚ÒFÆ–÷WBÓæÖ–æFW‚‚“°¢–b†—5öf–ÆR‚FÖ–æFW‚’’°¢G&V6ÆÄ&w5µÒÒrÒÖÖÖ–æFW‚s°¢G&V6ÆÄ&w5µÒÒFÖ–æFWƒ°¢G&V6ÆÄ&w5µÒÒrÒÖÖ×&ö÷Bs°¢G&V6ÆÄ&w5µÒÒGF†—2Óç&ö÷EFƒ° ¢FÖ6V&6„–æFW‚ÒFÆ–÷WBÓæÖ6V&6„–æFW‚‚“°¢–b†—5öf–ÆR‚FÖ6V&6„–æFW‚’’°¢G&V6ÆÄ&w5µÒÒrÒÖÖ×6V&6‚Ö–æFW‚s°¢G&V6ÆÄ&w5µÒÒFÖ6V&6„–æFWƒ°¢Ğ¢Ğ¢FW†—BÒ‚GF†—2Óç&V6ÆÅ'VææW"’‚G&V6ÆÄ&w2“°¢–b‚FW†—BÓÒ’°¢gw&—FR€¢5DDU%"À¢%´d”ÅÒv÷&¶fÆ÷r&÷fS¢6öçG&7BæBv÷fW&æVB'Vâ&VÖ–â&W7VÖ&ÆRÂ'WB&V6ÆÂ6ö×–ÆF–öâf–ÆVBâ&W'VâF†R6ÖRv÷&¶fÆ÷r&÷fR6öÖÖæBgFW"f—†–ær6ö×–ÆW"–çWBåÆâ"À¢“° ¢&WGW&âFW†—C°¢Ğ ¢FÖæ–fW7EF‚Ò†æWr'VäÖæ–fW7EG&ç6—F–öåw&—FW"‚GF†—2Óç&ö÷EF‚’’Óçw&—FR‚GF6´–BÓçfÇVR“°¢V6†ò%´ôµÒv÷&¶fÆ÷r&÷fS¢6öçG&7B&÷fVBæBv÷fW&æVB&V6ÆÂ6ö×–ÆVBf÷"²GF6´–BÓçfÇVWÕÆâ#°¢V6†ò%´ôµÒv÷&¶fÆ÷r&÷fS¢6ö×–ÆVB×7FFR'Vâ&ö¦V7F–öâ&Vg&W6†VBB²FÖæ–fW7EF‡ÕÆâ#° ¢&WGW&â°¢Ò6F6‚…'VçF–ÖTW†6WF–öâFW†6WF–öâ’°¢gw&—FR€¢5DDU%"À¢u´d”ÅÒv÷&¶fÆ÷r&÷fS¢râFW†6WF–öâÓævWDÖW76vR‚¢â%Æå´5D”ôâ$UT•$TEÒ–ç7V7BvVçBÖÆö÷v÷&¶fÆ÷r7FGW2²GF6´–BÓçfÇVWÒÒÖf÷&ÖCÖ§6öâæB&W'Vâv÷&¶fÆ÷r&÷fRgFW"&W—"åÆâ"À¢“° ¢&WGW&â°¢Ò6F6‚…F‡&÷v&ÆRFW†6WF–öâ’°¢gw&—FR€¢5DDU%"À¢u´d”ÅÒv÷&¶fÆ÷r&÷fS¢GW&&ÆR6öçG&7BÖ’&R&÷fVBÂ'WB'Vâ&W&F–öâf–ÆVC¢p¢âFW†6WF–öâÓævWDÖW76vR‚¢â%Æå´5D”ôâ$UT•$TEÒ–ç7V7BvVçBÖÆö÷v÷&¶fÆ÷r7FGW2²GF6´–BÓçfÇVWÒÒÖf÷&ÖCÖ§6öâæB&W'Vâv÷&¶fÆ÷r&÷fRgFW"&W—"åÆâ"À¢“° ¢&WGW&â°¢Ğ¢Ğ ¢&—fFRgVæ7F–öâ&W&U6W76–öâ…F6´6öçG&7BF6öçG&7B“¢6W76–öà¢°¢FW†—7F–ærÒGF†—2Óæ7F—fU6W76–öâ‚F6öçG&7BÓçF6´–B“°¢–b‚FW†—7F–ærÓÒçVÆÂ’°¢&WGW&âFW†—7F–æs°¢Ğ ¢&WGW&â†æWr6W76–öå7F÷&R‚’’Óæ7&VFR€¢†æWr&ö¦V7DÆ–÷WB‚GF†—2Óç&ö÷EF‚’’Óç6W76–öç5&ö÷B‚’À¢F6öçG&7BÓçF6´–BÀ¢çVÆÂÀ¢F6öçG&7BÓçÆææVD'’À¢F6öçG&7BÓæ&6T6öÖÖ—BÀ¢“°¢Ğ ¢&—fFRgVæ7F–öâw&—FTv÷fW&æVE&V6ÆÄ–çWB„v÷fW&æVE'VâG'VâÂF6´6öçG&7BF6öçG&7B“¢7G&–æp¢°¢GF‚ÒF—&æÖR‚G'VâÓçF‚’âr÷&V6ÆÂÖ–çWBæ§6öâs°¢F–çWBÒ°¢w66†VÖ÷fW'6–öârÓâsãrÀ¢v¶–æBrÓâvv÷fW&æVE÷&V6ÆÅö–çWBrÀ¢w'Våö–BrÓâG'VâÓç'Vä–BÀ¢v6öçG&7BrÓâ°¢wF‚rÓârââòââö6öçG&7G2òrâF6öçG&7BÓçF6´–Bârö6öçG&7Bæ§6öârÀ¢w6†#SbrÓâG'VâÓæ6öçG&7E6÷W&6U²w6†#SbuÒÀ¢w&Wf—6–öârÓâF6öçG&7BÓç&Wf—6–öâÀ¢ÒÀ¢Ó°¢GF×ÒGF‚ârçF×ârâ&–ã&†W‚‡&æFöÕö'—FW2ƒb’“°¢–b†f–ÆU÷WEö6öçFVçG2‚GF×Â6æöæ–6Ä§6öã£§&WGG’‚F–çWB’’ÓÓÒfÇ6RÇÂ&VæÖR‚GF×ÂGF‚’’°¢VæÆ–æ²‚GF×“°¢F‡&÷ræWr'VçF–ÖTW†6WF–öâ‚uVæ&ÆRFòW'6—7Bv÷fW&æVB&V6ÆÂ–çWC¢râGF‚“°¢Ğ ¢&WGW&âGFƒ°¢Ğ ¢&—fFRgVæ7F–öâ÷W&F–æu&ö×DÖæ–fW7B…F6´6öçG&7BF6öçG&7B“¢÷7G&–æp¢°¢–b‚F6öçG&7BÓæ÷W&F–æu&ö×G2ÓÓÒµÒ’°¢&WGW&âçVÆÃ°¢Ğ¢FÖæ–fW7BÒF6öçG&7BÓæ÷W&F–æu&ö×DÖæ–fW7C°¢–b‚FÖæ–fW7BÓÓÒçVÆÂ’°¢F‡&÷ræWr'VçF–ÖTW†6WF–öâ‚t&÷fVB÷W&F–ær&ö×G2&WV—&R÷W&F–æu÷&ö×EöÖæ–fW7Bâr“°¢Ğ ¢G&W6öÇfVBÒF…&W6öÇfW#£¦¦ö–â‚GF†—2Óç&ö÷EF‚ÂFÖæ–fW7B“°¢–b‚—5öf–ÆR‚G&W6öÇfVB’’°¢F‡&÷ræWr'VçF–ÖTW†6WF–öâ‚t&÷fVB÷W&F–ær&ö×BÖæ–fW7Bæ÷Bf÷VæC¢râG&W6öÇfVB“°¢Ğ ¢&WGW&âG&W6öÇfVC°¢Ğ ¢ò¢ ¢¢&ÒÆ—7CÇ7G&–æsâGFö¶Vç0¢¢&WGW&â'&—¶'“¢7G&–ærÂÆV&æ–æu&ö÷C¢7G&–æwÆçVÆÇĞ¢¢ğ¢&—fFRgVæ7F–öâ'6R†'&’GFö¶Vç2“¢'&¢°¢F'’ÒçVÆÃ°¢FÆV&æ–æu&ö÷BÒçVÆÃ°¢f÷"‚F–æFW‚ÒÂF6÷VçBÒ6÷VçB‚GFö¶Vç2“²F–æFW‚ÂF6÷VçC²²²F–æFW‚’°¢GFö¶VâÒGFö¶Vç5²F–æFW…Ó°¢–b‚–åö'&’‚GFö¶VâÂ²rÒÖ'’rÂrÒÖÆV&æ–ær×&ö÷BrÂrÒ×&ö÷BuÒÂG'VR’ÇÂ—76WB‚GFö¶Vç5²F–æFW‚²Ò’’°¢F‡&÷ræWr–çfÆ–D&wVÖVçDW†6WF–öâ‚rÒÖ'’—2&WV—&VBâr“°¢Ğ¢GfÇVRÒG&–Ò‚GFö¶Vç5²²²F–æFW…Ò“°¢–b‚GfÇVRÓÓÒrr’°¢F‡&÷ræWr–çfÆ–D&wVÖVçDW†6WF–öâ‚GFö¶Vââr&WV—&W2æöâÖV×G’fÇVRâr“°¢Ğ¢–b‚GFö¶VâÓÓÒrÒÖ'’r’°¢F'’ÒGfÇVS°¢ÒVÇ6R°¢FÆV&æ–æu&ö÷BÒGfÇVS°¢Ğ¢Ğ¢–b‚F'’ÓÓÒçVÆÂ’°¢F‡&÷ræWr–çfÆ–D&wVÖVçDW†6WF–öâ‚rÒÖ'’—2&WV—&VBâr“°¢Ğ ¢&WGW&â²v'’rÓâF'’ÂvÆV&æ–æu&ö÷BrÓâFÆV&æ–æu&ö÷EÓ°¢Ğ ¢&—fFRgVæ7F–öâ7F—fU6W76–öâ‡7G&–ærGF6´–B“¢õ6W76–öà¢°¢G&ö÷BÒ†æWr&ö¦V7DÆ–÷WB‚GF†—2Óç&ö÷EF‚’’Óç6W76–öç5&ö÷B‚“°¢–b‚—5öF—"‚G&ö÷B’’°¢&WGW&âçVÆÃ°¢Ğ¢G6W76–öç2Ò'&•÷fÇVW2†'&•öf–ÇFW"€¢†æWr6W76–öå7F÷&R‚’’ÓæÆÂ‚G&ö÷B’À¢7FF–2fâ…6W76–öâG6W76–öâ“¢&ööÂÓâG6W76–öâÓçF6´–BÓÓÒGF6´–BbbG6W76–öâÓç7FGW2Óæ—46Æ÷6VB‚’À¢’“°¢–b†6÷VçB‚G6W76–öç2’â’°¢F‡&÷ræWr'VçF–ÖTW†6WF–öâ‚$×VÇF—ÆR7F—fR6W76–öç2f÷VæBf÷"²GF6´–GÒâ"“°¢Ğ ¢&WGW&âG6W76–öç5³ÒóòçVÆÃ°¢Ğ§Ğ