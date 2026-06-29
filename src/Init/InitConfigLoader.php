<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class InitConfigLoader
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array{
     *     warnings: list<string>,
     *     paths: array<string, string>,
     *     agents: array<string, array<string, string>>
     * }
     */
    public function load(?string $configPath): array
    {
        $result = [
            'warnings' => [],
            'paths' => [],
            'agents' => [],
        ];

        if ($configPath === null || trim($configPath) === '') {
            return $result;
        }

        $absoluteConfigPath = $this->resolvePath($configPath);
        if (!is_file($absoluteConfigPath)) {
            return $result;
        }

        $content = file_get_contents($absoluteConfigPath);
        if (!is_string($content)) {
            $result['warnings'][] = '[WARN] init config: invalid JSON';

            return $result;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $result['warnings'][] = '[WARN] init config: invalid JSON';

            return $result;
        }

        $paths = $decoded['paths'] ?? null;
        if (is_array($paths)) {
            foreach (['skills_root', 'subagents_root', 'codex_hooks_root', 'tools_root'] as $key) {
                $value = $paths[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $result['paths'][$key] = $value;
                }
            }
        }

        $agents = $decoded['agents'] ?? null;
        if (is_array($agents)) {
            foreach ($agents as $agentName => $agentConfig) {
                if (!is_string($agentName) || !is_array($agentConfig)) {
                    continue;
                }

                $normalizedAgentConfig = [];
                foreach (['status', 'maps_to'] as $key) {
                    $value = $agentConfig[$key] ?? null;
                    if (is_string($value) && $value !== '') {
                        $normalizedAgentConfig[$key] = $value;
                    }
                }

                if ($normalizedAgentConfig !== []) {
                    $result['agents'][strtolower($agentName)] = $normalizedAgentConfig;
                }
            }
        }

        return $result;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->rootPath, '/') . '/' . ltrim($path, '/');
    }
}
