# Cross-package artifact and identity inventory

Status: current-state inventory for [agent-loop#19](https://github.com/voku/agent-loop/issues/19)  
Scope: the supported `agent-*` package set as of 2026-08-06

This inventory records existing authorities before a run manifest is designed.
It deliberately separates an artifact's owner from the package that happens to
read it.

## Identity map

| Identity | Current owner | Current location/source | Authority | Current gap |
| --- | --- | --- | --- | --- |
| Task/card ID | `agent-kanban` when a card exists; otherwise orchestrator input | card metadata and filename, or CLI task argument | durable work-item identity | ad hoc tasks and card-backed tasks are not represented through one explicit run reference |
| Card revision | `agent-kanban` | SHA-256 `CardRevision` derived from card content | exact board-state revision | not yet linked into joined workflow status/run identity |
| Session ID | `agent-session` | `<sessions_root>/<session-id>/session.json` | task-local working-session identity | consumers resolve `sessions_root` through `ProjectLayout` (`agent-loop init paths`) |
| Session kind | `agent-session` | `session.json` `ephemeral` flag | governed versus experiment semantics | not yet exposed as part of one external run reference |
| Work-brief revision | `agent-session` | `work-brief.json` and history | exact candidate/approved scope revision | not collected with map/recall/edit identities |
| Approval | `agent-session` | `approval.json` | human approval bound to one brief revision | joined state must verify the referenced revision remains current |
| Validation evidence identity | `agent-session` | `validation-evidence.jsonl` | actual command execution bound to a brief revision | later runs can see evidence as stale, but the cross-package run has no single reference to the required records |
| Map schema and digest | `agent-map` | `.agent-loop/map/php-symbols.json` or TOON equivalent | canonical repository-fact snapshot | orchestration passes paths and reconstructs readiness/snapshot identity |
| Map analysis fingerprint | `agent-map` | canonical map metadata | semantic-analysis configuration/source identity | not collected with the brief and compilation in one projection |
| Search-index snapshot | `agent-map` | `.agent-loop/map/search.sqlite` metadata | derived retrieval cache identity | optional state is forwarded only when the file exists; readiness/degradation are not one product contract |
| Context source slice | `agent-map` | `EditContextPlan`/rendered recall facts | current source range plus source hash | exact context is bound to map/source, but task-level and inferred navigation results do not yet share one public reference contract |
| Compilation ID | `agent-recall-compiler` | recall artifacts and immutable selection/outcome lineage | one deterministic recall compilation | not explicitly linked to an orchestrator-owned run identity |
| Recall bundle | `agent-recall-compiler` | `recall.bundle.json` | canonical replayable briefing input/result | consumer locates it through output directory conventions |
| Fact bundle | `agent-recall-compiler` | `facts.json` | provider-resolved machine facts | not itself a complete run projection |
| Selection report | `agent-recall-compiler` | `selection-report.json` | guidance/constraint selection explanation | run status must join it to brief, artifacts and outcomes |
| Compiled system briefing | `agent-recall-compiler` | `system.md` | rendered agent-facing guidance/context | human-readable projection, not identity authority |
| Validation plan | `agent-recall-compiler` | `validation-plan.md` | rendered validation obligations | machine binding lives in other artifacts |
| Verification plan | `agent-recall-compiler` | `verification-plan.json` | public questions/checks/gates | run projection must bind it to the exact compilation and edit result |
| Verification key | `agent-recall-compiler` | `verification-key.json` | verifier-owned canonical answers | must remain outside the coding-agent prompt and linked only by hashes |
| Recall output hashes | `agent-recall-compiler` | `meta.json` | artifact-integrity evidence | no external run reference currently groups these hashes with later execution |
| Edit request identity | `agent-loop` | `.agent-loop/edit/<task-id>/request.json` | requested target/instruction and preparation options | directory task ID alone is not sufficient to represent retries/attempts without explicit binding |
| Execution identity | `agent-loop` | `execution.json` | runner and orchestration execution evidence | must be linked to compilation/plan/source snapshot in the run projection |
| Agent result | `agent-loop` | `agent-result.json` | agent answer sheet plus orchestrator-observed diff/command evidence | not authoritative for expected answers or verdicts |
| Verification result | `agent-loop` | `verification-result.json` | independent grading/gate result for one edit bundle | cross-package status still resolves it through bundle paths |
| Review identity | `agent-loop` with recall review tooling | `<recall-root>/<task-id>/reviews/` | blind-spot/code-review artifact identity | joined state knows existence, but the complete run has no explicit reference/digest set |
| Learning decision | `agent-session` | `learning-decision.json` | task-local close-out decision | must be connected to durable outcomes/findings without making session state permanent memory |
| Recall-selection event | `agent-recall-compiler`, consumed by `agent-learning` | `history/recall-selections.jsonl` | immutable record of guidance eligibility/selection | compilation identity exists, complete run identity does not |
| Guidance-outcome event | `agent-recall-compiler`, consumed by `agent-learning` | `history/outcomes.jsonl` | immutable explicit outcome per selected guidance item | complete versus missing outcomes must be joined to the run without guessing |
| Finding | `agent-learning` | finding record under learning root | evidence-backed observed candidate knowledge | source task exists, but explicit governed run linkage is not consistently available |
| Proposal | `agent-learning` | proposal lifecycle directories/history | reviewed durable mutation candidate/decision | lineage passes through findings, not one run reference |
| Accepted-risk record | `agent-loop` | `.agent-loop/risks/<task-id>.accepted-risk.{md,json}` | explicit human override of failed gates | must be part of status/manifest when present, not a replacement for the failed evidence |

## Authority rules

### Board

`agent-kanban` is authoritative for card parsing, revision, lane, status, claims,
transition policy and board verification. `agent-loop` may decide when those
facts block or advance a workflow, but it must not recreate them from Markdown.

### Working state

`agent-session` is authoritative for session identity, brief revisions,
approvals, validation records, checkpoints and the task-local learning decision.
The session remains pruneable working memory.

### Repository facts

`agent-map` is authoritative for canonical symbol/relation facts, freshness,
source hashes and search-index compatibility. Search candidates remain inferred
until confirmed through canonical relations/source.

### Briefing and verification contract

`agent-recall-compiler` is authoritative for guidance selection, effective
scope, provider digests, output hashes, public verification plan and the
separate verifier-owned key.

### Execution and verdict

`agent-loop` is authoritative for orchestration-owned edit request/execution,
observed diff evidence and the independent verification result. The coding
agent's result cannot grade itself.

### Durable learning

`agent-learning` is authoritative for findings, proposals, lifecycle decisions,
immutable outcome interpretation and maintenance/forgetting. A session decision
can say what happened for the task; it cannot silently mutate durable guidance.

## Existing cross-package bindings

The following bindings already exist and must be preserved:

- an approval names an exact work-brief revision;
- validation evidence names the brief revision it executed against;
- recall output records source/provider/map digests and output hashes;
- a search index is refused when its map snapshot differs;
- verification key is bound to the public plan hash;
- agent result is bound to the verification plan hash;
- verification result grades one exact plan/key/result/execution set;
- recall selection and guidance outcome events join through compilation and
  guidance identities;
- findings/proposals preserve evidence and source-finding lineage.

The run manifest must connect these bindings. It must not replace them.

## Legacy and optional states

A joined run model must represent these states without inventing evidence:

- ad hoc task with no board card;
- session created before explicit run-manifest support;
- file-only recall with no exact map target;
- no search index configured;
- no guidance selected, therefore no per-guidance outcomes required;
- task that never created an edit bundle;
- task with an edit bundle that has not been verified;
- ephemeral experiment intentionally excluded from governed gates;
- historical learning records without an external run reference;
- accepted-risk close with failed gates preserved.

## Required manifest references

The proposed projection should reference, not copy:

```text
run schema/capability version
run id
mode: governed | ephemeral | legacy/inferred
optional board reference
session reference
work-brief revision and approval reference
map/search/context reference
recall compilation and output-hash reference
edit/execution reference
verification reference
review reference
learning decision and outcome-lineage reference
accepted-risk reference when present
```

Each reference requires:

- owning package;
- owner schema/capability version;
- stable ID or path constrained to the project root;
- content digest where the owning artifact has one;
- observed status;
- observation mode (`checked`, `cached`, `unknown`, or `legacy_inferred`);
- disagreement details rather than an automatic repair.

## Blocking gaps before schema design is final

1. `agent-loop` currently has a task-level edit directory, but retries/attempts
   need an explicit identity decision before one manifest path is frozen.
2. Board-backed and ad hoc tasks need one mode model without making a board
   mandatory by accident.
3. The first manifest creation transition must be chosen: PLAN, APPROVE or
   PREPARE.
4. Guidance/runtime capability identities do not yet exist.
5. Focused packages need small inspection/reference contracts so `agent-loop`
   does not parse their private files indefinitely.
6. Legacy runs need an explicit migration/read strategy that never fabricates
   missing digests or approvals.

## Inventory completion criterion

This inventory is complete enough to begin manifest design when every field in
the first schema can point to one authority above and no field requires
`agent-loop` to duplicate mutable domain state.
