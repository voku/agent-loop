<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use JsonException;
use RuntimeException;

/** CLI adapter for the exact-candidate shipping evidence invariant. */
final readonly class GitCandidateEvidenceCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public static function requested(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '--candidate-sha' || str_starts_with($token, '--candidate-sha=')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $options = $this->parse($tokens);
            $evidence = (new GitCandidateEvidence($this->rootPath))->prove(
                $options['candidate-sha'],
                $options['integrated-sha'],
                $options['target-ref'],
                $options['release-tag'],
            );
        } catch (RuntimeException $exception) {
            $format = $this->formatFromTokens($tokens);
            if ($format === 'json') {
                try {
                    echo json_encode(
                        ['status' => 'fail', 'error' => $exception->getMessage()],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ) . "\n";
                } catch (JsonException) {
                    fwrite(STDERR, '[FAIL] candidate evidence: ' . $exception->getMessage() . "\n");
                }
            } else {
                fwrite(STDERR, '[FAIL] candidate evidence: ' . $exception->getMessage() . "\n");
            }

            return 1;
        }

        if ($options['format'] === 'json') {
            try {
                echo json_encode(
                    $evidence,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . "\n";
            } catch (JsonException $exception) {
                fwrite(STDERR, '[FAIL] candidate evidence: unable to encode evidence: ' . $exception->getMessage() . "\n");

                return 1;
            }

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
     * @return array{
     *     candidate-sha: non-empty-string,
     *     integrated-sha: non-empty-string,
     *     target-ref: non-empty-string,
     *     release-tag: non-empty-string|null,
     *     format: 'text'|'json'
     * }
     */
    private function parse(array $tokens): array
    {
        $values = [];
        $allowed = ['candidate-sha', 'integrated-sha', 'target-ref', 'release-tag', 'format'];
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                throw new RuntimeException('Unknown candidate evidence argument: ' . $token);
            }

            $option = substr($token, 2);
            $value = null;
            if (str_contains($option, '=')) {
                [$option, $value] = explode('=', $option, 2);
            } else {
                $candidate = $tokens[$index + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    throw new RuntimeException('Missing value for --' . $option . '.');
                }
                $value = $candidate;
                ++$index;
            }

            if (!in_array($option, $allowed, true)) {
                throw new RuntimeException('Unknown candidate evidence option: --' . $option);
            }
            if ($value === '') {
                throw new RuntimeException('Missing value for --' . $option . '.');
            }
            $values[$option] = $value;
        }

        foreach (['candidate-sha', 'integrated-sha', 'target-ref'] as $required) {
            if (!isset($values[$required])) {
                throw new RuntimeException('Missing required option: --' . $required . '.');
            }
        }

        $format = $values['format'] ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            throw new RuntimeException('--format must be text or json.');
        }

        return [
            'candidate-sha' => $values['candidate-sha'],
            'integrated-sha' => $values['integrated-sha'],
            'target-ref' => $values['target-ref'],
            'release-tag' => $values['release-tag'] ?? null,
            'format' => $format,
        ];
    }

    /** @param list<string> $tokens */
    private function formatFromTokens(array $tokens): string
    {
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (str_starts_with($token, '--format=')) {
                return substr($token, strlen('--format='));
            }
            if ($token === '--format') {
                return $tokens[$index + 1] ?? 'text';
            }
        }

        return 'text';
    }
}
