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
        if (!in_array($profile, ['wsl2', 'linux', 'windows', 'powershell'], true)) {
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
        {$this->renderSetupBlock($profile)}

        ripgrep (rg):
        {$this->renderRipgrepBlock($profile)}

        ripgrep gives agents a fast repo search primitive.
        Verify with `rg --version`.

        Caveman:
        {$this->renderCavemanBlock($profile)}

        Caveman reduces agent reply verbosity/output tokens.
        It does not reduce model reasoning/thinking tokens.

        RTK:
        {$this->renderRtkBlock($profile)}

        RTK reduces noisy terminal/tool output before the agent reads it.
        Verify with `rtk gain`.

        {$this->renderAgentBlock($agent, $profile)}

        {$this->renderBoundaryWarning($profile)}
        TXT;
    }

    private function renderSetupHeading(string $profile): string
    {
        return match ($profile) {
            'linux' => 'Native Linux setup:',
            'windows', 'powershell' => 'Windows PowerShell setup:',
            default => 'Shared WSL2 setup:',
        };
    }

    private function renderSetupBlock(string $profile): string
    {
        if (in_array($profile, ['windows', 'powershell'], true)) {
            return <<<'TXT'
            ```powershell
            # Verify Node.js (v18+) is installed
            node -v

            # --- OPTION A: If you HAVE admin rights ---
            # winget install OpenJS.NodeJS

            # --- OPTION B: If you DO NOT have admin rights (portable installation) ---
            # $portableNodeDir = "$HOME\.local\node-portable"
            # Invoke-WebRequest -Uri "https://nodejs.org/dist/v20.11.1/node-v20.11.1-win-x64.zip" -OutFile "$env:TEMP\node.zip"
            # Expand-Archive -Path "$env:TEMP\node.zip" -DestinationPath "$env:TEMP\node-extracted" -Force
            # New-Item -ItemType Directory -Path "$HOME\.local" -Force
            # Copy-Item -Path "$env:TEMP\node-extracted\node-v20.11.1-win-x64" -Destination $portableNodeDir -Recurse -Force
            # [Environment]::SetEnvironmentVariable("PATH", "$portableNodeDir;" + [Environment]::GetEnvironmentVariable("PATH", "User"), "User")
            ```
            TXT;
        }

        return <<<TXT
        ```bash
        set -euo pipefail

        sudo apt update
        sudo apt install -y curl git ca-certificates build-essential

        if ! command -v node >/dev/null 2>&1 || ! node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 18 ? 0 : 1)' ; then
          curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
          sudo apt install -y nodejs
        fi
        ```
        TXT;
    }

    private function renderCavemanBlock(string $profile): string
    {
        if (in_array($profile, ['windows', 'powershell'], true)) {
            return <<<'TXT'
            ```powershell
            # Install Caveman globally via npm (works in user-space if Node is portable)
            npm install -g @juliusbrussee/caveman
            ```
            TXT;
        }

        return <<<TXT
        ```bash
        curl -fsSL https://raw.githubusercontent.com/JuliusBrussee/caveman/main/install.sh -o /tmp/caveman-install.sh
        less /tmp/caveman-install.sh
        bash /tmp/caveman-install.sh
        ```
        TXT;
    }

    private function renderRipgrepBlock(string $profile): string
    {
        if (in_array($profile, ['windows', 'powershell'], true)) {
            return <<<'TXT'
            ```powershell
            # Install ripgrep via winget
            winget install BurntSushi.ripgrep.MSVC
            rg --version
            ```
            TXT;
        }

        return <<<TXT
        ```bash
        sudo apt install -y ripgrep
        rg --version
        ```
        TXT;
    }

    private function renderRtkBlock(string $profile): string
    {
        if (in_array($profile, ['windows', 'powershell'], true)) {
            return <<<'TXT'
            ```powershell
            # Install RTK to user local bin directory (requires no admin rights)
            $localBin = "$HOME\.local\bin"
            New-Item -ItemType Directory -Path $localBin -Force
            Invoke-WebRequest -Uri "https://github.com/rtk-ai/rtk/releases/download/v0.43.0/rtk-x86_64-pc-windows-msvc.zip" -OutFile "$env:TEMP\rtk.zip"
            Expand-Archive -Path "$env:TEMP\rtk.zip" -DestinationPath "$env:TEMP\rtk-extracted" -Force
            Copy-Item -Path "$env:TEMP\rtk-extracted\rtk.exe" -Destination "$localBin\rtk.exe" -Force
            [Environment]::SetEnvironmentVariable("PATH", "$localBin;" + [Environment]::GetEnvironmentVariable("PATH", "User"), "User")
            ```
            TXT;
        }

        return <<<TXT
        ```bash
        curl -fsSL https://raw.githubusercontent.com/rtk-ai/rtk/master/install.sh | sh

        grep -qxF 'export PATH="\$HOME/.local/bin:\$PATH"' "\$HOME/.bashrc" \
          || echo 'export PATH="\$HOME/.local/bin:\$PATH"' >> "\$HOME/.bashrc"

        export PATH="\$HOME/.local/bin:\$PATH"

        rtk --version
        rtk gain
        ```
        TXT;
    }

    private function renderAgentBlock(string $agent, string $profile = 'wsl2'): string
    {
        $isWindows = in_array($profile, ['windows', 'powershell'], true);
        $environmentLabel = $isWindows ? 'Windows' : ($profile === 'linux' ? 'Linux' : 'WSL2');
        $codeFence = $isWindows ? '```powershell' : '```bash';

        return match ($agent) {
            'codex' => <<<TXT
            Codex hook setup:
            {$codeFence}
            rtk init -g --codex
            rtk init --show
            ```

            Codex: restart the agent inside {$environmentLabel} after enabling the hook.
            If Caveman is installed for Codex through skills, start each session with:

            /caveman full
            TXT,
            'claude' => <<<TXT
            Claude hook setup:
            {$codeFence}
            rtk init -g
            rtk init --show
            ```

            Claude Code: restart Claude inside {$environmentLabel} after enabling the hook.
            Use Caveman with:

            /caveman full
            TXT,
            default => <<<TXT
            Antigravity hook setup:
            {$codeFence}
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
        if (in_array($profile, ['windows', 'powershell'], true)) {
            return <<<'TXT'
            Important Windows boundary:

            Install this inside the Windows environment (PowerShell/CMD) where the coding agent executes commands.

            This affects paths such as:

              C:\Users\<you>\.claude
              C:\Users\<you>\AppData\...

            It does not automatically affect WSL2 paths such as:

              /home/<you>/.claude
              /home/<you>/.bashrc

            If your agent runs in WSL2 but you install in Windows, the hook will not apply there.
            TXT;
        }

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
