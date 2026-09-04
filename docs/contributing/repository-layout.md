# Repository layout

Use repository paths to communicate semantics. The goal is not identical directory trees across every `agent-*` package; it is a predictable rule for equivalent things.

## Shared convention

- `docs/` contains human-readable explanation: workflows, architecture, contributor guidance, reference, policies, and dogfood reports.
- `resources/` contains static assets shipped or consumed by package runtime/setup: skills, subagents, prompt manifests, host hooks, review assets, templates, package-provided Git hooks, and Make includes.
- `src/` contains runtime implementation.
- `tests/` contains executable verification.
- `tools/` contains maintainer/development executables and dogfood tooling.
- `examples/` contains consumer-facing examples and fixtures.
- repository root is reserved for ecosystem/package/host-discovery conventions such as `README.md`, `CHANGELOG.md`, `LICENSE`, `composer.json`, and host-discovered instruction files.

Do not create empty directories merely to make sibling repositories look identical. Same semantics should use the same convention; absent semantics need no placeholder tree.

## Before moving a path

1. Search current production code, tests, CI, docs, dogfood, and sibling consumers for the path.
2. Classify the entry as `ROOT_CONVENTION`, `HUMAN_DOC`, `PACKAGE_RESOURCE`, `RUNTIME_CODE`, `TEST`, `DEV_TOOL`, `CONSUMER_EXAMPLE`, `GENERATED_OR_STATE`, or `UNCLEAR`.
3. If the path is consumed, move the owner path and update first-party callers/tests/docs in the same slice.
4. Prefer a coordinated pre-1.0 move for internal paths. Do not leave forwarding copies merely because a 0.x layout once existed.
5. Preserve root placement when a real tool or ecosystem convention discovers the file there.

## Current evidence

`agent-loop` currently mixes human docs and installable assets below `docs/agents/`, while package resources also already exist below `resources/`. Runtime setup code knows paths such as `docs/agents/skills`, `docs/agents/codex-hooks`, and `docs/agents/claude-hooks`; those are package-resource candidates, not ordinary documentation.

Sibling evidence shows why the convention must remain semantic rather than cosmetic: `agent-recall-compiler` and `agent-learning` currently expose package-owned skills from top-level `skills/`, `agent-map` exposes a package Make include from repository root, while `agent-session` currently needs neither `docs/` nor `resources/` at all.

Normalize these only through bounded slices with real consumer evidence. A path move that reveals broad path-pinned dogfood or unstable consumers should be split or preceded by a smaller owner-boundary improvement rather than papered over with compatibility copies.
