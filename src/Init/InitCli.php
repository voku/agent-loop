<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

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
            'validate' => (new InitValidateCommand($this->rootPath))->run($rest),
            'install-plan' => (new InitInstallPlanCommand())->run($rest),
            'sync-skills' => $this->runReservedSyncSkills($rest),
            'sync-subagents' => $this->runReservedSyncSubagents($rest),
            'sync-hooks' => $this->runReservedSyncHooks($rest),
            'scaffold' => $this->runReservedScaffold($rest),
            default => $this->printUsage(1, $command),
        };
    }

    /**
     * @param list<string> $tokens
     */
    private function runReservedSyncSkills(array $tokens): int
    {
        return $this->runReservedAgentCommand(
            $tokens,
            'sync-skills',
            ['codex', 'claude', 'copilot', 'antigravity'],
            true
        );
    }

    /**
     * @param list<string> $tokens
     */
    private function runReservedSyncSubagents(array $tokens): int
    {
        return $this->runReservedAgentCommand(
            $tokens,
            'sync-subagents',
            ['copilot', 'antigravity'],
            true
        );
    }

    /**
     * @param list<string> $tokens
     */
    private function runReservedSyncHooks(array $tokens): int
    {
        return $this->runReservedAgentCommand(
            $tokens,
            'sync-hooks',
            ['codex'],
            false
        );
    }

    /**
     * @param list<string> $tokens
     */
    private function runReservedScaffold(array $tokens): int
    {
        $argumentError = $this->validateOptions($tokens, ['profile', 'agent'], ['dry-run']);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $profile = $this->readOptionValue($tokens, 'profile');
        if ($profile !== 'wsl2') {
            fwrite(\STDERR, "Unknown profile: " . ($profile ?? '') . "\n");

            return 1;
        }

        $agentValue = $this->readOptionValue($tokens, 'agent');
        if ($agentValue === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse($agentValue, ['codex', 'claude', 'antigravity']);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        echo "init scaffold is not implemented yet\n";

        return 1;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $allowedCanonicalAgents
     */
    private function runReservedAgentCommand(
        array $tokens,
        string $commandName,
        array $allowedCanonicalAgents,
        bool $allowAll,
    ): int {
        $argumentError = $this->validateOptions($tokens, ['agent'], ['dry-run', 'force']);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $agentValue = $this->readOptionValue($tokens, 'agent');
        if ($agentValue === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse($agentValue, $allowedCanonicalAgents, $allowAll);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        echo 'init ' . $commandName . " is not implemented yet\n";

        return 1;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $valueOptions
     * @param list<string> $flagOptions
     */
    private function validateOptions(array $tokens, array $valueOptions, array $flagOptions = []): ?string
    {
        $allowed = array_merge($valueOptions, $flagOptions);
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, $allowed, true)) {
                return 'Unknown init option: --' . $normalized;
            }

            if (in_array($normalized, $valueOptions, true) && !str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     */
    private function readOptionValue(array $tokens, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($tokens as $index => $token) {
            if (str_starts_with($token, $prefix)) {
                $value = substr($token, strlen($prefix));

                return $value === '' ? null : $value;
            }

            if ($token === '--' . $name) {
                $candidate = $tokens[$index + 1] ?? null;
                if (is_string($candidate) && !str_starts_with($candidate, '--')) {
                    return $candidate;
                }
            }
        }

        return null;
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
          agent-loop init validate --kind=<skills|subagents|hooks|all> [--agent=<agent>] [--config=PATH] [--skills-root=PATH]
          agent-loop init install-plan --profile=<profile> --agent=<agent>
          agent-loop init sync-skills --agent=<agent|all> [--dry-run] [--force]
          agent-loop init sync-subagents --agent=<agent|all> [--dry-run] [--force]
          agent-loop init sync-hooks --agent=<agent> [--dry-run] [--force]
          agent-loop init scaffold --profile=<profile> --agent=<agent> [--dry-run]

        Commands:
          help           Show init help.
          doctor         Diagnose local setup and repo-managed agent asset hints.
          validate       Validate repo-managed agent asset definitions.
          install-plan   Print reviewed setup commands for Linux/WSL2 agent tooling. Does not execute them.
          sync-skills    Reserved for syncing repo-managed skills.
          sync-subagents Reserved for syncing repo-managed subagents.
          sync-hooks     Reserved for syncing repo-managed hooks.
          scaffold       Reserved for repo-local scaffolding.
        TXT;

        if ($unknownCommand === '') {
            echo $usage . "\n";
        } else {
            fwrite(\STDERR, $usage . "\n");
        }

        return $exitCode;
    }
}
