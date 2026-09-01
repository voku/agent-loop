<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class InitCli
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $command = $tokens[0] ?? 'help';
        $rest = array_slice($tokens, 1);

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
          agent-loop init install-assets --agent=<agent|all> [--config=PATH] [--extra-skills-root=PATH ...] [--extra-subagents-root=PATH ...] [--with-hooks] [--dry-run] [--force] [--adopt-existing] [--skip-git-config]
          agent-loop init uninstall-assets --agent=<agent> [--with-hooks] [--yes]
          agent-loop init sync-skills --agent=<agent|all> [--config=PATH] [--skills-root=PATH ...] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-subagents --agent=<agent|all> [--config=PATH] [--subagents-root=PATH ...] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-hooks --agent=<agent> [--config=PATH] [--hooks-root=PATH] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-policy --agent=<codex|claude|opencode|all> [--dry-run] [--force]
          agent-loop init sync-githooks [--hooks-dir=PATH] [--commit-template=PATH] [--container-service=NAME]
                                       [--container-image=NAME] [--container-workdir=PATH] [--container-user=NAME]
                                       [--skip-git-config] [--dry-run] [--force] [--adopt-existing]
          agent-loop init sync-instructions --agent=<agent|all> [--dry-run]
          agent-loop init sync-tools [--tools-root=PATH] [--tools-dir=PATH] [--config=PATH] [--dry-run] [--force] [--adopt-existing]
          agent-loop init scaffold [--agent=<agent|all>] [--prefix=<PROJECT>|--demo] [--dry-run]

        Commands:
          help              Show init help.
          doctor            Diagnose local setup and repo-managed agent asset hints.
          paths             Show where this project keeps its workflow state (read-only). Ask this instead of assuming .agent-loop/.
          status            Show what is activated here: resolved CLI path, host projection, local Git integration, sources, aliases, target manifests, and the next activation command (read-only).
          host-status       Auto-detect one probed canonical coding host, or use explicit --agent selection, then return one canonical repository-owned next action plus any separate host/user runtime boundary.
          tools             Probe and cache CLI tool availability (rg, git, php, composer, docker, itp-context, slop-scan, agent-map index).
          validate          Validate repo-managed agent asset definitions.
          install-plan      Print an offline setup plan for package-owned assets. Does not execute it.
          uninstall-assets  Remove the managed assets this repository projected for one host. Manifest-scoped and fail-closed: unchanged managed entries are removed, locally modified or unverifiable entries are reported and kept, project-owned paths are never touched, and executable host hooks need --with-hooks. Prints the exact plan and removes nothing without --yes.
          install-assets    Install first-party workflow skills from agent-loop and its Recall dependency, bundled roles, the host's always-on project instruction entrypoint, and - when the repository declares a hook policy - the local Git hook/commit-template activation. Add --with-hooks to explicitly register bundled executable Codex/Claude host hooks.
          sync-skills       Prevalidate and merge one or more canonical skill roots into one managed client projection; duplicate skill IDs fail.
          sync-subagents    Sync repo-managed subagents into a client target directory.
          sync-hooks        Explicitly sync repo-managed executable hooks into a client target (Codex hooks.json, or the Claude settings.json hooks key). Use --dry-run to inspect exact targets before mutation.
          sync-policy       Merge only agent-loop-owned repository authority rules into Codex, Claude Code, or OpenCode host policy; host/user trust and Auto Mode remain explicit boundaries.
          sync-githooks     Install the package-owned Git hooks and point core.hooksPath / commit.template at them.
          sync-instructions Update only agent-loop-owned marker blocks in AGENTS.md and host import shims; preserve project-owned instructions outside the markers.
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
}
