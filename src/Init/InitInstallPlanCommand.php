<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitInstallPlanCommand
{
    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $profile = $this->readOptionValue($tokens, 'profile');
        if (!in_array($profile, ['wsl2', 'linux', 'windows', 'powershell'], true)) {
            fwrite(\STDERR, 'Unknown profile: ' . ($profile ?? '') . "\n");

            return 1;
        }

        $requestedAgent = $this->readOptionValue($tokens, 'agent');
        if ($requestedAgent === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse($requestedAgent, ['codex', 'claude', 'copilot', 'antigravity']);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        echo $this->renderPlan($profile, $agent->canonicalName());

        return 0;
    }

    private function renderPlan(string $profile, string $agent): string
    {
        $command = 'php vendor/bin/agent-loop init install-assets --agent=' . $agent;

        return <<<TXT
        agent-loop init install-plan

        Profile: {$profile}
        Agent: {$agent}

        This command prints a setup plan only. It writes nothing.

        Optional system tool:
        {$this->renderRipgrepBlock($profile)}

        First-party agent assets:

        ```text
        {$command} --dry-run
        {$command}
        php vendor/bin/agent-loop init doctor
        ```

        `install-assets` copies skills shipped inside the installed `voku/agent-loop` package.
        Codex also receives repository-local PHP hooks. It does not clone a repository,
        execute a remote installer, use a plugin marketplace, or require Node.js.

        {$this->renderActivation($agent, $profile)}
        TXT;
    }

    private function renderRipgrepBlock(string $profile): string
    {
        $shell = in_array($profile, ['windows', 'powershell'], true) ? 'powershell' : 'bash';

        return <<<TXT
        ```{$shell}
        rg --version
        ```

        If `rg` is missing, install ripgrep through your reviewed operating-system
        package source. `agent-loop` does not fetch or execute an installer.
        TXT;
    }

    private function renderActivation(string $agent, string $profile): string
    {
        $environment = in_array($profile, ['windows', 'powershell'], true)
            ? 'Windows'
            : ($profile === 'linux' ? 'Linux' : 'WSL2');

        return match ($agent) {
            'codex' => <<<TXT
            Restart Codex inside {$environment}, open `/hooks`, inspect the installed
            repository-local hooks, and trust them only after review.
            TXT,
            'copilot' => <<<TXT
            Restart or reload skills in Copilot inside {$environment}.
            TXT,
            'claude' => <<<TXT
            Restart Claude Code inside {$environment} so it reloads repository skills.
            TXT,
            default => <<<TXT
            Restart Antigravity inside {$environment} so it reloads repository skills.
            TXT,
        };
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['profile', 'agent'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init install-plan argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!is_string($normalized) || !in_array($normalized, $valueOptions, true)) {
                return 'Unknown init install-plan option: --' . (is_string($normalized) ? $normalized : '');
            }

            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init install-plan option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }

    /** @param list<string> $tokens */
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
}
