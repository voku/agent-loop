<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\Run\CanonicalJson;

/** CLI adapter for the exact-candidate shipping evidence invariant. */
final readonly class GitCandidateEvidenceCommand
{
    /** @var list<string> */
    private const array VALUE_OPTIONS = [
        'candidate-sha',
        'integrated-sha',
        'target-ref',
        'release-tag',
        'format',
    ];

    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public static function requested(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                continue;
            }

            $raw = substr($token, 2);
            $name = str_contains($raw, '=') ? strstr($raw, '=', true) : $raw;
            if (is_string($name) && $name !== 'format' && in_array($name, self::VALUE_OPTIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $format = OptionTokens::value($tokens, 'format') ?? 'text';
        try {
            $this->validateTokens($tokens);
            if (!in_array($format, ['text', 'json', 'toon'], true)) {
                throw new RuntimeException('--format must be text, json, or toon.');
            }

            $evidence = (new GitCandidateEvidence($this->rootPath))->prove(
                $this->required($tokens, 'candidate-sha'),
                $this->required($tokens, 'integrated-sha'),
                $this->required($tokens, 'target-ref'),
                OptionTokens::value($tokens, 'release-tag'),
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), $format);
        }

        if ($format === 'json') {
            echo CanonicalJson::pretty($evidence);

            return 0;
        }
        if ($format === 'toon') {
            echo AgentOutput::toon($evidence);

            return 0;
        }

        printf(
            "[OK] candidate evidence: %s -> %s via %s -> %s (%s)\n",
            $evidence['candidate_sha'],
            $evidence['integrated_sha'],
            $evidence['integration_kind'],
            $evidence['target_sha'],
            $evidence['target_ref'],
        );
        if ($evidence['release_tag'] !== null) {
            printf(
                "[OK] release evidence: tag %s object %s -> commit %s\n",
                $evidence['release_tag'],
                $evidence['tag_object_sha'],
                $evidence['release_commit_sha'],
            );
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @return non-empty-string
     */
    private function required(array $tokens, string $name): string
    {
        $value = OptionTokens::value($tokens, $name);
        if ($value === null || $value === '') {
            throw new RuntimeException('Missing required option: --' . $name . '.');
        }

        return $value;
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): void
    {
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                throw new RuntimeException('Unknown candidate evidence argument: ' . $token);
            }

            $raw = substr($token, 2);
            $name = str_contains($raw, '=') ? strstr($raw, '=', true) : $raw;
            if (!is_string($name) || !in_array($name, self::VALUE_OPTIONS, true)) {
                throw new RuntimeException('Unknown candidate evidence option: --' . (is_string($name) ? $name : ''));
            }

            if (str_contains($raw, '=')) {
                $value = substr($raw, strlen($name) + 1);
                if ($value === '') {
                    throw new RuntimeException('Missing value for --' . $name . '.');
                }

                continue;
            }

            $value = $tokens[$index + 1] ?? null;
            if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                throw new RuntimeException('Missing value for --' . $name . '.');
            }
            ++$index;
        }
    }

    private function fail(string $message, string $format): int
    {
        if ($format === 'json') {
            echo CanonicalJson::pretty(['status' => 'fail', 'error' => $message]);
        } elseif ($format === 'toon') {
            echo AgentOutput::toon(['status' => 'fail', 'error' => $message]);
        } else {
            fwrite(STDERR, '[FAIL] candidate evidence: ' . $message . "\n");
        }

        return 1;
    }
}
