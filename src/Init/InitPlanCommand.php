<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
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
        try {
            $language = $this->requestedLanguage($tokens);
            echo json_encode($this->inspect($language), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (InvalidArgumentException | JsonException | RuntimeException $exception) {
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
     *   review_diffs: list<array{owner: 'agent-loop', target: '.agent-loop/init.json', pointer: '/prompting/language', before: string|null, before_source: string, after: string, requires_confirmation: true, execution: 'not_authorized'}>,
     *   review: array{required: true, applied: false, reason: string},
     *   warnings: list<string>
     * }
     */
    public function inspect(?string $requestedLanguage = null): array
    {
        $evidence = (new InitRecommendCommand($this->rootPath))->inspect();
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $proposals = [];
        $mapProposal = $this->mapProposal($evidence['map']['command'], $evidence['map']['source']);
        if ($mapProposal !== null) {
            $proposals[] = $mapProposal;
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
            'review_diffs' => $this->languageDiff(
                $requestedLanguage,
                $evidence['prompting']['language'],
                $evidence['prompting']['source'],
                $evidence['warnings'],
            ),
            'review' => [
                'required' => true,
                'applied' => false,
                'reason' => 'No configuration diff or command execution is authorized by setup evidence. Existing project-owned values, exclusions, and the three-round Attention safeguard are unchanged.',
            ],
            'warnings' => $evidence['warnings'],
        ];
    }

    /**
     * @return array{
     *   id: 'map.scope',
     *   kind: 'map_command',
     *   owner: 'agent-map',
     *   source: string|null,
     *   confidence: 'literal_config'|'declared_config'|'directory_presence',
     *   current: null,
     *   proposed: string,
     *   requires_confirmation: true,
     *   execution: 'not_authorized',
     *   prerequisites: list<string>
     * }|null
     */
    private function mapProposal(?string $command, ?string $source): ?array
    {
        if ($command === null) {
            return null;
        }

        $confidence = match ($source) {
            'directory-presence' => 'directory_presence',
            'composer.json' => 'declared_config',
            default => 'literal_config',
        };

        return [
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

    /**
     * @param list<string> $tokens
     */
    private function requestedLanguage(array $tokens): ?string
    {
        $language = null;
        $seenFormat = false;

        foreach ($tokens as $token) {
            if ($token === '--format=json' && !$seenFormat) {
                $seenFormat = true;

                continue;
            }

            if (str_starts_with($token, '--language=') && $language === null) {
                $language = $this->normalizeLanguage(substr($token, strlen('--language=')));

                continue;
            }

            throw new InvalidArgumentException('supported options are --format=json and --language=<BCP47>, each at most once.');
        }

        return $language;
    }

    /**
     * @param list<string> $warnings
     *
     * @return list<array{owner: 'agent-loop', target: '.agent-loop/init.json', pointer: '/prompting/language', before: string|null, before_source: string, after: string, requires_confirmation: true, execution: 'not_authorized'}>
     */
    private function languageDiff(?string $requestedLanguage, string $currentLanguage, string $source, array $warnings): array
    {
        if ($requestedLanguage === null) {
            return [];
        }

        $language = $this->normalizeLanguage($requestedLanguage);
        foreach ($warnings as $warning) {
            if (str_contains($warning, 'init config:')) {
                throw new RuntimeException('Cannot propose a language change while init config validation has warnings.');
            }
        }

        if ($language === $currentLanguage) {
            return [];
        }

        return [[
            'owner' => 'agent-loop',
            'target' => '.agent-loop/init.json',
            'pointer' => '/prompting/language',
            'before' => $source === 'default' ? null : $currentLanguage,
            'before_source' => $source,
            'after' => $language,
            'requires_confirmation' => true,
            'execution' => 'not_authorized',
        ]];
    }

    private function normalizeLanguage(string $language): string
    {
        if (strlen($language) > 35 || preg_match('/\\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\\z/', $language) !== 1) {
            throw new InvalidArgumentException('language must be a BCP 47 language tag accepted by init config.');
        }

        return strtolower($language);
    }
}
