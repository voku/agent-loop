<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentLoop\HumanExplanationPolicy;
use voku\AgentLoop\PathResolver;

final readonly class InitConfigLoader
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array{
     *     warnings: list<string>,
     *     paths: array<string, string>,
     *     agents: array<string, array<string, string>>,
     *     recall: array{document_manifests: list<string>},
     *     interaction: array{human_explanations: 'ask'|'always'|'never'}
     * }
     */
    public function load(?string $configPath): array
    {
        $result = [
            'warnings' => [],
            'paths' => [],
            'agents' => [],
            'recall' => ['document_manifests' => []],
            'interaction' => ['human_explanations' => HumanExplanationPolicy::ASK->value],
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
            foreach ([
                'skills_root',
                'subagents_root',
                'codex_hooks_root',
                'tools_root',
                // Workflow state locations. state_root moves the whole tree at
                // once; the rest override one branch of it independently, which
                // is what a project that grew its own conventions actually needs.
                'state_root',
                'sessions_root',
                'learning_root',
                'recall_root',
            ] as $key) {
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

        $recall = $decoded['recall'] ?? null;
        if ($recall !== null && !is_array($recall)) {
            $result['warnings'][] = '[WARN] init config: recall must be an object';
        } elseif (is_array($recall) && array_key_exists('document_manifests', $recall)) {
            $manifests = $recall['document_manifests'];
            if (!is_array($manifests)) {
                $result['warnings'][] = '[WARN] init config: recall.document_manifests must be an array';
            } else {
                foreach ($manifests as $manifest) {
                    if (!is_string($manifest) || trim($manifest) === '') {
                        $result['warnings'][] = '[WARN] init config: recall.document_manifests must contain only non-empty strings';

                        continue;
                    }

                    $manifest = str_replace('\\', '/', trim($manifest));
                    if (PathResolver::isAbsolute($manifest) || in_array('..', explode('/', $manifest), true)) {
                        $result['warnings'][] = '[WARN] init config: recall document manifest must stay inside the project: ' . $manifest;

                        continue;
                    }

                    $result['recall']['document_manifests'][] = $manifest;
                }
                $result['recall']['document_manifests'] = array_values(array_unique($result['recall']['document_manifests']));
                sort($result['recall']['document_manifests'], SORT_STRING);
            }
        }

        $interaction = $decoded['interaction'] ?? null;
        if ($interaction !== null && !is_array($interaction)) {
            $result['warnings'][] = '[WARN] init config: interaction must be an object';
        } elseif (is_array($interaction) && array_key_exists('human_explanations', $interaction)) {
            $configured = $interaction['human_explanations'];
            $policy = is_string($configured)
                ? HumanExplanationPolicy::tryFrom(strtolower(trim($configured)))
                : null;
            if ($policy === null) {
                $result['warnings'][] = '[WARN] init config: interaction.human_explanations must be ask, always, or never';
            } else {
                $result['interaction']['human_explanations'] = $policy->value;
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
