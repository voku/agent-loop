<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use JsonException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;

/**
 * Projects the residue that voku/agent-edit recorded in `verification-result.json` into structured host work.
 *
 * Loop does not scan or judge Markdown/template mentions: agent-edit owns that evidence. This only reads what
 * the owner already wrote and names the two ways to complete it, so a host needs no free-text parsing.
 */
final readonly class EditResidueWork
{
    private const MAX_REFERENCES = 50;

    /**
     * @return array{kind: string, summary: string, bundles: list<array<string, mixed>>}|null null when no edit bundle of the task has open residue
     */
    public static function forTask(string $rootPath, string $taskId): ?array
    {
        $bundles = [];
        $open = 0;
        foreach ((new ProjectLayout($rootPath))->editBundles($taskId) as $bundle) {
            $resultFile = $bundle . '/verification-result.json';
            $raw = is_file($resultFile) ? @file_get_contents($resultFile) : false;
            if (!is_string($raw)) {
                continue;
            }
            try {
                $verification = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            $residue = is_array($verification) && is_array($verification['residue'] ?? null) ? $verification['residue'] : null;
            if ($residue === null || ($residue['status'] ?? null) !== 'open' || ($verification['status'] ?? null) === 'passed') {
                continue;
            }

            $relative = PathResolver::relativeTo($rootPath, $bundle);
            $references = [];
            foreach (is_array($residue['references'] ?? null) ? $residue['references'] : [] as $reference) {
                if (!is_array($reference) || count($references) >= self::MAX_REFERENCES) {
                    continue;
                }
                $references[] = [
                    'path' => is_string($reference['path'] ?? null) ? $reference['path'] : '',
                    'line' => is_int($reference['line'] ?? null) ? $reference['line'] : 0,
                    'confidence' => is_string($reference['confidence'] ?? null) ? $reference['confidence'] : '',
                    'matched' => is_string($reference['matched'] ?? null) ? $reference['matched'] : '',
                    'start_file_pos' => is_int($reference['start_file_pos'] ?? null) ? $reference['start_file_pos'] : null,
                    'end_file_pos' => is_int($reference['end_file_pos'] ?? null) ? $reference['end_file_pos'] : null,
                ];
            }
            $count = is_int($residue['open'] ?? null) ? $residue['open'] : count($references);
            $open += $count;
            $bundles[] = [
                'bundle' => $relative,
                'verification_result' => $relative . '/verification-result.json',
                'open' => $count,
                'historical' => is_int($residue['historical'] ?? null) ? $residue['historical'] : 0,
                'truncated' => ($residue['truncated'] ?? false) === true,
                'references' => $references,
                'completion_paths' => [
                    [
                        'path' => 'fix_and_reverify',
                        'description' => 'Fix the listed mentions (confidence says how strong each match is), then re-run verify; it re-scans.',
                        'invocation' => ['executable' => 'agent-loop', 'arguments' => ['edit', 'refactor', 'verify', '--bundle=' . $relative], 'template' => false],
                    ],
                    [
                        'path' => 'accept_residue',
                        'description' => 'Accept the remaining mentions with a recorded reason; verify then reports passed and keeps the reason.',
                        'invocation' => ['executable' => 'agent-loop', 'arguments' => ['edit', 'refactor', 'verify', '--bundle=' . $relative, '--accept-residue=<reason>'], 'template' => true],
                    ],
                ],
            ];
        }

        if ($bundles === []) {
            return null;
        }

        return [
            'kind' => 'edit_residue',
            'summary' => $open . ' non-historical Markdown/template mention(s) of the old symbol remain after the verified PHP edit',
            'bundles' => $bundles,
        ];
    }
}
