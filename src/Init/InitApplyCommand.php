<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Apply only explicitly accepted and provenance-checked setup fields. */
final readonly class InitApplyCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $options = $this->parse($tokens);
            $plan = (new InitPlanCommand($this->rootPath))->inspect($options['language']);
            if (!hash_equals($plan['evidence_sha256'], $options['evidence_sha256'])) {
                throw new RuntimeException('Setup evidence changed since review. Generate and review a new init plan.');
            }

            $status = $options['yes']
                ? (new InitLanguageConfigWriter($this->rootPath))->apply($options['language'], $options['evidence_sha256'])
                : 'dry_run';

            echo json_encode([
                'status' => $status,
                'field' => 'prompting.language',
                'target' => '.agent-loop/init.json',
                'evidence_sha256' => $options['evidence_sha256'],
                'review_diffs' => $plan['review_diffs'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (InvalidArgumentException | JsonException | RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] init apply: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{language: string, evidence_sha256: string, yes: bool}
     */
    private function parse(array $tokens): array
    {
        $options = [];
        $yes = false;
        $dryRun = false;
        foreach ($tokens as $token) {
            if ($token === '--yes' && !$yes) {
                $yes = true;

                continue;
            }
            if ($token === '--dry-run' && !$dryRun) {
                $dryRun = true;

                continue;
            }
            if (str_starts_with($token, '--language=') && !isset($options['language'])) {
                $options['language'] = substr($token, strlen('--language='));

                continue;
            }
            if (str_starts_with($token, '--evidence-sha256=') && !isset($options['evidence_sha256'])) {
                $options['evidence_sha256'] = substr($token, strlen('--evidence-sha256='));

                continue;
            }
            if ($token === '--accept=prompting.language' && !isset($options['accept'])) {
                $options['accept'] = true;

                continue;
            }

            throw new InvalidArgumentException('supported options are --language, --evidence-sha256, --accept=prompting.language, --dry-run and --yes.');
        }

        return $this->validatedOptions($options, $yes, $dryRun);
    }

    /**
     * @param array{language?: string, evidence_sha256?: string, accept?: true} $options
     * @return array{language: string, evidence_sha256: string, yes: bool}
     */
    private function validatedOptions(array $options, bool $yes, bool $dryRun): array
    {
        if ($yes && $dryRun) {
            throw new InvalidArgumentException('--yes and --dry-run are mutually exclusive.');
        }
        if (!isset($options['language'], $options['evidence_sha256'], $options['accept'])) {
            throw new InvalidArgumentException('language, evidence SHA-256, and explicit --accept=prompting.language are required.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $options['evidence_sha256']) !== 1) {
            throw new InvalidArgumentException('--evidence-sha256 must contain 64 lowercase hexadecimal characters.');
        }
        if (strlen($options['language']) > 35 || preg_match('/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\z/', $options['language']) !== 1) {
            throw new InvalidArgumentException('--language must be a valid BCP 47 language tag.');
        }

        return [
            'language' => strtolower($options['language']),
            'evidence_sha256' => $options['evidence_sha256'],
            'yes' => $yes,
        ];
    }
}
