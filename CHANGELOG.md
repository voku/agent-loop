# Changelog

All notable changes to this project will be documented in this file.

## 0.0.4 - 2026-06-20

- `verify` is now a cross-package consistency check (`AgentLoopVerifier`):
  tasks, board, session/recall linkage with hash-based staleness
  detection, and the learning root, each skipping itself when its inputs
  are absent. The previous board-only check remains available as
  `board:verify`.
- Reworked the README around the package map, the exact verified
  commands, and an explicit "what agent-loop does not do" section.
- Added `tests/fixtures/basic-loop` and `SmokeLoopTest`, an end-to-end
  proof of session -> recall -> learn -> verify.
- Fixed `bin/agent-loop` missing its executable bit in git, which broke
  running it directly from a checkout (`./bin/agent-loop`); installs via
  Composer as a dependency were unaffected, since Composer force-sets
  `+x` on `vendor/bin/` proxies regardless of the source file's mode.
- Fixed the README's basic-workflow example: it passed the task id
  instead of the session id to `session record`/`checkpoint`/`close`,
  and omitted `--output-dir` on `recall compile`, so `agent-loop verify`
  failed if the example was followed literally.
- Added `examples/basic-loop`, a runnable walkthrough of the full loop
  against a tiny fake task, with real captured output.

## 0.0.3 - 2026-06-20

- Bumped `voku/agent-recall-compiler` dependency to version 0.5.*

## 0.0.2 - 2026-06-20

- Added `voku/agent-session` library integration

## 0.0.1 - 2026-06-20

- init commit
