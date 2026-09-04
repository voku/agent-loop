# Agent-loop ecosystem token diet

Measured on 2026-08-15 from the current package heads. The rule for this pass is simple: measure the model-facing path first, then change only the owner or consumer that can remove proven waste without changing meaning.

## Result

| Package | Evidence | Decision |
| --- | --- | --- |
| `agent-loop` | A realistic Kanban Recall fixture was 9,139 bytes with the previous projection and 1,894 bytes with the reduced projection: 79.3% less. Pretty-print whitespace alone accounted for only 145 bytes. Read-only structured Loop projections are also smaller as TOON than pretty JSON in regression tests. | Ship the smaller internal Recall projection and use TOON at selected agent-facing read boundaries. |
| `agent-recall-compiler` | `agent-loop`'s current `MEMORY.md` is 8,726 bytes and `MemoryRecallProvider` deliberately loads it as one global fact. That content is copied into `system.md`, so it is a real model-context floor, not JSON overhead. There is no task-scoping contract for memory rows yet. | No serialization patch. Scoped memory needs an explicit selection contract first. |
| `agent-map` | Read commands already default to bounded text output; machine consumers can request `toon`. CLI defaults cap results/files at 20, symbols/methods at 10, and context at 60,000 bytes. | Reuse its existing TOON dependency/capability; do not add another compact format. |
| `agent-session` | A representative `show` document with five checkpoints is about 1.5 KB; `list` already emits one compact metadata line per session. Session JSON does not echo decision, assumption, or validation bodies. | No patch. The original full-state-echo hypothesis is false. |
| `agent-learning` | `HistoryProjectionBuilder` already produces deterministic ID/digest projections instead of copying complete finding/proposal bodies. Governed workflow state uses typed Learning APIs; `backlog` is not a model-context hot path. | No patch. Existing summary/detail separation already owns this problem. |

## Consumer policy

Token efficiency belongs primarily at the consumer boundary:

1. **Same PHP process:** call the owning package's typed public API. Do not render a dependency result to CLI/JSON merely to parse it again.
2. **Agent-facing structured read:** request the smallest owner-defined projection and prefer TOON when available.
3. **Already-bounded text:** keep it when it is smaller than the structured document, for example focused context/report output.
4. **Durable evidence:** keep the canonical owner format. JSON remains the format for hashed, replayable, schema-owned, persisted, or explicitly interoperable artifacts.
5. **Projection is not truncation:** omit whole unused fields; preserve selected values completely. A missing projected key means "not requested", not "unset".

`agent-loop` therefore declares `helgesverre/toon` directly because it now uses the library itself; the package was already present in the ecosystem through `agent-map`, so this does not invent a second encoding dependency.

## Agent-loop Kanban Recall change

`WorkflowKanbanContextWriter` previously copied these card fields into Recall input:

- `title`
- `lane`
- `status`
- `priority`
- `summary`
- `next_action`
- `validation`
- `task_brief`
- `handoff_notes`

The Recall renderer and `workflow context` read only `title`, `lane`, `status`, `priority`, and `next_action`. The other four prose fields were carried through the compile pipeline and never rendered. The writer also truncated selected values even though projection and truncation are different contracts.

The new projection therefore keeps only the five consumed fields. `schema_version` stays `1.0`, `task_id` remains the projection identity, source path/revision stay intact, and retained keys keep their previous canonical order. `next_action` is emitted completely rather than truncated. The file is machine-to-machine JSON because it is a persisted Recall input artifact; pretty-print whitespace is removed while the trailing newline remains.

The regression fixture deliberately uses 1,100 bytes of summary, 1,600 bytes of next action, 900 bytes of validation, 2,600 bytes of handoff notes, and 2,800 bytes of task brief. On the previous writer the kept next action was cut at 1,200 characters. The new test requires the complete 1,600-character value and the exact reduced card key set.

## Agent-facing TOON reads

TOON is added only where the same structured document is transiently read by an agent:

- `init paths --format=toon`
- `workflow status <task-id> --format=toon`
- exact Git candidate/release evidence through `verify ... --format=toon`
- existing `agent-map ... --format=toon` discovery commands

The JSON forms remain unchanged for compatibility. Regression tests decode JSON and TOON and require equivalent map semantics while asserting that TOON uses fewer bytes. Associative key insertion order is deliberately not a cross-format contract.

`workflow context` and `workflow report` are not converted merely because TOON exists: their text renderers are already selective summaries. A compact encoding that causes a larger semantic payload would lose the point of the exercise.

## Agent-kanban release boundary resolved

The earlier rollout found that `agent-kanban#7` was merged but unavailable to ordinary Composer consumers, while `main` also contained a breaking default workspace move. Publishing that line as `0.2.2` would have hidden a breaking change behind a patch version.

`agent-kanban#8` therefore prepared and merged release `0.3.0` with the sibling packages' deterministic `.release/<version>.json` mechanism. The annotated `0.3.0` tag is bound to exact candidate `c98008d93e7c2acbfb2dae7418f5b5c925702866`. `agent-loop` now requires `voku/agent-kanban:^0.3.0`, so the `--fields` / `--compact` capability from #7 is installable in a clean consumer rather than merely present on upstream `main`.

The governed Loop path still uses Kanban's typed repository/domain API directly instead of round-tripping through the Kanban CLI. The new released CLI projection remains useful for agents and external consumers that genuinely cross the CLI boundary.

## Validation boundary

The remote work branch triggers the normal PHP 8.3/8.4/8.5 `composer ci` matrix plus installed release-set and deterministic slop dogfood. The local sandbox cannot clone GitHub because outbound DNS resolution is unavailable, so no local Composer result is claimed. CI status is reported from the exact branch commit instead of inventing a local pass.
