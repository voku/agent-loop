# Agent-loop ecosystem token diet

Measured on 2026-08-15 from the current package heads. The rule for this pass is simple: measure the model-facing path first, then change only the owner or consumer that can remove proven waste without changing meaning.

## Result

| Package | Evidence | Decision |
| --- | --- | --- |
| `agent-loop` | A realistic Kanban Recall fixture was 9,139 bytes with the previous projection and 1,894 bytes with the reduced projection: 79.3% less. Pretty-print whitespace alone accounted for only 145 bytes. | Ship the smaller internal Recall projection. |
| `agent-recall-compiler` | `agent-loop`'s current `MEMORY.md` is 8,726 bytes and `MemoryRecallProvider` deliberately loads it as one global fact. That content is copied into `system.md`, so it is a real model-context floor, not JSON overhead. There is no task-scoping contract for memory rows yet. | No serialization patch. Scoped memory needs an explicit selection contract first. |
| `agent-map` | Read commands already default to bounded text output; machine consumers can request `toon`. CLI defaults cap results/files at 20, symbols/methods at 10, and context at 60,000 bytes. | No patch. `--compact` JSON would optimize a non-default representation instead of the hot path. |
| `agent-session` | A representative `show` document with five checkpoints is about 1.5 KB; `list` already emits one compact metadata line per session. Session JSON does not echo decision, assumption, or validation bodies. | No patch. The original full-state-echo hypothesis is false. |
| `agent-learning` | `HistoryProjectionBuilder` already produces deterministic ID/digest projections instead of copying complete finding/proposal bodies. Governed workflow state uses typed Learning APIs; `backlog` is not a model-context hot path. | No patch. Existing summary/detail separation already owns this problem. |

## Agent-loop change

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

The new projection therefore keeps only the five consumed fields. `schema_version` stays `1.0`, `task_id` remains the projection identity, source path/revision stay intact, and retained keys keep their previous canonical order. `next_action` is emitted completely rather than truncated. The file is machine-to-machine JSON, so pretty-print whitespace is also removed while the trailing newline remains.

The regression fixture deliberately uses 1,100 bytes of summary, 1,600 bytes of next action, 900 bytes of validation, 2,600 bytes of handoff notes, and 2,800 bytes of task brief. On the previous writer the kept next action was cut at 1,200 characters. The new test requires the complete 1,600-character value and the exact reduced card key set.

## Agent-kanban release boundary

`agent-kanban#7` is merged on `main`, but the newest tag is still `0.2.1`. `main` is ten commits ahead of that tag and includes earlier breaking default-path changes as well as the projection API. `agent-loop` currently requires `voku/agent-kanban: 0.2.*`.

Therefore this pass does not pretend that `CardFieldSelection` or the new CLI flags are available in an installed `agent-loop` release. Tagging the current `agent-kanban` main as a `0.2.x` patch would also incorrectly hide breaking changes. A proper next `agent-kanban` release must happen before an installed consumer can depend on that API.

## Validation boundary

The remote work branch triggers the normal PHP 8.3/8.4/8.5 `composer ci` matrix on push. The local sandbox cannot clone GitHub because outbound DNS resolution is unavailable, so no local Composer result is claimed. CI status is reported from the exact branch commit instead of inventing a local pass.
