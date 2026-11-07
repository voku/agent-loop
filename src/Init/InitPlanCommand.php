<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use JsonException;
use RuntimeException;

/**
 * Reviewable setup candidates derived only from bounded read-only evidence.
 *
 * A candidate is not an approved command, and this command never writes files.
 */
final readonly class InitPlanCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens !== [] && $tokens !== ['--format=json']) {
            fwrite(\STDERR, "[FAIL] init plan: supported option is --format=json\n");

            return 1;
        }

        try {
            echo json_encode($this->inspect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (JsonException | RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] init plan: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @return array{
     *   schema_version: 1,
     *   mode: 'read_only',
     *   evidence_sha256: string,
     *   proposals: list<array{
     *     id: string,
     *     kind: 'map_command'|'composer_script',
     *     owner: 'agent-map'|'composer',
     *     source: string|null,
     *     confidence: 'literal_config'|'declared_config'|'directory_presence'|'unverified_script_name',
     *     current: null,
     *     proposed: string,
     *     requires_confirmation: true,
     *     execution: 'not_authorized',
     *     prerequisites: list<string>
     *   }>,
     *   file_changes: list<array{path: string, before: string|null, after: string|null}>,
     *   review: array{required: true, applied: false, reason: string},
     *   warnings: list<string>
     * }
     */
    public function inspect(): array
    {
        $evidence = (new InitRecommendCommand($this->rootPath))->inspect();
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $proposals = [];
        $command = $evidence['map']['command'];
        if ($command !== null) {
            $source = $evidence['map']['source'];
            $confidence = match ($source) {
                'directory-presence' => 'directory_presence',
                'composer.json' => 'declared_config',
                default => 'literal_config',
            };

            $proposals[] = [
                'id' => 'map.scope',
                'kind' => 'map_command',
                'owner' => 'agent-map',
                'source' => $source,
                'confidence' => $confidence,
                'current' => null,
                'proposed' => $command,
                'requires_confirmation' => true,
                'execution' => 'not_authorized',
                'prerequisites' => [
                    'Confirm the proposed repository scope and index cost.',
                    'Run the command only after explicit approval.',
                ],
            ];
        }

        array_push($proposals, ...$this->composerProposals(
            $evidence['quality']['composer_scripts'],
            $evidence['quality']['source'],
        ));

        return [
            'schema_version' => 1,
            'mode' => 'read_only',
            'evidence_sha256' => hash('sha256', $encoded),
            'proposals' => $proposals,
            'file_changes' => [],
            'review' => [
                'required' => true,
                'applied' => false,
                'reason' => 'No configuration diff or command execution is authorized by setup evidence. Existing project-owned values, exclusions, and the three-round Attention safeguard are unchanged.',
            ],
            'warnings' => $evidence['warnings'],
        ];
    }

    /**
     * @param list<string> $scriptNames
     * @return list<array{
     *   id: string,
     *   kind: 'composer_script',
     *   owner: 'composer',
     *   source: string|null,
     *   confidence: 'unverified_script_name',
     *   current: null,
     *   proposed: string,
     *   requires_confirmation: true,
     *   execution: 'not_authorized',
     *   prerequisites: list<string>
     * }>
     */
    private function composerProposals(array $scriptNames, ?string $source): array
    {
        $proposals = [];

        foreach ($scriptNames as $scriptName) {
            $proposals[] = [
                'id' => 'composer.script.' . $scriptName,
                'kind' => 'composer_script',
                'owner' => 'composer',
                'source' => $source,
                'confidence' => 'unverified_script_name',
                'current' => null,
                'proposed' => $scriptName,
                'requires_confirmation' => true,
                'execution' => 'not_authorized',
                'prerequisites' => [
                    'Inspect the complete Composer script body and CI dependencies.',
                    'Authorize the exact execution command separately.',
                ],
            ];
        }

        return $proposals;
    }
}
