# PHP project onboarding with a coding agent

**Objective:** Ask an existing coding agent to set up Agent Loop for an existing PHP project. The coding agent uses repository evidence and Agent Loop's installed skill; Agent Loop does **not** need a second LLM integration.

This guide is for the project developer and the coding host. The skill source is `resources/skills/agent-loop-php-setup/SKILL.md` and is projected through the existing `init install-assets` mechanism.

## One-prompt onboarding (recommended)

Give your existing coding agent this task, without manually mapping PHPStan or PHP-CS-Fixer settings:

> Set up voku/agent-loop for this PHP repository. If it is not installed, inspect our Composer setup and propose the smallest safe development dependency change first. Once available, run `init recommend --format=json`, inspect our actual PHP tooling and scripts, preview the host installation, and use `agent-loop-php-setup` to produce and execute an approved setup plan. Preserve existing exclusions, validation gates and project instructions. Do not execute unknown PHP configs or install hooks without permission. Verify the result.

The **coding agent**, not the developer, performs discovery and proposes the work. The developer authorizes the dependency change, managed-file changes and privileged or executable integrations where required.

## First-time bootstrap (commands the agent can run)

From the PHP project root, the agent uses the project's normal Composer process after approval:

```bash
composer require --dev voku/agent-loop
vendor/bin/agent-loop init recommend --format=json
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
```

The `recommend` command is read-only and returns detected PHPStan/Composer scope, **declared Composer validation-script names (not executable approvals)**, PHP-CS-Fixer presence, existing Slop exclusions, prompting language and unresolved choices. The host is **not** expected to copy all of that into new configuration files.

Select the actual host (`codex` above is an example; `init host-status --format=json` helps identify it) and inspect the dry-run. With approval for the proposed changes:

```bash
vendor/bin/agent-loop init install-assets --agent=codex
```

This projects the package's `agent-loop-php-setup` skill into the coding host. Executable hooks are **not** installed unless `--with-hooks` is separately requested. Project-owned `AGENTS.md` content must remain intact.

## Tell the coding agent what to do

Once skills are available, use a request such as:

> Use `$agent-loop-php-setup` to configure Agent Loop for this existing PHP repository. Inspect Composer scripts, PHPStan, PHP-CS-Fixer, PHPUnit, Slop Scan, Agent Map and CI. Propose the smallest safe changes and preview their diffs. Preserve our existing quality gates, language, and exclusions. Apply approved changes, verify the installation and report remaining decisions.

The model already operating in the coding host interprets the evidence. No `OPENAI_API_KEY`, model-provider selection, embeddings or dedicated LLM API are needed for this workflow.

## What the agent should decide

| Evidence | Intended result | Boundary |
| --- | --- | --- |
| `composer.json`, `phpstan.neon` | Suggested map paths and actual analysis command | Paths are proposals, not confirmed scope |
| `.php-cs-fixer*.php` | Existing formatter command | Finder is executable PHP; never execute during discovery |
| `phpunit.xml`, Composer scripts | Existing test/CI commands | Do not assume `composer test` exists |
| `slop-scan.config.json` | Preserve existing scanner policy | No automatic reuse of PHPStan/Fixer exclusions |
| `.agent-loop/init.json` | Preserve explicit prompting preferences | An `en` default does not express human preference |
| Existing workflow gates | Keep human Attention cap distinct from independent reviewers | No silent policy relaxation |

The skill does not invent `init plan`/`init apply` commands. It can use the safe owner CLI commands already implemented, such as `init install-assets --dry-run`, `init sync-tools --dry-run`, `init doctor` and `init validate --kind=all`.

## Verification

The `PHP project onboarding skill dogfood` workflow runs against an isolated PHP consumer with realistic Composer, PHPStan and Slop configuration and a hostile PHP-CS-Fixer PHP file. It checks the read-only evidence, unchanged project files before installation, exact projected skill contents and the absence of executable host hooks. It uploads an exact-head receipt.

See [project-aware init evidence](project-aware-init.md) for the bounds of the current discovery command. Follow [#947](https://github.com/voku/agent-loop/issues/947) for proposed typed plan/diff/application improvements. The skill is independently useful without those future APIs.
