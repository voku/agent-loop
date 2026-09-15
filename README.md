# Agent Loop (`voku/agent-loop`)

[![Build Status](https://github.com/voku/agent-loop/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-loop/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-loop/v/stable)](https://packagist.org/packages/voku/agent-loop)
[![Total Downloads](https://poser.pugx.org/voku/agent-loop/downloads)](https://packagist.org/packages/voku/agent-loop)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-loop/d/monthly)](https://packagist.org/packages/voku/agent-loop)
[![License](https://poser.pugx.org/voku/agent-loop/license)](https://packagist.org/packages/voku/agent-loop)
[![PHP Version Require](https://poser.pugx.org/voku/agent-loop/require/php)](https://packagist.org/packages/voku/agent-loop)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-loop?style=flat-square)](https://github.com/voku/agent-loop/stargazers)

**Make coding-agent work resumable, auditable, and governed.**

Coding agents are already good at changing code. The harder problem is keeping
scope, context, evidence, decisions, and lessons coherent across a real task.

`voku/agent-loop` adds a local workflow around the coding agent you already use:

```text
human intent
  -> explicit task contract
  -> current bounded context
  -> implementation with normal repository tools
  -> validation and review evidence
  -> useful learning
  -> governed close
```

It does not replace your coding agent, Git, tests, PHPStan, or code review. It
connects them into one durable workflow so a task can survive a new chat, a new
agent, or a later continuation without reconstructing the truth from memory.

## Why Agent Loop?

| Problem | Agent Loop |
| --- | --- |
| The chat forgets what was agreed | Persists task intent, scope, validation, and run state |
| An agent guesses what to do next | Exposes one canonical `next_action_kind` / `next_action` |
| Large repositories overflow prompt context | Uses Map and Recall to select bounded, current context |
| “Looks good” becomes fake evidence | Keeps validation, review, and verification explicit and auditable |
| Useful lessons disappear after the task | Records findings and promotes only reviewed, reusable learning |
| Guidance grows forever | Prefers owner APIs, typed projections, tests, static rules, and deletion of obsolete prose |
| One agent vendor becomes the architecture | Keeps the workflow local and provider-independent |

The goal is not maximum automation. The goal is **reliable agent work with less
reconstruction, less hidden state, and better evidence**.

## Install

```bash
composer require --dev voku/agent-loop
```

Requirements:

- PHP 8.3+
- Composer

The CLI is available as:

```bash
vendor/bin/agent-loop
```

## Quick start

Create the repository-local workflow scaffold:

```bash
vendor/bin/agent-loop init scaffold
```

Or create the tutorial board and task:

```bash
vendor/bin/agent-loop init scaffold --demo
vendor/bin/agent-loop board card show DEMO-1
```

Then follow [Your first governed task](docs/quick-start.md).

For supported coding hosts, package-owned skills and roles can be projected from
the installed Composer package without downloading remote agent code:

```bash
vendor/bin/agent-loop init install-assets --agent=all --dry-run
vendor/bin/agent-loop init install-assets --agent=all
vendor/bin/agent-loop init host-status --format=json
```

Portable assets are available for Codex, Claude Code, OpenCode, Copilot, Gemini
CLI, and Antigravity. Host-specific capabilities and limitations remain explicit.

## The workflow

For normal durable work, the host-facing lifecycle is intentionally small:

```text
plan + approve task intent
        ↓
agent-loop enter <task-id>
        ↓
obey current next_action
        ↓
implement the approved outcome coherently
        ↓
agent-loop finish <task-id>
        ↓
obey current next_action
        ↓
complete
```

The coding agent does not need to memorize internal package ordering. It consumes
the current lifecycle result instead.

```bash
vendor/bin/agent-loop enter ABC-123 --format=json
```

The result tells the host whether the next step is a command, model-owned work, a
real human decision, or completion.

After implementation or another requested action:

```bash
vendor/bin/agent-loop finish ABC-123 --format=json
```

`finish` reconciles the current task evidence and returns the next authoritative
step. No parallel checklist is required in the host prompt.

See the [lifecycle contract](docs/workflow/lifecycle.md) for the exact ownership
model.

## What makes it different?

### Durable task truth

Approved task intent and workflow state live outside the chat. A later agent can
resume from current repository evidence instead of trusting a conversational
summary.

### Bounded context instead of bigger prompts

`agent-map` locates PHP symbols, callers, dependencies, and change scope.
`agent-recall-compiler` compiles task-relevant operational context. The agent reads
the source it actually needs instead of swallowing the repository.

### Evidence before confidence

Tests, static analysis, review artifacts, exact diffs, and owner state remain the
evidence. Agent confidence is not a gate.

### Learning that can disappear again

`agent-learning` records observations, findings, and reviewed precedent. Repeated
objective lessons can later become tests, PHPStan rules, fixers, typed APIs, or
other deterministic constraints.

Once structure owns the rule, obsolete prompt knowledge should be deleted rather
than accumulated forever.

### Engineering judgment stays engineering judgment

`agent-loop` governs workflow authority and evidence. It does not turn every
engineering task into “make the smallest diff”. A surgical fix can stay surgical;
a broader feature or ownership correction can use the coherent solution the task
actually requires.

## Ecosystem

The packages are focused so each concern has one semantic owner:

| Package | Owns |
| --- | --- |
| [`agent-loop`](https://github.com/voku/agent-loop) | Governed lifecycle, orchestration, verification, setup |
| [`agent-kanban`](https://github.com/voku/agent-kanban) | Git-native work items |
| [`agent-session`](https://github.com/voku/agent-session) | Task-local working state and validation evidence |
| [`agent-map`](https://github.com/voku/agent-map) | PHP code intelligence and bounded navigation |
| [`agent-recall-compiler`](https://github.com/voku/agent-recall-compiler) | Task context, Recall artifacts, L2 prompt contracts |
| [`agent-learning`](https://github.com/voku/agent-learning) | Findings, precedent, proposals, durable learning |
| [`agent-skills`](https://github.com/voku/agent-skills) | Reusable engineering and review skills |

Optional surfaces:

- [`agent-ui`](https://github.com/voku/agent-ui) provides a local human control plane.
- [`agent-loop-runner`](https://github.com/voku/agent-loop-runner) provides an optional execution plane for isolated coding-host runs.

You do not need to understand every package before using `agent-loop`. The public
lifecycle exists specifically so hosts do not reconstruct those internals.

## Boundaries

`agent-loop` deliberately does **not**:

- call an LLM by itself;
- auto-commit, auto-push, or auto-merge;
- silently invent human approval or risk acceptance;
- replace tests, PHPStan, code review, or repository-native tools;
- treat generated Map output as source evidence;
- turn every observation into durable memory;
- make hooks a correctness or security boundary;
- require one particular coding-agent provider.

The workflow is local-first and auditable through files, Git, and executable
owner contracts.

## Useful commands

```bash
vendor/bin/agent-loop help
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init host-status --format=json
vendor/bin/agent-loop workflow status ABC-123 --format=json
vendor/bin/agent-loop map query SomeClass
vendor/bin/agent-loop verify --task-id=ABC-123
```

The CLI is the executable reference. Detailed specialist commands remain
available when the current task needs them; their existence does not make them
mandatory workflow phases.

## Documentation

Start here:

- [Your first governed task](docs/quick-start.md)
- [Cross-package lifecycle and ownership](docs/workflow/lifecycle.md)
- [Agent assets and host integration](docs/reference/agent-assets.md)
- [Learning and durable-memory boundary](docs/workflow/learning-boundary.md)
- [Pre-1.0 compatibility and durable-state contract](docs/compatibility.md)

For contributors and deeper design evidence:

- [Real-issue acceptance model](docs/dogfood/real-issue-acceptance.md)
- [First-party discipline dogfood](docs/dogfood/2026-08-07-first-party-discipline.md)
- [Third-party mechanism mapping and notices](docs/reference/third-party-notices.md)

## Development

```bash
composer install
composer test
composer phpstan
composer ci
```

Never report a command as passed unless it actually ran and its exit code was
observed.

## License

MIT. See [LICENSE](LICENSE).
