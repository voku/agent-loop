# Agent Loop (`voku/agent-loop`)

[![Build Status](https://github.com/voku/agent-loop/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-loop/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-loop/v/stable)](https://packagist.org/packages/voku/agent-loop)
[![Total Downloads](https://poser.pugx.org/voku/agent-loop/downloads)](https://packagist.org/packages/voku/agent-loop)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-loop/d/monthly)](https://packagist.org/packages/voku/agent-loop)
[![License](https://poser.pugx.org/voku/agent-loop/license)](https://packagist.org/packages/voku/agent-loop)
[![PHP Version Require](https://poser.pugx.org/voku/agent-loop/require/php)](https://packagist.org/packages/voku/agent-loop)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-loop?style=flat-square)](https://github.com/voku/agent-loop/stargazers)

**Keep coding-agent work moving without losing the task, guessing what comes next, or calling something done without evidence.**

Coding agents are already good at changing code. The harder problem is everything
around that change: remembering what was agreed, keeping the work inside the right
scope, giving the agent the context it actually needs, proving the result, and
carrying useful lessons into the next task.

`voku/agent-loop` adds that workflow around the coding agent you already use.

## How it works

```text
You have work to do
        |
        v
+------------------+
|    Agent Loop    |
|                  |
| keeps the task,  |
| context and      |
| evidence coherent|
+--------+---------+
         |
         v
+------------------+
|   Coding agent   |
|                  |
| understands,     |
| changes, tests   |
+--------+---------+
         |
         v
+------------------+
|  Prove the work  |
|                  |
| tests · analysis |
| review · evidence|
+--------+---------+
         |
    +----+----+
    |         |
 not done    done
    |         |
    +-- retry |
              v
       keep useful lessons
              |
              v
       better future work
```

Agent Loop does not replace the coding agent or your normal engineering tools. It
keeps the work around them durable: what was requested, what is allowed, what
context matters, what has been tried, and what evidence is needed before the task
is done.

The chat can disappear; the task should not.

Useful experience can feed later tasks. When a lesson becomes stable and
mechanically checkable, it can become a test, static-analysis rule, or another
deterministic check instead of another prompt instruction.

The goal is not maximum automation. The goal is **reliable agent work with less
reconstruction, less hidden state, and better evidence**.

## Why Agent Loop?

| Without it | With Agent Loop |
| --- | --- |
| A new chat means reconstructing the task | Approved task intent and progress survive the conversation |
| The agent decides what to do next from prose and memory | The workflow exposes the current next step |
| Large repositories become giant prompts | Only relevant repository context is selected |
| “Looks good” can quietly become “done” | Tests, analysis and review remain explicit evidence |
| Useful lessons disappear after one task | Proven lessons can inform later work |
| Instructions keep growing forever | Repeated objective lessons can become tests, static rules or other deterministic checks |
| Your workflow becomes tied to one model vendor | The workflow stays local and provider-independent |

## Install

```bash
composer require --dev voku/agent-loop
```

Requirements: PHP 8.3+ and Composer.

```bash
vendor/bin/agent-loop
```

## Start a repository

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

`install-assets --dry-run` shows the exact managed asset changes the current
project would receive without writing them. Detailed composition and host behavior
live in [Agent assets and host integration](docs/reference/agent-assets.md).

Portable assets are available for Codex, Claude Code, OpenCode, Copilot, Gemini
CLI, and Antigravity. Host-specific capabilities and limitations remain explicit.

## The two lifecycle commands

For everyday use, the workflow stays deliberately small:

```text
unplanned or existing task
  -> agent-loop enter <task-id>
  -> follow next_action_kind + next_action
  -> do the current work
  -> agent-loop finish <task-id>
  -> repeat until complete
```

Start or resume a durable task:

```bash
vendor/bin/agent-loop enter ABC-123 --format=json
```

For an unplanned task, `enter` is the front door before planning or approval.
Follow the returned `next_action_kind` and `next_action` rather than inventing a
sequence: `command_template` is model-owned work to complete from the request and
repository evidence; `decision_required` is a genuine human-authority decision.

After implementation or another requested action:

```bash
vendor/bin/agent-loop finish ABC-123 --format=json
```

`finish` reconciles current evidence and returns the next authoritative step.
Repeat until the lifecycle reports completion.

You do not need to memorize the internal package order or rebuild a phase machine
inside the prompt. See the [lifecycle contract](docs/workflow/lifecycle.md) when
you want the exact ownership model.

## What makes it different?

### The task survives the chat

Approved task intent and workflow state live outside the conversation. A later
agent can resume from current repository evidence instead of trusting a summary
of what somebody remembers happening.

### The agent gets less context, but better context

`agent-map` can locate the relevant PHP structure and `agent-recall-compiler`
can assemble task-specific context and prior knowledge. The agent reads what it
actually needs instead of swallowing the repository.

### Evidence beats confidence

Tests, static analysis, review artifacts, exact diffs, and recorded owner state
remain the evidence. An agent sounding certain is not a validation strategy.

### Useful experience can improve later work

`agent-learning` records evidence-backed observations and precedent. A useful
lesson can inform a future task; repeated objective lessons can eventually become
tests, PHPStan rules, fixers, typed APIs, or other deterministic constraints.

Once code or tooling owns the rule, obsolete prompt instructions can disappear
again instead of accumulating forever.

### Engineering judgment stays engineering judgment

`agent-loop` governs workflow authority and evidence. It does not turn every
engineering task into “make the smallest diff”. A surgical fix can stay surgical;
a broader feature or ownership correction can use the coherent solution the task
actually requires.

## Under the hood

You do not need to understand every package to use Agent Loop. They exist so each
kind of information has one clear owner instead of one giant agent framework
quietly owning everything.

| Concern | Package |
| --- | --- |
| Workflow and task authority | [`agent-loop`](https://github.com/voku/agent-loop) |
| Git-native work items | [`agent-kanban`](https://github.com/voku/agent-kanban) |
| Temporary working memory and validation evidence | [`agent-session`](https://github.com/voku/agent-session) |
| Repository structure and code navigation | [`agent-map`](https://github.com/voku/agent-map) |
| Bounded task context and prompt construction material | [`agent-recall-compiler`](https://github.com/voku/agent-recall-compiler) |
| Findings, precedent and durable learning | [`agent-learning`](https://github.com/voku/agent-learning) |
| Reusable engineering and review skills | [`agent-skills`](https://github.com/voku/agent-skills) |

Optional surfaces:

- [`agent-ui`](https://github.com/voku/agent-ui) provides a local human control plane.
- [`agent-loop-runner`](https://github.com/voku/agent-loop-runner) provides an optional execution plane for isolated coding-host runs.

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

## CLI namespaces

The stable command families are kept intentionally concise here; use
`vendor/bin/agent-loop help` or `<namespace> help` for the executable details.

```text
edit          governed edit preparation and execution
board         Git-native work items
session       temporary working memory and validation evidence
map           repository structure and code navigation
recall        bounded task context and outcome logging
learn         findings, proposals, constraints and learning history
verify        cross-package consistency checks
workflow      durable governed workflow orchestration
board:verify  board-only consistency checks
memory        MEMORY.md validation and review
review        deterministic review helpers
init          repository setup and managed agent assets
```

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
