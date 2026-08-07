# First-party Caveman + Ponytail adaptation dogfood

Date: 2026-08-07 (Europe/Berlin)

## Goal

The goal is not merely to remove RTK or copy two prompt ideas.

Review Caveman and Ponytail as actual add-on codebases, including their skills,
hooks, scripts, agent roles, installation mechanics, audit/debt/measurement
features, and tests. Keep the useful mechanisms, combine overlapping ideas, and
adapt them into package-owned `agent-*` behavior for PHP and `agent-map` without
adding third-party runtime dependencies.

The resulting behavior should:

- remove filler from human-facing agent communication so review takes less time;
- discourage speculative abstractions and unrequested implementation;
- locate code with bounded `agent-map` navigation before broad PHP reads;
- use small investigator/builder/reviewer roles where that reduces main-thread
  context and review effort;
- preserve raw source, diffs, tests, and verification evidence;
- record deliberate simplification debt in the existing workflow state instead
  of creating another persistence system;
- install entirely from the Composer package.

## Source review

A direct `git clone` was attempted first and failed because the execution
container had no DNS/network access. The upstream repositories were therefore
reviewed through the connected GitHub API at fixed commits:

- Caveman: `ec83e5bace4c20484d704dea21e12fc4eb94e9aa`
- Ponytail: `16f29800fd2681bdf24f3eb4ccffe38be3baec6b`

The review covered skills, dedicated agents, activation and mode hooks, subagent
propagation, model overrides, compression code, stats/gain behavior, install
helpers, rule-copy drift checks, debt/audit flows, tests, and benchmark guidance.

The adaptation also checked the current Codex implementation rather than guessing
its role format. `openai/codex` was reviewed at commit
`4ee41929eaf4fc1e5662c9b4befd05230688ca62`, specifically
`codex-rs/core/src/config/agent_roles.rs`. That source discovers project roles
from the config layer's `agents/` directory and requires standalone role files to
provide non-empty `name`, `description`, and `developer_instructions`.

## Mechanism inventory and adaptation

### Caveman core communication

**Upstream mechanism:** terse human-facing prose while preserving code, paths,
commands, errors, technical terms, and negation; automatically expand when
security, irreversible actions, ordering, or ambiguity make fragments unsafe.

**Adaptation:** `agent-loop-discipline` keeps the attention rule but uses normal
grammatical sentences. Persisted docs/PRs/commits remain normal prose and raw
evidence is never rewritten.

### Caveman review

**Upstream mechanism:** findings-only review with exact path/line, problem, and
fix; no praise, hedging, or review diary.

**Adaptation:** `agent-loop-code-review` and the
`agent-loop-code-reviewer` subagent. Correctness review remains separate from the
Ponytail-derived complexity pass.

### Cavecrew investigator / builder / reviewer

**Upstream mechanism:** three narrow agent roles with hard output contracts so a
subagent returns a small useful result instead of several paragraphs of
exploration narration.

**Adaptation:** three portable skills plus canonical subagent definitions:

1. `agent-loop-investigate` / `agent-loop-investigator`:
   `agent-map query|related|file|changed` -> bounded real-source read -> terse
   `path:line — symbol — role` evidence;
2. `agent-loop-surgical-edit` / `agent-loop-surgical-builder`: already-localized
   1-2 file work, deterministic `agent-loop edit --runner=auto` when possible,
   explicit scope escalation instead of silent widening;
3. `agent-loop-code-review` / `agent-loop-code-reviewer`: complete raw diff,
   caller lookup when required, actionable correctness findings only.

The current feature itself is deliberately too broad for the surgical-builder
role. It spans installation, skills, subagents, tests, CI, and docs, so forcing it
through a 1-2 file builder would violate the role contract. The role is useful
because it says **no** to the wrong task shape.

### Caveman model overrides

**Upstream mechanism:** mutate installed agent frontmatter from environment
variables so investigator/builder/reviewer can use different models.

**Decision:** not ported. Model choice is host/client policy. Encoding model names
or provider economics in `agent-loop` would weaken the portable asset boundary.

### Caveman init tool

**Upstream mechanism:** idempotently copy/append the canonical rule into multiple
client locations with dry-run and force behavior.

**Adaptation:** reuse the existing stronger `agent-loop init` machinery:
canonical package roots, client rendering, managed-entry manifests,
`--dry-run`, `--force`, and `--adopt-existing`. No second copy engine was added.

`init install-assets --agent=all` now installs:

- portable skills for Codex, Claude, Copilot, and Antigravity;
- the three dedicated role definitions for Codex, Copilot, and Antigravity;
- Codex PHP hooks.

The canonical role definitions are rendered per client instead of maintained as
three independent sources. Codex receives `.codex/agents/*.toml`, Copilot
receives `.github/agents/*.agent.md`, and Antigravity receives
`.agents/agents/*.md`.

### Caveman compress

**Upstream mechanism:** model-assisted rewriting of natural-language memory
files, with sensitive-path refusal, verbatim frontmatter preservation,
out-of-tree backup, atomic writes, structural validation, repair retries, and
restore on failure.

**Decision:** the destructive rewrite runtime is not ported. It sends source text
through a model/API and replaces durable files, which conflicts with this
project's evidence and memory boundaries.

**Adapted idea:** reduce context *before* loading it. `agent-map` selects source;
recall selects approved guidance. Durable memory remains the reviewed source of
truth instead of being lossy-compressed in place.

### Caveman stats

**Upstream mechanism:** read real session telemetry, account for rule overhead,
and admit when the feature is net-negative.

**Adaptation:** `agent-loop-dogfood` permits only observable run artifacts and
explicit baselines. No local token-savings number is emitted without actual
telemetry.

### Caveman commit helper

**Decision:** not ported. Commit-message convenience is useful but not an
`agent-loop` workflow responsibility. The umbrella package should not absorb
every feature present in an upstream add-on.

### Ponytail core

**Upstream mechanism:** understand the real flow first, then stop at the first
working rung: no change -> existing code -> stdlib -> native platform ->
installed dependency -> root-cause shared fix -> smallest new code. No
one-implementation interfaces, speculative factories/config, or future-only
scaffolding. Safety and the smallest meaningful check remain mandatory.

**Adaptation:** this is the minimal implementation ladder and safety floor in
`agent-loop-discipline`.

### Ponytail review

**Upstream mechanism:** diff-only review for deletion, stdlib/native reuse,
speculative abstraction, and smaller equivalent logic.

**Adaptation:** `agent-loop-simplify-review`, extended with repository reuse and
wrong-package-boundary findings.

### Ponytail audit

**Upstream mechanism:** apply the simplify review repo-wide and rank the biggest
maintenance cuts first.

**Adaptation:** `agent-loop-simplify-audit`. It starts with `agent-map`/bounded
navigation and verifies every candidate against real source/callers instead of
reading the entire repository indiscriminately.

### Ponytail debt

**Upstream mechanism:** `ponytail:` comments name the ceiling of a deliberate
shortcut and the trigger for upgrading it; a one-shot command harvests them into
a ledger.

**Adaptation:** no tool-specific product-code comments and no new ledger.
`agent-loop-task-progress` records a deliberate simplification as an
`agent-session` decision with:

- current choice;
- known ceiling;
- observable revisit trigger.

A reusable conclusion moves through the existing `agent-learning` review
boundary. "Later" and "if needed" are not valid triggers.

### Ponytail gain

**Upstream mechanism:** benchmark scoreboard plus an explicit honesty rule that
benchmark medians are not per-repository savings.

**Adaptation:** same honesty boundary in `agent-loop-dogfood`; no unbuilt
counterfactual is treated as evidence.

### Ponytail rule-copy drift script

**Upstream mechanism:** compare checked-in client rule copies with a canonical
source and pin load-bearing invariants that cannot be byte-compared.

**Adaptation:** `FirstPartyAgentAssetContractTest` validates every canonical
subagent definition and pins role invariants across each portable skill/subagent
pair. This protects the intentional duplication needed for client-specific role
surfaces without pretending the two files must be byte-identical.

### Ponytail mode/runtime/subagent hooks

**Upstream mechanism:** hidden mode file, UserPromptSubmit switching, client
specific JSON shapes, and SubagentStart re-injection.

**Adaptation:** no modes or hidden state. The default discipline is stable.
Codex's typed PHP `SessionStart`/`SubagentStart` hook propagates that discipline;
portable skills and rendered subagent definitions cover the supported client
surfaces.

## Behavioral iterations that changed the implementation

### External recommendation -> package ownership

The first PR version still recommended third-party add-ons. That failed the
actual goal. The behavior was moved into reviewed package assets instead.

### Oversized rule -> smaller discipline

The first combined discipline was 116 lines / 4,670 bytes. It was reduced to 99
lines without losing the minimal implementation ladder, map boundary, evidence
integrity, safety floor, or validation contract.

### Hook schema -> real parser behavior

Real Codex parser behavior rejected prototype `PreToolUse` combinations that
looked plausible as JSON. Pass-through now leaves commands unchanged and no
synthetic allow decision is generated.

### Fake security -> immutable installer

An early hook blacklist tried to block Caveman/Ponytail/RTK install commands.
Dogfood showed that this was the wrong boundary. It was deleted. The actual
security property is that `init install-assets` only reads the installed
Composer package and has no remote bootstrap path.

### Config propagation -> delete the config surface

A prior review used three broad repository probes with recorded output sizes of
8,119, 7,666, and 28,542 characters: **44,327 characters total**, then proposed
propagating `--config` further.

The disciplined replay inspected the owning installer/test/agent parsing
surfaces directly and reached the smaller result: package-owned installation
should not be configurable; host custom assets already belong to `sync-*`.

### Cavecrew adaptation -> use existing subagent sync

Once the upstream role mechanism was reviewed explicitly, the previous
skills+hooks-only installer was visibly incomplete. Rather than invent a new
role installer, `install-assets` now delegates to the existing
`InitSyncSubagentsCommand` for its supported targets.

### Codex roles -> extend the existing renderer

The first role port only rendered dedicated definitions for Copilot and
Antigravity because those were the targets already implemented by
`sync-subagents`. That was an implementation-history limitation, not an
architecture reason.

Checking current `openai/codex` source showed that project roles are discovered
from the configuration layer's `agents/` directory and standalone role TOMLs
must provide `name`, `description`, and `developer_instructions`. The existing
renderer was therefore extended with a third Codex target:

```text
docs/agents/subagents/agent-loop-investigator.md
  -> .codex/agents/agent-loop-investigator.toml
  -> .github/agents/agent-loop-investigator.agent.md
  -> .agents/agents/agent-loop-investigator.md
```

The Codex renderer intentionally emits no `model`, reasoning, sandbox, or
provider-specific setting. Those remain host/client policy.

`init status` uses the same Codex `.toml` projection and target root as
`sync-subagents`, so managed-entry drift is visible instead of becoming a second
silent contract.

### Rule duplication -> executable drift contract

Adding portable role skills and dedicated subagent definitions created an
intentional duplication seam. Ponytail's rule-copy script made that risk
obvious, so the port includes a PHP contract test instead of hoping both copies
stay aligned.

### Hook command parser -> reject suffix injection

A fresh review found that the initial hook validator accepted arbitrary trailing
shell text after an otherwise valid local PHP script. The parser now accepts only
the local hook script plus the supported `--event=SessionStart|SubagentStart`
argument. Shell separators, command substitution, and arbitrary extra arguments
have regression tests.

### Asset scan -> include nested hook scripts

The first package-asset safety test globbed `codex-hooks/*` and therefore saw the
`hooks/` directory but not the PHP files inside it. The test now scans
`codex-hooks/hooks/*.php` explicitly. A reassuring test name is not evidence that
the intended files were inspected.

## Observable behavioral replay

A clean identical-model A/B runner is not available in the connector-only
execution environment, so no hidden-reasoning or token-savings claim is made.
The comparison uses observed work from this PR.

| Metric | Earlier broad review | Disciplined replay |
| --- | ---: | ---: |
| Broad repository probes | 3 | 0 |
| Recorded broad-probe output | 44,327 chars | 0 chars |
| Focused owning files | not isolated | 3 |
| Additional config mechanism | proposed | removed |
| Raw evidence retained | yes | yes |

The change in behavior matters more than the output count: the replay found the
owner, removed an unnecessary mechanism, and stopped.

The role split also changed the current work. The upstream inventory was handled
as investigation first; the resulting feature was recognized as broader than a
surgical role and kept in the main workflow; correctness and simplification are
separate final passes instead of one giant "review everything" prompt.

## Runtime and installed-package gates

`composer ci` runs PHPUnit, PHPStan, and the hook dogfood gate.

GitHub Actions CI #386 on functional wiring head
`dceb7774a5d6a13eeeb375369904b0698548900b` passed:

- `composer ci` on PHP 8.3, 8.4, and 8.5;
- clean non-symlinked Composer consumer lifecycle;
- `init install-assets --agent=all` from the installed
  `vendor/voku/agent-loop` package;
- installed portable role skills;
- installed Copilot and Antigravity investigator/builder/reviewer definitions;
- installed Codex hooks;
- installed-package hook dogfood.

That run proved the then-current package-owned mechanism set without fetching the
upstream add-ons. The later native Codex role renderer, stricter hook parser,
status projection, and expanded asset tests intentionally invalidate it as a
final merge claim. The PR leaves draft only after the final head passes the same
PHP matrix and clean installed-consumer gate, including the generated Codex role
TOMLs.

## Acceptance boundary

This adaptation is ready only when the final head is green and the full diff
review finds no concrete correctness or simplify finding.

The goal is reached when Caveman/Ponytail are no longer merely named influences:
the useful mechanisms have explicit first-party implementations or explicit
architecture-based rejection reasons, and the resulting package changes how the
agent works without becoming another giant plugin runtime.
