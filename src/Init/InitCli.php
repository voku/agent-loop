<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class InitCli
{
    /**
     * @var array<string, array{usage: string, description: string, notes: list<string>}>
     */
    private const array COMMAND_HELP = [
        'doctor' => [
            'usage' => 'agent-loop init doctor [--config=PATH] [--skills-root=PATH] [--subagents-root=PATH] [--hooks-root=PATH] [--tools-root=PATH]',
            'description' => 'Diagnose local setup and repository-managed agent asset hints without changing them.',
            'notes' => ['Read-only diagnostics; path options override the configured asset roots for inspection.'],
        ],
        'status' => [
            'usage' => 'agent-loop init status [--config=PATH] [--skills-root=PATH] [--subagents-root=PATH] [--hooks-root=PATH] [--tools-root=PATH]',
            'description' => 'Show the activated CLI, host projection, local Git integration, sources, aliases, manifests, and next activation command.',
            'notes' => ['Read-only status; use the returned activation command for the next repository-owned setup action.'],
        ],
        'host-status' => [
            'usage' => 'agent-loop init host-status [--agent=<agent>] [--format=text|json]',
            'description' => 'Select or detect one coding host and return the canonical repository-owned setup action plus separate host/user boundaries.',
            'notes' => ['Use --agent when more than one supported host is visible on PATH.'],
        ],
        'paths' => [
            'usage' => 'agent-loop init paths [--format=text|json|toon]',
            'description' => 'Show where this project keeps workflow state so agents do not have to assume .agent-loop/.',
            'notes' => ['Read-only; use init scaffold to create missing local workflow state.'],
        ],
        'tools' => [
            'usage' => 'agent-loop init tools [--refresh] [--max-age=SECONDS] [--cache=PATH]',
            'description' => 'Probe and cache availability of the evidence and repository tools used by the workflow.',
            'notes' => ['Help does not run probes or update the tool inventory cache.'],
        ],
        'validate' => [
            'usage' => 'agent-loop init validate --kind=<skills|subagents|hooks|all> [--agent=<agent>] [--config=PATH] [--skills-root=PATH]',
            'description' => 'Validate repository-managed agent asset definitions for the selected kind.',
            'notes' => ['Validation reads definitions and reports errors; it does not project assets.'],
        ],
        'install-plan' => [
            'usage' => 'agent-loop init install-plan --profile=<profile> --agent=<agent>',
            'description' => 'Print an offline setup plan for package-owned assets.',
            'notes' => ['The plan is informational and never executes installation.'],
        ],
        'install-assets' => [
            'usage' => 'agent-loop init install-assets --agent=<agent|all> [--config=PATH] [--extra-skills-root=PATH ...] [--extra-subagents-root=PATH ...] [--no-package-skills] [--no-package-subagents] [--with-hooks] [--dry-run] [--force] [--adopt-existing] [--skip-git-config]',
            'description' => 'Install first-party workflow skills, subagents, project instructions, and optional host hooks and Git integration.',
            'notes' => ['Writes managed assets unless --dry-run is used; --with-hooks explicitly enables executable host-hook registration.'],
        ],
        'uninstall-assets' => [
            'usage' => 'agent-loop init uninstall-assets --agent=<agent> [--with-hooks] [--yes]',
            'description' => 'Plan and optionally remove the managed assets projected for one host.',
            'notes' => ['Manifest-scoped and fail-closed; nothing is removed without --yes, and project-owned paths are preserved.'],
        ],
        'sync-skills' => [
            'usage' => 'agent-loop init sync-skills --agent=<agent|all> [--config=PATH] [--skills-root=PATH ...] [--dry-run] [--force] [--adopt-existing]',
            'description' => 'Project skills into one managed client target from the configured or explicitly supplied project skill roots.',
            'notes' => ['Use --dry-run to inspect changes before writing; duplicate skill IDs fail closed.'],
        ],
        'sync-subagents' => [
            'usage' => 'agent-loop init sync-subagents --agent=<agent|all> [--config=PATH] [--subagents-root=PATH ...] [--dry-run] [--force] [--adopt-existing]',
            'description' => 'Project subagents into one managed client target from the configured or explicitly supplied roots.',
            'notes' => ['Use --dry-run to inspect changes before writing; duplicate subagent names fail closed.'],
        ],
        'sync-hooks' => [
            'usage' => 'agent-loop init sync-hooks --agent=<agent> [--config=PATH] [--hooks-root=PATH] [--dry-run] [--force] [--adopt-existing]',
            'description' => 'Synchronize repository-managed executable hooks into a Codex or Claude client target.',
            'notes' => ['Use --dry-run to inspect exact targets; host/user trust and Auto Mode remain outside this command.'],
        ],
        'sync-policy' => [
            'usage' => 'agent-loop init sync-policy --agent=<codex|claude|opencode|cursor|all> [--dry-run] [--force]',
            'description' => 'Merge only agent-loop-owned repository authority rules into the selected host policy.',
            'notes' => ['Host/user trust and Auto Mode are explicit boundaries and are never silently changed.'],
        ],
        'sync-githooks' => [
            'usage' => 'agent-loop init sync-githooks [--hooks-dir=PATH] [--commit-template=PATH] [--container-service=NAME] [--container-image=NAME] [--container-workdir=PATH] [--container-user=NAME] [--skip-git-config] [--dry-run] [--force] [--adopt-existing]',
            'description' => 'Install package-owned Git hooks and configure the repository hooks path and commit template.',
            'notes' => ['Use --dry-run to inspect changes; --skip-git-config leaves Git configuration untouched.'],
        ],
        'sync-instructions' => [
            'usage' => 'agent-loop init sync-instructions --agent=<agent|all> [--dry-run]',
            'description' => 'Update the agent-loop-owned AGENTS.md block and maintained host import shims.',
            'notes' => [
                'Only managed marker blocks are changed; project-owned text outside them is preserved. Use --dry-run to preview updates.',
                'Claude Code may load AGENTS.md through its built-in agents-md fallback, but the managed CLAUDE.md @AGENTS.md import remains the deterministic repository-level entrypoint.',
            ],
        ],
        'sync-tools' => [
            'usage' => 'agent-loop init sync-tools [--tools-root=PATH] [--tools-dir=PATH] [--config=PATH] [--dry-run] [--force] [--adopt-existing]',
            'description' => 'Install isolated evidence-tool projects such as itp-context and slop-scan under tools/.',
            'notes' => ['Writes project files only and never runs Composer. Use --dry-run to preview updates.'],
        ],
        'scaffold' => [
            'usage' => 'agent-loop init scaffold [--agent=<agent|all>] [--prefix=<PROJECT>|--demo] [--dry-run]',
            'description' => 'Create the minimum local workflow infrastructure for a real project or an opt-in tutorial board.',
            'notes' => ['Use --prefix for a real empty board or --demo for tutorial state; neither is invented by default.'],
        ],
    ];

    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $command = $tokens[0] ?? 'help';
        $rest = array_slice($tokens, 1);

        if (isset(self::COMMAND_HELP[$command]) && $this->containsHelpToken($rest)) {
            return $this->printCommandUsage($command);
        }

        return match ($command) {
            'help', '--help', '-h', '' => $this->printUsage(0),
            'doctor' => (new InitDoctorCommand($this->rootPath))->run($rest),
            'status' => (new InitStatusCommand($this->rootPath))->run($rest),
            'host-status' => (new InitHostStatusCommand($this->rootPath))->run($rest),
            'paths' => (new InitPathsCommand($this->rootPath))->run($rest),
            'tools' => (new InitToolsCommand($this->rootPath))->run($rest),
            'validate' => (new InitValidateCommand($this->rootPath))->run($rest),
            'install-plan' => (new InitInstallPlanCommand())->run($rest),
            'install-assets' => (new InitInstallAssetsCommand($this->rootPath))->run($rest),
            'uninstall-assets' => (new InitUninstallAssetsCommand($this->rootPath))->run($rest),
            'sync-skills' => (new InitSyncSkillsCommand($this->rootPath))->run($rest),
            'sync-subagents' => (new InitSyncSubagentsCommand($this->rootPath))->run($rest),
            'sync-hooks' => (new InitSyncHooksCommand($this->rootPath))->run($rest),
            'sync-policy' => (new InitSyncPolicyCommand($this->rootPath))->run($rest),
            'sync-githooks' => (new InitSyncGitHooksCommand($this->rootPath))->run($rest),
            'sync-instructions' => (new InitSyncInstructionsCommand($this->rootPath))->run($rest),
            'sync-tools' => (new InitSyncToolsCommand($this->rootPath))->run($rest),
            'scaffold' => (new InitScaffoldCommand($this->rootPath))->run($rest),
            default => $this->printUsage(1, $command),
        };
    }

    private function printUsage(int $exitCode, string $unknownCommand = ''): int
    {
        if ($unknownCommand !== '') {
            fwrite(\STDERR, "Unknown init command: {$unknownCommand}\n\n");
        }

        $usage = <<<'TXT'
        Usage:
          agent-loop init help
          agent-loop init doctor [--config=PATH] [--skills-root=PATH] [--subagents-root=PATH] [--hooks-root=PATH] [--tools-root=PATH]
          agent-loop init paths [--format=text|json|toon]
          agent-loop init status [--config=PATH] [--skills-root=PATH] [--subagents-root=PATH] [--hooks-root=PATH] [--tools-root=PATH]
          agent-loop init host-status [--agent=<agent>] [--format=text|json]
          agent-loop init tools [--refresh] [--max-age=SECONDS] [--cache=PATH]
          agent-loop init validate --kind=<skills|subagents|hooks|all> [--agent=<agent>] [--config=PATH] [--skills-root=PATH]
          agent-loop init install-plan --profile=<profile> --agent=<agent>
          agent-loop init install-assets --agent=<agent|all> [--config=PATH] [--extra-skills-root=PATH ...] [--extra-subagents-root=PATH ...] [--no-package-skills] [--no-package-subagents] [--with-hooks] [--dry-run] [--force] [--adopt-existing] [--skip-git-config]
          agent-loop init uninstall-assets --agent=<agent> [--with-hooks] [--yes]
          agent-loop init sync-skills --agent=<agent|all> [--config=PATH] [--skills-root=PATH ...] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-subagents --agent=<agent|all> [--config=PATH] [--subagents-root=PATH ...] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-hooks --agent=<agent> [--config=PATH] [--hooks-root=PATH] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-policy --agent=<codex|claude|opencode|cursor|all> [--dry-run] [--force]
          agent-loop init sync-githooks [--hooks-dir=PATH] [--commit-template=PATH] [--container-service=NAME]
                                       [--container-image=NAME] [--container-workdir=PATH] [--container-user=NAME]
                                       [--skip-git-config] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-instructions --agent=<agent|all> [--dry-run]
          agent-loop init sync-tools [--tools-root=PATH] [--tools-dir=PATH] [--config=PATH] [--dry-run] [--force] [--adopt-existing]
          agent-loop init scaffold [--agent=<agent|all>] [--prefix=<PROJECT>|--demo] [--dry-run]

        Commands:
          Every init subcommand accepts help, --help, and -h. Help is read-only and performs no setup operation.
          help              Show init help.
          doctor            Diagnose local setup and repo-managed agent asset hints.
          paths             Show where this project keeps its workflow state (read-only). Ask this instead of assuming .agent-loop/.
          status            Show what is activated here: resolved CLI path, host projection, local Git integration, sources, aliases, target manifests, and the next activation command (read-only).
          host-status       Auto-detect one probed canonical coding host, or use explicit --agent selection, then return one canonical repository-owned next action plus any separate host/user runtime boundary.
          tools             Probe and cache CLI tool availability (rg, jq, git, php, composer, docker, itp-context, slop-scan, agent-map index).
          validate          Validate repo-managed agent asset definitions.
          install-plan      Print an offline setup plan for package-owned assets. Does not execute it.
          uninstall-assets  Remove the managed assets this repository projected for one host. Manifest-scoped and fail-closed: unchanged managed entries are removed, locally modified or unverifiable entries are reported and kept, project-owned paths are never touched, and executable host hooks need --with-hooks. Prints the exact plan and removes nothing without --yes.
          install-assets    Install first-party workflow skills from agent-loop and its Recall dependency, bundled roles, the host's always-on project instruction entrypoint, and - when the repository declares a hook policy - the local Git hook/commit-template activation. Add --with-hooks to explicitly register bundled executable Codex/Claude host hooks.
          sync-skills       Project skills into one managed client target. With --skills-root, merge exactly those roots (duplicate skill IDs fail). Without it, copy the configured project skills root and prune only entries outside the config's desired set, so package copies install-assets projected are kept.
          sync-subagents    Project subagents into one managed client target. With --subagents-root, sync exactly those roots. Without it, copy the configured project subagents root and prune only entries outside the config's desired set.
          sync-hooks        Explicitly sync repo-managed executable hooks into a client target (Codex hooks.json, or the Claude settings.json hooks key). Use --dry-run to inspect exact targets before mutation.
          sync-policy       Merge only agent-loop-owned repository authority rules into Codex, Claude Code, OpenCode, or Cursor host policy; host/user trust, runtime execution, and Auto Mode remain explicit boundaries.
          sync-githooks     Install the package-owned Git hooks and point core.hooksPath / commit.template at them.
          sync-instructions Update only agent-loop-owned marker blocks in AGENTS.md and maintained host import shims; preserve project-owned instructions outside the markers.
          sync-tools        Install the isolated evidence tool projects (itp-context, slop-scan) under tools/. Writes project files only; never runs Composer.
          scaffold          Create minimum local workflow infrastructure. Use --prefix for a real empty board or --demo for tutorial board/task state; neither is invented by default.
        TXT;

        if ($unknownCommand === '') {
            echo $usage . "\n";
        } else {
            fwrite(\STDERR, $usage . "\n");
        }

        return $exitCode;
    }

    private function printCommandUsage(string $command): int
    {
        $help = self::COMMAND_HELP[$command];
        echo "Usage:\n  {$help['usage']} [--help|-h]\n\n";
        echo "Description:\n  {$help['description']}\n\n";
        echo "Notes:\n";
        foreach ($help['notes'] as $note) {
            echo "  - {$note}\n";
        }
        echo "\nAliases: help, --help, -h\n";

        return 0;
    }

    /** @param list<string> $tokens */
    private function containsHelpToken(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if ($token === '--help' || $token === '-h') {
                return true;
            }

            if ($token !== 'help') {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            if (!is_string($previous) || !str_starts_with($previous, '--') || str_contains($previous, '=')) {
                return true;
            }
        }

        return false;
    }
}
