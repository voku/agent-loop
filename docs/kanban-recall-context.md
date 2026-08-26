# Kanban context ownership

`agent-kanban` owns card format, parsing, revision identity, and board mutation. `agent-recall-compiler` owns how bounded Kanban facts participate in Recall selection and prompt compilation. `agent-loop` only adapts the typed card projection at the workflow boundary.

For governed `enter` and `workflow handoff`, Loop constructs Recall's released `KanbanContextProjection` in memory and passes its canonical JSON directly to the Recall provider. The projection contains only the task id, semantic card source path/revision, title, lane, status, priority, and next action consumed by Recall.

The derived projection is intentionally **not** stored in the workflow Session. Session remains pruneable working memory and does not become a persistence owner for board-derived Recall input. Recall provenance continues to point at the board-owned card source/revision rather than at an intermediate Session file.

Missing board configuration, non-Kanban task ids, or missing cards produce no Kanban projection. They do not authorize Loop to parse card Markdown privately or invent replacement board state.
