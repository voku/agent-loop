# Agent Assets In agent-loop

## Purpose

`agent-loop` ships portable, repository-managed agent behavior with the Composer
package. Consumers install reviewed files from their local `vendor/voku/agent-loop`
copy; `init` does not clone repositories, execute remote installers, or depend on
an external plugin marketplace.

Canonical package roots:

- `docs/agents/skills/`
- `docs/agents/subagents/`
- `docs/agents/codex-hooks/`
- `docs/agents/claude-hooks/`
- `docs/agents/tools/`

The design separates four concerns that are easy to muddle together when humans
discover another clever agent plugin on the internet:

1. **discipline**: the always-on behavioral invariants;
2. **workflow**: persisted task state and evidence-driven phase transitions;
3. **roles**: narrow investigator, builder, and reviewer contracts;
4. **host adapters**: the smallest client-specific representation of the same
   behavior.

The first three are canonical. Host adapters do not get to invent policy.

## First-party install

Review the plan:

```bash
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
```

Preview and install the package-owned assets:

```bash
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init install-assets --agent=all
vendor/bin/agent-loop init doctor
```

`--agent=all` installs:

- portable skills for Codex, Claude, Copilot, and Antigravity;
- investigator, surgical-builder, and code-reviewer subagent definitions for all
  four clients;
- repository-local discipline hooks for Codex and Claude.

The canonical role Markdown under `docs/agents/subagents/` is rendered into the
client format rather than duplicated as client-specific source. Model selection
remains a host/client concern; package-owned roles do not pin a provider or
reasoning level.

Package installation is intentionally boring:

- assets are read only from the installed Composer package;
- no host source override is accepted by `install-assets`;
- existing manifest-safe `sync-skills`, `sync-subagents`, and `sync-hooks`
  implementations perform the writes;
- unmanaged targets are not overwritten unless `--force` or `--adopt-existing`
  is explicit;
- `--dry-run` is supported;
- nothing is downloaded or executed remotely.

## Bootstrap execution contract

`agent-loop-discipline` is the compact session/subagent bootstrap. It does not
try to contain the whole manual. It establishes invariants and routes the agent
to the more specific skills.

When a task is explicitly governed by `agent-loop`:

```text
PLAN -> APPROVE -> CONTEXT -> IMPLEMENT -> VALIDATE -> REVIEW -> LEARN -> VERIFY -> CLOSE
```

The current phase comes from persisted workflow artifacts and observed command
results, never from conversational confidence. Scope drift returns to `PLAN` and
invalidates approval/evidence tied to the old brief revision. Human approval,
explicit risk ownership, irreversible actions, and genuinely missing product
intent remain human gates. Reads, edits, validation, review tooling, and reports
that the agent can perform remain agent work.

A useful progress update is deliberately small:

```text
RESULT: <verified result>
STATE: <phase> <task-id> <brief revision when known>
NEXT: <one agent-owned action or exact human gate>
```

Completion uses `RESULT`, `EVIDENCE`, and `OMITTED`. This compresses narration,
not source, diffs, tests, static-analysis output, errors, or verification
artifacts.

## Role routing

The discipline routes only when a narrow contract actually fits:

1. `agent-loop-investigate` / `agent-loop-investigator`: locate with `agent-map`,
   verify real source, report exact locations, never edit;
2. `agent-loop-surgical-edit` / `agent-loop-surgical-builder`: already-understood
   one/two-file edit, smallest diff, validate, escalate when scope expands;
3. `agent-loop-code-review` / `agent-loop-code-reviewer`: complete raw-diff
   correctness findings only;
4. `agent-loop-simplify-review`: current-diff complexity only;
5. `agent-loop-simplify-audit`: repo-wide complexity audit;
6. ambiguous, architectural, new-feature, or 3+ file work stays in the main
   governed workflow.

A common bounded chain remains:

```text
investigator -> surgical builder -> code reviewer
```

A one-line answer does not need three agents merely to make the activity diagram
look important.

## Minimal implementation discipline

The implementation ladder stays below workflow governance:

1. no change needed;
2. reuse existing repository behavior;
3. use PHP standard library;
4. use the native platform/database/shell/protocol capability;
5. reuse an installed dependency;
6. fix one verified root cause for all callers;
7. use deterministic `agent-loop edit --runner=auto` when an exact edit is proven;
8. only then add the minimum new code.

This never removes trust-boundary validation, security controls, data-loss
prevention, required concurrency/transaction guarantees, accessibility,
explicit requirements, or the smallest meaningful regression check.

## Learning remains the differentiator

The upstream inspirations help with role focus, implementation restraint, and
human attention. `agent-loop` adds the part they do not provide as one governed
contract:

```text
current evidence -> task finding -> proposal -> named human review -> durable guidance
```

A finding is not memory. A compact response is not evidence. A green close does
not approve a learning. Reusable conclusions pass through `agent-learning`; a
statically verifiable reviewed rule should move into the smallest executable
constraint that prevents recurrence.

## Repository hooks

Both supported hook bundles call the same typed `AgentDisciplineHook` runtime.
The client files only adapt host serialization and registration.

### Codex

- `SessionStart` injects the discipline;
- `SubagentStart` propagates it;
- `PreToolUse` leaves ordinary Bash commands untouched and denies configured
  unbounded `.agent-map` dump patterns.

Codex receives the `AGENT_LOOP_DISCIPLINE` system marker used by its hook
contract.

### Claude

- `SessionStart` runs on startup/resume/clear/compact/fork and injects the same
  discipline;
- `SubagentStart` propagates it;
- `PreToolUse` uses the same bounded-map policy;
- registration is merged into only the `hooks` key of `.claude/settings.json`;
- the context output omits Codex's `systemMessage` marker because Claude renders
  that field as a user-visible warning;
- context is bounded below Claude's current hook output limit.

Hooks are behavioral guardrails, never correctness or security boundaries. A
host can skip or disable them. Product code, CI, trust-boundary checks, workflow
gates, and package installation must remain correct without hook execution.

## Current commands

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init status
vendor/bin/agent-loop init tools
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-plan --profile=linux --agent=codex
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init sync-subagents --agent=codex --dry-run
vendor/bin/agent-loop init sync-hooks --agent=codex --dry-run
vendor/bin/agent-loop init sync-hooks --agent=claude --dry-run
vendor/bin/agent-loop init scaffold --dry-run
```

`doctor` and `status` are read-only. `tools` writes only the bounded tool
inventory cache. `install-plan` prints commands but executes none.
`install-assets` and `sync-*` are explicit mutation boundaries.

## agent-map boundary

Generated map files are navigation state, not source evidence:

```bash
vendor/bin/agent-loop map query <symbol>
vendor/bin/agent-loop map related <symbol>
vendor/bin/agent-loop map file <path>
vendor/bin/agent-loop map changed --base=<ref>
vendor/bin/agent-loop map stats
```

Query the index, then inspect the selected real source. Never dump
`.agent-map/php-symbols.json` or `.agent-map/search.sqlite` into a prompt.

## Host-repository overrides

Ordinary validation/sync commands may use host-owned canonical roots through
`.agent-loop/init.json`; `install-assets` intentionally cannot.

```json
{
  "version": 1,
  "paths": {
    "skills_root": "infra/doc/agents/skills",
    "subagents_root": "infra/doc/agents/subagents",
    "codex_hooks_root": "infra/doc/agents/codex-hooks",
    "claude_hooks_root": "infra/doc/agents/claude-hooks",
    "tools_root": "infra/doc/agents/tools",
    "recall_root": "infra/doc/agent-learning/recall-output"
  }
}
```

Do not edit generated client copies first. Update canonical host/package source,
validate it, then sync it.

## Dogfood contract

`composer dogfood:discipline` verifies the packaged discipline, hook behavior,
session/subagent propagation, unchanged raw commands, explicit non-security hook
boundary, and bounded map denial. Guidance changes additionally use
`agent-loop-dogfood`: observable tool/source/diff/validation/review artifacts,
not invented reasoning-token savings.

The release-set CI installs `agent-loop` into a clean Composer consumer and
exercises package-owned installation. A green installer proves mechanics, not
that guidance improved behavior; behavioral claims still require comparable
observed tasks.

## Provenance

`THIRD_PARTY_NOTICES.md` records the reviewed Caveman, Ponytail, and Attention
Control commits, the mechanisms adapted from each, and the parts deliberately
left out. None of those projects is a runtime dependency.

## Validation

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init doctor
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer dogfood:discipline
composer ci
```

Never report a command as passed unless its exit status was observed.
