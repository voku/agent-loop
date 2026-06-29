<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitInstallPlanCommand
{
    public function __construct()
    {
    }

    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $profile = $this->readOptionValue($tokens, 'profile');
        if (!in_array($profile, ['wsl2', 'linux'], true)) {
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

        echo $this->renderPlan($profile, $agent->canonicalName());

        return 0;
    }

    private function renderPlan(string $profile, string $agent): string
    {
        return <<<TXT
        agent-loop init install-plan

        Profile: {$profile}
        Agent: {$agent}

        This command prints a setup plan only.
        It does not install tools.
        It does not modify ~/.bashrc, ~/.zshrc, ~/.local/bin, ~/.claude, .codex, or agent settings.
        Review the commands before running them manually.

        {$this->renderSetupHeading($profile)}
        ```bash
        set -euo pipefail

        sudo apt update
        sudo apt install -y curl git ca-certificates build-essential

        if ! command -v node >/dev/null 2>&1 || ! node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 18 ? 0 : 1)' ; then
          curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
          sudo apt install -y nodejs
        fi
        ```

        Caveman:
        ```bash
        curl -fsSL https://raw.githubusercontent.com/JuliusBrussee/caveman/main/install.sh -o /tmp/caveman-install.sh
        less /tmp/caveman-install.sh
        bash /tmp/caveman-install.sh
        ```

        Caveman reduces agent reply verbosity/output tokens.
        It does not reduce model reasoning/thinking tokens.

        RTK:
        ```bash
        curl -fsSL https://raw.githubusercontent.com/rtk-ai/rtk/master/install.sh | sh

        grep -qxF 'export PATH="\$HOME/.local/bin:\$PATH"' "\$HOME/.bashrc" \
          || echo 'export PATH="\$HOME/.local/bin:\$PATH"' >> "\$HOME/.bashrc"

        export PATH="\$HOME/.local/bin:\$PATH"

        rtk --version
        rtk gain
        ```

        RTK reduces noisy terminal/tool output before the agent reads it.
        Verify with `rtk gain`.

        {$this->renderAgentBlock($agent, $profile)}

        {$this->renderBoundaryWarning($profile)}
        TXT;
    }

    private function renderSetupHeading(string $profile): string
    {
        return $profile === 'linux' ? 'Native Linux setup:' : 'Shared WSL2 setup:';
    }

    private function renderAgentBlock(string $agent, string $profile = 'wsl2'): string
    {
        $environmentLabel = $profile === 'linux' ? 'Linux' : 'WSL2';

        return match ($agent) {
            'codex' => <<<TXT
            Codex hook setup:
            ```bash
            rtk init -g --codex
            rtk init --show
            ```

            Codex: restart the agent inside {$environmentLabel} after enabling the hook.
            If Caveman is installed for Codex through skills, start each session with:

            /caveman full
            TXT,
            'claude' => <<<TXT
            Claude hook setup:
            ```bash
            rtk init -g
            rtk init --show
            ```

            Claude Code: restart Claude inside {$environmentLabel} after enabling the hook.
            Use Caveman with:

            /caveman full
            TXT,
            default => <<<TXT
            Antigravity hook setup:
            ```bash
            rtk init -g --gemini
            rtk init --show
            ```

            Antigravity / Google agent tooling: restart the agent inside {$environmentLabel} after enabling the hook.
            If this repository still uses Gemini CLI compatibility, verify the exact hook command against the current Google docs before running it.
            TXT,
        };
    }

    private function renderBoundaryWarning(string $profile): string
    {
        if ($profile === 'linux') {
            return <<<'TXT'
            Important native Linux boundary:

            Install this inside the same Linux environment where the coding agent executes commands.

            This affects paths such as:

              /home/<you>/.local/bin
              /home/<you>/.bashrc
              /home/<you>/.claude

            If the agent runs under a different Linux user, shell profile, container, or remote host than the one where you install these tools, the hook will not apply there automatically.
            TXT;
        }

        return <<<'TXT'
        Important WSL2 boundary:

        Install this inside the same WSL2 environment where the coding agent executes commands.

        This affects paths such as:

          /home/<you>/.local/bin
          /home/<you>/.bashrc
          /home/<you>/.claude

        It does not automatically affect Windows paths such as:

          C:\Users\<you>\.claude
          C:\Users\<you>\AppData\...

        If your agent runs in PowerShell but you install in WSL2, the hook will not apply there.
        TXT;
    }

    /**
     * @param list<string> $tokens
     */
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
            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init install-plan option: --' . $normalized;
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
}
