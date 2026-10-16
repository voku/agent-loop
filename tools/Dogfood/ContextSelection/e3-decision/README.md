# E3 adoption decision: trim-1 vs native tools on the E1.3 CI log (disposable evidence)

Decision: **REJECT**. See issue #779. Nothing here is production code.

- trim-1 built from `caveman-ai/trim-1@f16ff28a9a84bfa7ffa89c12b7e20ad9e4a21c6a` (Go 1.24.7, stdlib only; `go test ./...` passes). Binary sha256 `49ee532da8d1f7f1b0612af6a347ab09882988d1d7def9fbf4aa602b211e946c`, model.bin sha256 `75478721d9992c3cbe6a1874ddf363ae6d78935a1fa526fb6fac1d0f6f3cdc2d`.
- Input: the original E1.3 log, 21,026 B / 237 lines, sha256 `2838527eaac6ffabbaa2b22599e5d62d356726a555f7b3d95dbf68d024702a56`.
- Query (identical for every arm): the frozen E2.0 question.
- `views/`: the exact bytes each arm showed the model (before ANSI/BOM stripping, which was applied identically to all arms).
- `blind-key.json`, `grading.json`: random session labels -> arm, and the manual grades.
- Model sessions: 12 fresh Claude (sonnet) subagent sessions, one Read of one file each, no repository access, no evaluator data. Per-session token/duration are subagent-reported totals including fixed overhead.
