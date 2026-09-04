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

## agent-loop's own layout

`agent-loop` now follows the convention completely; `docs/agents/` no longer exists.

```text
resources/
  skills/                              # package-owned workflow skills
  subagents/                           # package-owned role briefs
  hooks/codex/  hooks/claude/          # opt-in executable host hook bundles
  tools/                               # isolated tool project templates
  githooks/                            # package-owned Git hooks
  make/agent-loop.mk                   # Make include for host repositories
  instructions/project-instructions.md # managed instruction projection source
  prompts/operating-prompts.json       # operating-prompt manifest
  review/                              # human review page assets
  recall-documents.json                # this repository's Recall document manifest

docs/
  architecture/  contributing/  dogfood/  policies/  reference/  testing/  workflow/
```

`src/PackageResources.php` is the single owner of those locations for runtime
code: setup, hook, prompt and review code ask it instead of each spelling a
physical directory, so moving an asset is one class change plus the move. Tests,
CI and the standalone dogfood runners still name the shipped directories
literally, on purpose - they are the check that the layout the owner promises is
the layout that shipped, and the release-set runners deliberately execute
without this package's autoloader. Consumer-configured roots (`paths.skills_root` and
friends in `.agent-loop/init.json`) default to the same relative layout, so a
host repository that keeps its own assets uses one convention rather than a
second one inherited from an older release.

Immutable records are the deliberate exception: `CHANGELOG.md` entries and dated
`docs/dogfood/` reports keep the paths that were true when they were written. A
record that is rewritten to match today's tree stops being evidence.

## Sibling repositories

The convention stays semantic rather than cosmetic: `agent-recall-compiler` and
`agent-learning` currently expose package-owned skills from a top-level
`skills/`, `agent-map` exposes a package Make include from its repository root,
while `agent-session` currently needs neither `docs/` nor `resources/` at all.

Normalize those through bounded slices with real consumer evidence, in the same
shape as this repository's move: give the owner one path projection, move the
files, update every first-party caller in the same slice, and delete the old
location rather than leaving a forwarding copy.
