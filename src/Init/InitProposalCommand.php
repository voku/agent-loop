<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Produces source-backed setup proposals without becoming a config writer.
 *
 * The coding host may review these candidates, but cannot treat a proposed
 * command or a semantic field diff as permission to execute or apply it.
 */
final readonly class InitProposalCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $language = null;
            $formatSeen = false;
            foreach ($tokens as $token) {
                if ($token === '--format=json' && !$formatSeen) {
                    $formatSeen = true;

                    continue;
                }

                if (str_starts_with($token, '--language=') && $language === null) {
                    $language = $this->normalizeLanguage(substr($token, strlen('--language=')));

                    continue;
                }

                throw new InvalidArgumentException('supported options are --format=json and --language=<BCP47> (each at most once).');
            }

            echo json_encode($this->inspect($language), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (InvalidArgumentException | RuntimeException | JsonException $exception) {
            fwrite(STDERR, '[FAIL] init plan: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @return array{
     *   schema_version: 1,
     *   state: 'proposal_only',
     *   requires_confirmation: true,
     *   apply_supported: false,
     *   candidates: list<array{id: string, owner: string, source: string, confidence: string, value: array<string, mixed>, requires_confirmation: true}>,
     *   changes: list<array{target: string, pointer: string, before: string, before_source: string, after: string, owner: string, source: string, requires_confirmation: true}>,
     *   warnings: list<string>
     * }
     */
    public function inspect(?string $requestedLanguage = null): array
    {
        $report = (new InitRecommendCommand($this->rootPath))->inspect();
        $candidates = [];

        if ($report['map']['paths'] !== [] && $report['map']['source'] !== null && $report['map']['command'] !== null) {
            $candidates[] = [
                'id' => 'map.paths',
                'owner' => 'voku/agent-map',
                'source' => $report['map']['source'],
                'confidence' => $report['map']['source'] === 'directory-presence' ? 'presence-only' : 'source-backed',
                'value' => [
                    'paths' => $report['map']['paths'],
                    'suggested_command' => $report['map']['command'],
                ],
                'requires_confirmation' => true,
            ];
        }

        if ($report['quality']['composer_scripts'] !== [] && $report['quality']['source'] !== null) {
            $candidates[] = [
                'id' => 'composer.quality-scripts',
                'owner' => 'composer',
                'source' => $report['quality']['source'],
                'confidence' => 'declaration-only',
                'value' => ['script_names' => $report['quality']['composer_scripts']],
                'requires_confirmation' => true,
            ];
        }

        $changes = $this->languageChanges(
            $requestedLanguage,
            $report['prompting']['language'],
            $report['prompting']['source'],
            $report['warnings'],
        );

        return [
            'schema_version' => 1,
            'state' => 'proposal_only',
            'requires_confirmation' => true,
            'apply_supported' => false,
            'candidates' => $candidates,
            'changes' => $changes,
            'warnings' => $report['warnings'],
        ];
    }

    /**
     * @param list<string> $warnings
     *
     * @return list<array{target: string, pointer: string, before: string, before_source: string, after: string, owner: string, source: string, requires_confirmation: true}>
     */
    private function languageChanges(
        ?string $requestedLanguage,
        string $currentLanguage,
        string $currentSource,
        array $warnings,
    ): array {
        if ($requestedLanguage === null) {
            return [];
        }

        $language = $this->normalizeLanguage($requestedLanguage);
        foreach ($warnings as $warning) {
            if (str_contains($warning, 'init config:')) {
                throw new RuntimeException('Cannot propose a language edit while the existing init config has validation warnings.');
            }
        }

        if ($language === $currentLanguage) {
            return [];
        }

        return [[
            'target' => '.agent-loop/init.json',
            'pointer' => '/prompting/language',
            'before' => $currentLanguage,
            'before_source' => $currentSource,
            'after' => $language,
            'owner' => 'agent-loop',
            'source' => 'explicit --language',
            'requires_confirmation' => true,
        ]];
    }

    private function normalizeLanguage(string $language): string
    {
        if (strlen($language) > 35 || preg_match('/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\z/', $language) !== 1) {
            throw new InvalidArgumentException('language must be a BCP 47 language tag accepted by InitConfigLoader.');
        }

        return strtolower($language);
    }
}
