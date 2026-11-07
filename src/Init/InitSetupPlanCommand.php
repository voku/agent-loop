<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Typed, read-only setup proposals derived from verified project evidence.
 *
 * A proposal is never authorization. No project configuration is rewritten,
 * no script is executed, and tool-specific exclusion languages stay isolated.
 */
final readonly class InitSetupPlanCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $language = $this->parseLanguage($tokens);
            echo json_encode($this->inspect($language), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (InvalidArgumentException | JsonException | RuntimeException $exception) {
            fwrite(STDERR, '[FAIL] init plan: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @return array{
     *   schema_version: int,
     *   evidence_schema_version: int,
     *   candidates: list<array<string, mixed>>,
     *   preserved: list<array<string, mixed>>,
     *   quality_script_names: list<string>,
     *   warnings: list<string>,
     *   authorization: array{writes: false, executes_project_code: false, apply_supported: false}
     * }
     */
    public function inspect(?string $requestedLanguage = null): array
    {
        $language = $requestedLanguage === null ? null : $this->validatedLanguage($requestedLanguage);
        $evidence = (new InitRecommendCommand($this->rootPath))->inspect();
        $candidates = [];

        if ($evidence['map']['command'] !== null) {
            $candidates[] = [
                'id' => 'map.build',
                'kind' => 'command',
                'owner' => 'agent-map',
                'source' => $evidence['map']['source'],
                'confidence' => $evidence['map']['source'] === 'directory-presence' ? 'heuristic' : 'source-backed',
                'target' => 'agent-map index',
                'before' => 'unknown',
                'after' => $evidence['map']['command'],
                'diff' => ['operation' => 'run-after-approval', 'paths' => $evidence['map']['paths']],
                'requires_confirmation' => true,
            ];
        }

        $currentLanguage = $evidence['prompting']['language'];
        if ($language !== null && $language !== $currentLanguage) {
            $explicitSetting = $evidence['prompting']['source'] !== 'default';
            $candidates[] = [
                'id' => 'prompting.language',
                'kind' => 'json-member',
                'owner' => 'agent-loop',
                'source' => 'explicit-cli-request',
                'confidence' => 'user-specified',
                'target' => '.agent-loop/init.json',
                'before' => $explicitSetting ? $currentLanguage : null,
                'after' => $language,
                'diff' => [
                    'operation' => $explicitSetting ? 'replace' : 'add',
                    'pointer' => '/prompting/language',
                    'value' => $language,
                ],
                'requires_confirmation' => true,
            ];
        }

        return [
            'schema_version' => 1,
            'evidence_schema_version' => $evidence['schema_version'],
            'candidates' => $candidates,
            'preserved' => [
                [
                    'owner' => 'slop-scan',
                    'target' => $evidence['sources']['slop'],
                    'values' => $evidence['exclusions']['slop'],
                    'reason' => 'Keep slop glob policy unchanged; PHPStan exclusions are not slop globs.',
                ],
                [
                    'owner' => 'agent-loop',
                    'target' => '.agent-loop/init.json',
                    'field' => 'review.human_attention_every_changes_required',
                    'value' => $evidence['review']['human_attention_every_changes_required'],
                    'reason' => 'Governed human Attention policy is not an onboarding setting.',
                ],
            ],
            'quality_script_names' => $evidence['quality']['composer_scripts'],
            'warnings' => $evidence['warnings'],
            'authorization' => [
                'writes' => false,
                'executes_project_code' => false,
                'apply_supported' => false,
            ],
        ];
    }

    /** @param list<string> $tokens */
    private function parseLanguage(array $tokens): ?string
    {
        $language = null;
        $seenFormat = false;
        foreach ($tokens as $token) {
            if ($token === '--format=json' && !$seenFormat) {
                $seenFormat = true;
                continue;
            }

            if (str_starts_with($token, '--language=') && $language === null) {
                $language = $this->validatedLanguage(substr($token, strlen('--language=')));
                continue;
            }

            throw new InvalidArgumentException('supported options are --format=json and --language=<BCP47>.');
        }

        return $language;
    }

    private function validatedLanguage(string $language): string
    {
        if (strlen($language) > 35 || preg_match('/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\z/', $language) !== 1) {
            throw new InvalidArgumentException('language must be a BCP 47 tag accepted by init config.');
        }

        return strtolower($language);
    }
}
