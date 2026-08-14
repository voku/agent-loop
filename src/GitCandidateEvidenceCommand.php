<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\Run\CanonicalJson;

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
        $format = OptionTokens::value($tokens, 'format') ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->fail('--format must be text or json.', $format);
        }

        try {
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
        if ($value === null) {
            throw new RuntimeException('Missing required option: --' . $name . '.');
        }

        return $value;
    }

    private function fail(string $message, string $format): int
    {
        if ($format === 'json') {
            echo CanonicalJson::pretty(['status' => 'fail', 'error' => $message]);
        } else {
            fwrite(STDERR, '[FAIL] candidate evidence: ' . $message . "\n");
        }

        return 1;
    }
}
