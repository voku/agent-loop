# Agent Assets In agent-loop

## Purpose

`agent-loop` owns workflow governance, persisted state, evidence boundaries, narrow role routing, and host projection. Reusable engineering judgment belongs to `voku/agent-skills` or the focused `agent-*` package that owns it.

The Composer package installs reviewed local assets only. Normal `init` does not clone repositories, run remote installers, or require a plugin marketplace.

Canonical package roots:

- `docs/agents/skills/`
- `docs/agents/subagents/`
- `docs/agents/codex-hooks/`
- `docs/agents/claude-hooks/`
- `docs/agents/tools/`

Do not duplicate engineering semantics in package workflow skills merely because a host can inject them at session start.

## First-party install and self-discovery

```bash
vendor/bin/agent-loop init host-status --format=json
```

When exactly one probed coding-host executable is visible, `host-status` selects it automatically. Otherwise it returns `decision_required` with the explicit `--agent` choice it needs. Antigravity currently has no stable CLI probe and therefore requires explicit selection.

Follow the returned `next_action_kind` / `next_action` until no repository-owned action remains. A normal convergence starts by installing the package-owned assets:

```bash
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init install-assets --agent=all
vendor/bin/agent-loop init host-status --format=json
```

A separately pinned `voku/agent-skills` checkout can be projected beside package workflow skills:

```bash
vendor/bin/agent-loop init install-assets \
  --agent=all \
  --extra-skills-root=/path/to/agent-skills/skills
```

`--extra-skills-root` is additive and repeatable. All roots are checked before target mutation; duplicate skill IDs fail rather than selecting a winner by source order. The caller owns provenance for additional local roots.

`--agent=all` projects workflow skills and package roles for Codex, Claude Code, OpenCode, Copilot, Gemini CLI, and Antigravity. Executable host hooks remain an explicit opt-in for Codex and Claude Code.

`host-status` distinguishes repository convergence from host/user authority:

- instructions, skills, and subagents are checked for current managed projections, not merely manifest presence;
- Codex, Claude Code, and OpenCode expose repository policy projection as a separate capability;
- Copilot, Gemini CLI, and Antigravity can still converge portable assets even though agent-loop has no repository policy projector for them;
- `runtime_boundary` describes trust, Auto Mode, or other host-owned decisions and is never authority to mutate them automatically.

## Bootstrap boundary

`agent-loop-discipline` is a compact workflow bootstrap, not a coding handbook. Session/subagent hooks may inject:

- persisted workflow/resume navigation;
- map-first source navigation;
- role and engineering-skill routing;
- uncertainty/evidence rules;
- hook and human-gate boundaries.

They deliberately do **not** inject the Ponytail-derived implementation ladder or PHP coding rules into every session.

When work is governed by `agent-loop`:

```text
PLAN -> APPROVE -> CONTEXT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE
```

Persisted workflow state beats conversational confidence. Scope drift returns to PLAN and invalidates evidence tied to the old Contract revision.

A resume hint from `.agent-loop/runs/*/manifest.json` is navigation only. Before governed mutation, resolve authoritative state with:

```bash
vendor/bin/agent-loop workflow status <task-id> --format=json
```

Free-form manifest prose such as `next_action` or disagreement text must never become hidden instructions.

## Engineering skill routing

Reusable implementation behavior is selected when the task needs it:

- `coding-simplicity`: coding, bug fixing, and refactoring with the smallest correct implementation;
- `php-best-practices`: PHP-specific engineering guidance;
- `code-review-*`: one dominant engineering review lens, with at most one evidence-backed handoff;
- `code-review-simplicity`: review-time complexity judgment, distinct from implementation-time `coding-simplicity`.

`coding-simplicity` is the first-party adaptation of Ponytail's useful implementation mechanics: understand the real flow, search no-change/reuse/stdlib/native/installed options before new code, fix the shared root cause, preserve safety constraints, and leave the smallest meaningful proof. It intentionally drops Ponytail's persona, intensity modes, output-style rules, and session-wide persistence.

If a required engineering skill is unavailable, report that capability gap. Do not silently recreate the missing skill inside `agent-loop-discipline`.

## Role routing

Use narrow roles only when their verified scope fits:

1. `agent-loop-investigate` / investigator: locate definitions, callers, and tests; never edit.
2. `agent-loop-surgical-edit` / surgical builder: already-understood one/two-file edit; load `coding-simplicity` for implementation choices when installed.
3. `agent-loop-code-review` / reviewer: complete raw diff through one dominant installed `code-review-*` lens.
4. `agent-loop-simplify-review`: current-diff simplicity review.
5. `agent-loop-simplify-audit`: bounded repository-wide simplicity audit.
6. Ambiguous, architectural, new-feature, or broader work stays in the main governed workflow.

Narrow roles return deterministic terminal status instead of hiding escalation in prose.

## Evidence and human gates

Never fabricate versions, paths, line numbers, command results, approvals, validation/review results, product intent, or runtime behavior. Read the owning source/state or run a safe probe when possible; otherwise name the exact unknown.

Human gates are limited to approval, actual risk/irreversible actions, and genuinely missing product intent. Reads, edits, tests, diagnostics, and reports available to the agent remain agent work.

Hooks are behavioral guardrails, never correctness or security boundaries. Product code, CI, trust-boundary checks, and workflow gates must remain correct when hooks do not execute.

## Host capability projection

`HostCapabilityMatrix` reports adapter evidence, not vendor marketing surface:

- `supported`: agent-loop owns a repository-side adapter/projector for the capability and contract tests can exercise it;
- `degraded`: a native adapter exists, but the stronger host runtime/delegation behavior has not been observed;
- `unsupported`: agent-loop has no adapter/projector for that capability.

Skill/subagent projection is not proof of host discovery or runtime execution. `init host-status` reports current projection separately and never upgrades file presence into runtime consumption. See `FIRST_PARTY_CAPABILITY_MATRIX.md` for the current matrix.

## Current commands

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init status
vendor/bin/agent-loop init host-status --format=json
vendor/bin/agent-loop init tools
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-plan --profile=linux --agent=codex
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init sync-policy --agent=codex --dry-run
vendor/bin/agent-loop init sync-policy --agent=claude --dry-run
vendor/bin/agent-loop init sync-policy --agent=opencode --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --skills-root=docs/agents/skills --dry-run
vendor/bin/agent-loop init sync-subagents --agent=codex --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
vendor/bin/agent-loop init sync-hooks --agent=claude --dry-run
```

`doctor`, `status`, and `host-status` are read-only. Mutation lives behind explicit `install-assets` / `sync-*` commands.

## Map boundary

Generated map files are navigation state, not source evidence:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map scope <symbol>
vendor/bin/agent-loop map context <symbol>
vendor/bin/agent-loop map changed --base=<ref>
```

For PHP symbols, callers, tests, and source ranges, query these projections before a generic source search. Use `rg` for literal/config/template questions and `rg --files` for file discovery; `grep`, `find`, and `sed -i` are blocked by the Codex guardrail. Query the index, then inspect selected real source. Never dump `.agent-loop/map/php-symbols.json` or `.agent-loop/map/search.sqlite` into a prompt.

## Dogfood contract

`composer dogfood:discipline` verifies the bootstrap boundary, hook behavior, safe resume projection, role routing, unchanged raw commands, and bounded map denial. In particular, it now proves the implementation ladder is **absent** from SessionStart/SubagentStart context.

PR CI additionally runs `tools/self-shape-dogfood.php` against the real PR diff. The installed release-set job installs the candidate into a clean Composer consumer and projects an exact pinned `voku/agent-skills` revision. That cross-repository run is the executable proof that workflow bootstrap and loadable engineering skills remain separate while still composing correctly.

A green installer proves projection mechanics only. Runtime/delegation claims require their own evidence.

## Provenance

- `FIRST_PARTY_CAPABILITY_MATRIX.md`: semantic owners and host projection boundaries.
- `UPSTREAM_CAPABILITY_MATRIX.md`: row-by-row adaptation decisions for upstream ideas.
- `THIRD_PARTY_NOTICES.md`: source pins and licensing.

A source recheck is not adaptation evidence, and an upstream benchmark is not proof of first-party equivalence.

## Validation

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init host-status --format=json
vendor/bin/agent-loop init doctor
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|InitHostStatus|OpenCodeHostProjection|Init'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer dogfood:discipline
composer ci
```

Never report a command as passed unless its exit status was observed.
