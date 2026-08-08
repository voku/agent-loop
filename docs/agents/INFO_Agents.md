# Agent Assets In agent-loop

## Purpose

`agent-loop` ships portable, repository-managed workflow behavior with the Composer
package. Consumers install reviewed files from their local `vendor/voku/agent-loop`
copy; `init` does not clone repositories, execute remote installers, or depend on
an external plugin marketplace at runtime.

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

Reusable engineering knowledge has a separate first-party owner: `voku/agent-skills`.
`agent-loop` may project an explicitly supplied local `agent-skills/skills` tree
alongside its package-owned workflow skills, but it does not copy that engineering
semantics into a second canonical source.

## First-party install

Review the plan:

```bash
vendor/bin/agent-loop init install-plan --profile=wsl2 --agent=codex
```

Preview and install package-owned assets only:

```bash
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init install-assets --agent=all
vendor/bin/agent-loop init doctor
```

To project a separately checked-out/pinned first-party engineering skill source in
the same managed skill sync, pass it explicitly as a local root:

```bash
vendor/bin/agent-loop init install-assets \
  --agent=all \
  --extra-skills-root=/path/to/agent-skills/skills
```

`--extra-skills-root` is repeatable. It never replaces the bundled workflow skill
root. All selected skill roots are collected before target mutation and written
through one managed manifest. If two roots contain the same skill directory name,
the install fails rather than choosing a winner by source order.

The caller owns provenance for additional local roots. A release/CI workflow may,
for example, check out an exact `voku/agent-skills` commit before calling
`install-assets`; normal `agent-loop` runtime does not fetch that repository.

`--agent=all` installs/projects:

- package-owned workflow skills, plus any explicit local skill roots, for Codex,
  Claude, Copilot, and Antigravity;
- investigator, surgical-builder, and code-reviewer subagent definitions for all
  four clients;
- repository-local discipline hooks for Codex and Claude.

The canonical role Markdown under `docs/agents/subagents/` is rendered into the
client format rather than duplicated as client-specific source. Model selection
remains a host/client concern; package-owned roles do not pin a provider or
reasoning level.

Package installation is intentionally boring:

- package-owned workflow skills, subagent roles, and hooks are always read from
  the installed Composer package;
- `install-assets` may merge explicit additional **local skill roots** only; it
  does not accept replacement sources for package roles or hooks;
- no remote source is downloaded by `install-assets` or `sync-skills`;
- duplicate skill IDs across canonical roots fail before target mutation;
- existing manifest-safe `sync-skills`, `sync-subagents`, and `sync-hooks`
  implementations perform the writes;
- unmanaged targets are not overwritten unless `--force` or `--adopt-existing`
  is explicit;
- `--dry-run` is supported.

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

### Resume without inventing state

Session and subagent hooks may prepend an `Agent Loop Resume Hint` when
`.agent-loop/runs/*/manifest.json` contains unfinished projected runs. The hint
contains only validated task identifiers and the small documented projected-state
vocabulary. It deliberately excludes `next_action`, disagreement messages,
references, task descriptions, and other free-form manifest content.

The hint is **navigation, not authority**. A run manifest is a derived projection.
Before any governed mutation, the agent resolves the actual task and runs:

```bash
vendor/bin/agent-loop workflow status <task-id> --format=json
```

A single unfinished task gets the exact status command in the hint. Multiple
unfinished tasks are listed in bounded form and the agent is told not to guess
which one owns the current request. Completed runs are omitted.

This gives resumed/compacted sessions and delegated agents enough state to avoid
starting a parallel conversational workflow without turning a writable JSON
projection into hidden instructions.

A useful progress update is deliberately small:

```text
RESULT: <verified result>
STATE: <phase> <task-id> <brief revision when known>
NEXT: <one agent-owned action or exact human gate>
```

Completion uses `RESULT`, `EVIDENCE`, and `OMITTED`. This compresses narration,
not source, diffs, tests, static-analysis output, errors, or verification
artifacts.

Unknown facts remain explicit state. The agent must inspect an owning artifact or
safe runtime observation when possible; it may not fabricate versions, paths,
line numbers, validation results, approvals, review results, or product intent to
make a receipt look complete. Repeated equivalent failures return to evidence
gathering or re-planning instead of stacking another speculative patch.

## Role routing

The discipline routes only when a narrow contract actually fits:

1. `agent-loop-investigate` / `agent-loop-investigator`: locate with `agent-map`,
   verify real source, report exact locations, never edit;
2. `agent-loop-surgical-edit` / `agent-loop-surgical-builder`: already-understood
   one/two-file edit, smallest diff, validate, escalate when scope expands;
3. `agent-loop-code-review` / `agent-loop-code-reviewer`: complete raw-diff review
   through one dominant installed `code-review-*` lens, with at most one evidence-backed handoff;
4. `agent-loop-simplify-review`: current-diff complexity only;
5. `agent-loop-simplify-audit`: repo-wide complexity audit;
6. ambiguous, architectural, new-feature, or 3+ file work stays in the main
   governed workflow.

A common bounded chain remains:

```text
investigator -> surgical builder -> code reviewer
```

Narrow roles use deterministic terminal status before their evidence:

```text
investigator: located | no_match | blocked
builder:      applied | scope_expanded | human_gate | ambiguous | regressed
reviewer:     findings | clean | blocked
```

This is deliberately stricter than merely asking each subagent to be terse. The
main thread can route a result without inferring intent from tone. A human gate
remains distinct from missing information, and a missing match remains distinct
from a guessed location.

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
explicit requirements, or the smallest meaningful regression check. Non-trivial
changed logic leaves the smallest runnable proof already appropriate for the
repository; trivial edits do not manufacture test ceremony.

Deliberate simplifications belong in task working memory with a known ceiling and
an observable revisit trigger. They do not become anonymous `TODO`s or permanent
tool-branded comments. Reusable conclusions cross the normal `agent-learning`
review boundary.

## Learning remains the differentiator

The upstream inspirations help with role focus, implementation restraint,
attention, and activation. `agent-loop` adds the part they do not provide as one
governed contract:

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

- `SessionStart` injects discipline plus a bounded unfinished-run hint when one
  exists;
- `SubagentStart` propagates the same discipline/resume boundary;
- `PreToolUse` leaves ordinary Bash commands untouched and denies configured
  unbounded `.agent-map` dump patterns.

Codex receives the `AGENT_LOOP_DISCIPLINE` system marker used by its hook
contract.

### Claude

- `SessionStart` runs on startup/resume/clear/compact/fork and injects the same
  discipline/resume boundary;
- `SubagentStart` propagates it;
- `PreToolUse` uses the same bounded-map policy;
- registration is merged into only the `hooks` key of `.claude/settings.json`;
- the context output omits Codex's `systemMessage` marker because Claude renders
  that field as a user-visible warning;
- context is bounded below Claude's current hook output limit, with resume state
  placed before the longer static discipline so it survives truncation.

Hooks are behavioral guardrails, never correctness or security boundaries. A
host can skip or disable them. Product code, CI, trust-boundary checks, workflow
gates, and package installation must remain correct without hook execution.

## Host capability projection

Different agent assets have different portability. `HostCapabilityMatrix` records
what **agent-loop currently implements**, not every feature a vendor may expose.
`init doctor` renders that current truth explicitly.

The first distinction is deliberate:

- `skill-projection=supported` means agent-loop can render/install the selected
  canonical skill roots for that host;
- `subagent-projection=supported` means agent-loop can render/install the
  canonical role representation for that host;
- neither statement by itself proves host discovery, delegated inheritance, or
  runtime execution.

Session bootstrap, subagent bootstrap, pre-tool guardrails, and repository hook
integration are separate capabilities. They remain `unsupported` for a host until
agent-loop has a verified native mechanism and executable evidence for it.

See `FIRST_PARTY_CAPABILITY_MATRIX.md` for semantic ownership and host projection
inventory.

## Current commands

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init status
vendor/bin/agent-loop init tools
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-plan --profile=linux --agent=codex
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init install-assets --agent=all --extra-skills-root=/path/to/agent-skills/skills
vendor/bin/agent-loop init sync-skills --agent=codex --skills-root=docs/agents/skills --skills-root=/path/to/agent-skills/skills --dry-run
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
`.agent-loop/init.json`:

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

`install-assets` does not accept that configuration as a replacement for its
package-owned workflow source. It may only **add** explicitly named local skill
roots via `--extra-skills-root`. Package roles/hooks stay immutable first-party
assets, and duplicate skill IDs across roots fail rather than override.

Do not edit generated client copies first. Update canonical host/package source,
validate it, then sync it.

## Dogfood contract

`composer dogfood:discipline` verifies the packaged discipline, hook behavior,
session/subagent propagation, deterministic narrow-role terminal contracts,
unchanged raw commands, explicit non-security hook boundary, bounded map denial,
and the workflow-resume projection boundary. The resume fixture includes hostile
free-form `next_action`/disagreement content and proves that only validated task
and projected-state identifiers enter hidden context.

Guidance changes additionally use `agent-loop-dogfood`: observable
tool/source/diff/validation/review artifacts, not invented reasoning-token
savings.

PR CI runs `tools/self-shape-dogfood.sh` against the real PR diff, so changes to
agent-loop pass through agent-loop's own governed lifecycle. The installed
release-set job separately installs the candidate into a clean Composer consumer.
For first-party engineering-skill integration it checks out an exact
`voku/agent-skills` revision in CI, passes that checkout as a local
`--extra-skills-root`, and verifies that package workflow skills and engineering
skills coexist in one managed projection across the supported host roots.

A green installer proves projection mechanics, not that a host executes every
installed capability. Runtime/delegation claims require their own evidence.

## Provenance

`FIRST_PARTY_CAPABILITY_MATRIX.md` maps our own semantic owners and current host
projection boundaries. It is the first place to check before adding another
workflow skill that may already be owned by `voku/agent-skills` or a focused
agent-* package.

`UPSTREAM_CAPABILITY_MATRIX.md` is the row-by-row integration inventory for
Caveman, Ponytail, and Attention Control. It distinguishes `ALREADY`, `ADAPT`,
`DEFER`, and `REJECT` mechanisms with concrete ownership/enforcement. A source
recheck is not itself an adaptation claim.

`THIRD_PARTY_NOTICES.md` records source pins, licensing, and the high-level
provenance summary. None of those projects is a runtime dependency.

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
