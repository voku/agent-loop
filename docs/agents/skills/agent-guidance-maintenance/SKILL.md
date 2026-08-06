---
name: agent-guidance-maintenance
description: Maintain repo-managed agent skills, shared docs, metadata roots, and migration-safe validation while keeping concise behavior guidance separate from raw tool evidence.
---

# Agent Guidance Maintenance

Use this skill for repository-managed agent guidance: skills, shared docs,
validation flow, client sync targets, and host-repository migration notes.

## Fast Path

1. Edit the canonical source under `docs/agents/skills/` or `docs/agents/`.
2. Keep the change scoped to the guidance contract.
3. Update executable help and tests when public `init` behavior changes.
4. Validate the source layout and dry-run client synchronization.
5. Run `init doctor` when path, migration, or setup guidance changes.
6. Update README, changelog, or migration notes when the public contract changes.
7. Audit the resulting guidance for contradictory instructions and lossy evidence
   handling.

Use `agent-loop-php-discipline` for PHP changes. Caveman-style brevity may shape
human-facing replies. Ponytail-style minimalism may shape implementation. Neither
may rewrite shell commands, source files, diffs, test output, or verification
artifacts.

## Canonical Files

- `docs/agents/skills/`;
- `docs/agents/INFO_Agents.md`;
- `docs/agents/migration/`;
- `README.md`;
- `CHANGELOG.md`;
- `src/Init/`;
- `tests/Init*`.

Do not begin by editing generated client copies such as `.codex/`,
`.github/skills/`, or another installed target. Change the canonical source,
validate it, then sync it.

## Guidance Rules

- Describe behavior that exists now. Mark reserved or planned behavior clearly.
- Keep CLI precedence explicit: command-line option, repository config, then
  portable default.
- Keep Google client aliases mapped through canonical `antigravity` behavior.
- Prompt `rg` installation and `rg --version` verification before relying on
  `rg`-first search.
- Treat source, diffs, command output, and generated verification files as raw
  evidence.
- Summaries may help humans navigate evidence; they must not replace it.
- Reject command-rewriting or output-compression guidance that can hide changed
  lines, alter redirected file contents, or corrupt harness-managed artifacts.
- Keep concise communication guidance separate from implementation-minimization
  guidance. They solve different problems.
- Keep target-manifest safety explicit so `sync-*` removes only stale managed
  entries.

## Migration Notes

When adapting a host workflow:

- preserve the real source path and wrapper shape in the migration note;
- separate current `agent-loop init` capabilities from future work;
- prefer repeatable `sync` wording over one-off copying;
- inspect whether generated or redirected output is expected to remain byte
  complete;
- document Docker, Make, shell, and bind-mount boundaries without inserting a
  lossy proxy between the agent and the underlying evidence;
- use dedicated agent-facing targets only when they expose a real repeated
  workflow, not merely to manufacture another abstraction.

## Hard Constraints

When a reviewed learning is statically verifiable, prefer an executable
constraint over a memory-only sentence:

1. implement the smallest PHPStan rule or coding-standard check that proves the
   requirement;
2. register it in the active constraints metadata;
3. regenerate required bootstrap or autoload files;
4. baseline only verified legacy violations while preventing new ones;
5. test both a failing and accepted example;
6. sweep the full eligible backlog rather than enforcing only the newest item.

Do not turn subjective style preferences into noisy static-analysis rules. A
hard constraint must protect a real property.

## Validation

```bash
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init sync-skills --agent=codex --dry-run
vendor/bin/agent-loop init doctor
vendor/bin/phpunit --filter 'Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
```

Run the repository formatter or `composer ci` when defined. Never report a
command as passed unless it ran and its exit code was observed.

## Skill Boundary

This skill owns canonical agent guidance, init-facing setup and sync contracts,
migration notes, and validation of repo-managed assets.

It does not own unrelated product code, generated client copies as the source of
truth, speculative abstractions, or transformation of raw evidence.
