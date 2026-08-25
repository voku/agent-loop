<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\ProjectLayout;

/**
 * CLI adapter over the typed repository-setup diagnostics.
 *
 * This command owns argument handling and rendering. Every decision it prints
 * is made by {@see RepositorySetupDiagnosticsInspector}, so a host consuming
 * the typed projection sees exactly what a human sees here.
 */
final readonly class InitDoctorCommand
{
    public function __construct(
        private string $rootPath,
        private ?HostRuntimeProbe $runtimeProbe = null,
    ) {
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

        $requestedConfig = OptionTokens::value($tokens, 'config');
        $layout = new ProjectLayout($this->rootPath);
        $canonicalConfig = $layout->configPath();
        $config = (new InitConfigLoader($this->rootPath))->load(
            $requestedConfig ?? (is_file($canonicalConfig) ? $layout->display($canonicalConfig) : null),
        );
        foreach ($config['warnings'] as $warning) {
            echo $warning . "\n";
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $this->readPathOverrides($tokens));

        echo "agent-loop init doctor\n\n";
        foreach ($this->diagnostics($paths)->diagnostics as $diagnostic) {
            echo $diagnostic->render() . "\n";
        }

        return 0;
    }

    /**
     * The decisions this command used to make inline now live in the owner's
     * typed setup domain, so `init doctor` and every typed host consumer can
     * never disagree about what the repository looks like.
     */
    private function diagnostics(AgentAssetSourcePaths $paths): RepositorySetupDiagnostics
    {
        return (new RepositorySetupDiagnosticsInspector($this->rootPath, $this->runtimeProbe))->inspect($paths);
    }

    /**
     * @param list<string> $tokens
     * @return array<string, string>
     */
    private function readPathOverrides(array $tokens): array
    {
        $overrides = [];
        foreach (['skills-root', 'subagents-root', 'hooks-root', 'tools-root'] as $option) {
            $value = OptionTokens::value($tokens, $option);
            if ($value !== null) {
                $overrides[$option] = $value;
            }
        }

        return $overrides;
    }

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['config', 'skills-root', 'subagents-root', 'hooks-root', 'tools-root'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === 'help' || $token === '--help' || $token === '-h') {
                continue;
            }

            if (!str_starts_with($token, '--')) {
                return 'Unknown init doctor argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init doctor option: --' . $normalized;
            }

            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init doctor option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }
}
