<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class InitCli
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        $command = $tokens[0] ?? 'help';
        $rest = array_slice($tokens, 1);

        return match ($command) {
            'help', '--help', '-h', '' => $this->printUsage(0),
            'doctor' => (new InitDoctorCommand($this->rootPath))->run($rest),
            'status' => (new InitStatusCommand($this->rootPath))->run($rest),
            'validate' => (new InitValidateCommand($this->rootPath))->run($rest),
            'install-plan' => (new InitInstallPlanCommand())->run($rest),
            'sync-skills' => (new InitSyncSkillsCommand($this->rootPath))->run($rest),
            'sync-subagents' => (new InitSyncSubagentsCommand($this->rootPath))->run($rest),
            'sync-hooks' => (new InitSyncHooksCommand($this->rootPath))->run($rest),
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
          agent-loop init status [--config=PATH] [--skills-root=PATH] [--subagents-root=PATH] [--hooks-root=PATH] [--tools-root=PATH]
          agent-loop init validate --kind=<skills|subagents|hooks|all> [--agent=<agent>] [--config=PATH] [--skills-root=PATH]
          agent-loop init install-plan --profile=<profile> --agent=<agent>
          agent-loop init sync-skills --agent=<agent|all> [--config=PATH] [--skills-root=PATH] [--dry-run] [--force]
          agent-loop init sync-subagents --agent=<agent|all> [--config=PATH] [--subagents-root=PATH] [--dry-run] [--force]
          agent-loop init sync-hooks --agent=<agent> [--config=PATH] [--hooks-root=PATH] [--dry-run] [--force]
          agent-loop init scaffold [--dry-run]

        Commands:
          help           Show init help.
          doctor         Diagnose local setup and repo-managed agent asset hints.
          status         Show resolved init sources, aliases, and target manifests (read-only).
          validate       Validate repo-managed agent asset definitions.
          install-plan   Print reviewed setup commands for ripgrep, RTK, and Caveman. Does not execute them.
          sync-skills    Sync repo-managed skills into a client target directory.
          sync-subagents Sync repo-managed subagents into a client target directory.
          sync-hooks     Sync repo-managed Codex hooks into a client target directory.
          scaffold       Create the minimum local workflow structure and a DEMO-1 task.
        TXT;

        if ($unknownCommand === '') {
            echo $usage . "\n";
        } else {
            fwrite(\STDERR, $usage . "\n");
        }

        return $exitCode;
    }
}
