<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use stdClass;
use voku\AgentLoop\FutureWorkMode;
use voku\AgentLoop\HumanExplanationPolicy;
use voku\AgentLoop\PathResolver;

final readonly class InitConfigLoader
{
    private const int MAX_FOLLOW_UP_SLICES = 10;

    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array{
     *     warnings: list<string>,
     *     paths: array<string, string>,
     *     agents: array<string, array<string, string>>,
     *     package_skills: bool,
     *     package_subagents: bool,
     *     recall: array{document_manifests: list<string>},
     *     interaction: array{human_explanations: 'ask'|'always'|'never'},
     *     workflow: array{future_work: array{mode: 'focus'|'discover'|'invest', max_follow_up_slices: int}}
     * }
     */
    public function load(?string $configPath): array
    {
        $result = [
            'warnings' => [],
            'paths' => [],
            'agents' => [],
            'package_skills' => true,
            'package_subagents' => true,
            'recall' => ['document_manifests' => []],
            'interaction' => ['human_explanations' => HumanExplanationPolicy::ASK->value],
            'workflow' => [
                'future_work' => [
                    'mode' => FutureWorkMode::FOCUS->value,
                    'max_follow_up_slices' => 1,
                ],
            ],
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
                'claude_hooks_root',
                'tools_root',
                // Workflow state locations. state_root moves the whole tree at
                // once; the rest override one branch of it independently, which
                // is what a project that grew its own conventions actually needs.
                'state_root',
                'board_root',
                'sessions_root',
                'learning_root',
                'recall_root',
                'map_root',
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

        if (array_key_exists('package_skills', $decoded)) {
            if (!is_bool($decoded['package_skills'])) {
                $result['warnings'][] = '[WARN] init config: package_skills must be a boolean';
            } else {
                $result['package_skills'] = $decoded['package_skills'];
            }
        }

        if (array_key_exists('package_subagents', $decoded)) {
            if (!is_bool($decoded['package_subagents'])) {
                $result['warnings'][] = '[WARN] init config: package_subagents must be a boolean';
            } else {
                $result['package_subagents'] = $decoded['package_subagents'];
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

        $decodedShape = json_decode($content);
        $hasInteraction = $decodedShape instanceof stdClass && property_exists($decodedShape, 'interaction');
        $interactionShape = $hasInteraction ? $decodedShape->interaction : null;
        $interaction = $decoded['interaction'] ?? null;
        if ($hasInteraction && !$interactionShape instanceof stdClass) {
            $result['warnings'][] = '[WARN] init config: interaction must be an object';
        } elseif ($interactionShape instanceof stdClass && is_array($interaction) && array_key_exists('human_explanations', $interaction)) {
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

        $hasWorkflow = $decodedShape instanceof stdClass && property_exists($decodedShape, 'workflow');
        $workflowShape = $hasWorkflow ? $decodedShape->workflow : null;
        $workflow = $decoded['workflow'] ?? null;
        if ($hasWorkflow && !$workflowShape instanceof stdClass) {
            $result['warnings'][] = '[WARN] init config: workflow must be an object';
        } elseif ($workflowShape instanceof stdClass && is_array($workflow) && property_exists($workflowShape, 'future_work')) {
            $futureWorkShape = $workflowShape->future_work;
            $futureWork = $workflow['future_work'] ?? null;
            if (!$futureWorkShape instanceof stdClass || !is_array($futureWork)) {
                $result['warnings'][] = '[WARN] init config: workflow.future_work must be an object';
            } else {
                if (array_key_exists('mode', $futureWork)) {
                    $configuredMode = $futureWork['mode'];
                    $mode = is_string($configuredMode)
                        ? FutureWorkMode::tryFrom(strtolower(trim($configuredMode)))
                        : null;
                    if ($mode === null) {
                        $result['warnings'][] = '[WARN] init config: workflow.future_work.mode must be focus, discover, or invest';
                    } else {
                        $result['workflow']['future_work']['mode'] = $mode->value;
                    }
                }

                if (array_key_exists('max_follow_up_slices', $futureWork)) {
                    $maximum = $futureWork['max_follow_up_slices'];
                    if (!is_int($maximum) || $maximum < 1 || $maximum > self::MAX_FOLLOW_UP_SLICES) {
                        $result['warnings'][] = '[WARN] init config: workflow.future_work.max_follow_up_slices must be an integer from 1 to ' . self::MAX_FOLLOW_UP_SLICES;
                    } else {
                        $result['workflow']['future_work']['max_follow_up_slices'] = $maximum;
                    }
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
