<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use JsonException;
use RuntimeException;

/**
 * Decides what the self-shape run may honestly claim about compiled guidance.
 *
 * `recall compile` pre-fills one row per selected guidance - outcome `unknown`,
 * no comment - for a session to complete afterwards. This runner is not that
 * session: it compiles Recall to prove the governed lifecycle works and never
 * reads `system.md`, so it has no opinion about whether any rule helped.
 *
 * Logging those rows unedited is what produced the eight `unknown` outcomes in
 * this repository's history, every one of them written by the same harness that
 * selected the guidance. Inventing a bucket instead would be worse:
 * `not_used` and `irrelevant` both count towards retirement in agent-learning's
 * staleness policies, so a runner with nothing to say would vote to delete rules
 * it never read.
 *
 * The selection events are still recorded, because the compiler really did
 * select. Only the judgement is withheld, and the reason is written down.
 */
final readonly class RecallOutcomeDraft
{
    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public static function withheld(array $draft, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Withholding a guidance judgement requires a reason.');
        }

        $draft['guidance_outcomes'] = [];
        $draft['guidance_outcomes_withheld_reason'] = $reason;

        return $draft;
    }

    /** Returns how many pre-filled judgements were dropped. */
    public static function withholdInFile(string $path, string $reason): int
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read outcome draft: ' . $path);
        }

        try {
            $draft = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Malformed outcome draft ' . $path . ': ' . $exception->getMessage(),
                previous: $exception,
            );
        }
        if (!is_array($draft)) {
            throw new RuntimeException('Outcome draft must be a JSON object: ' . $path);
        }

        /** @var array<string, mixed> $draft */
        $withheldCount = is_array($draft['guidance_outcomes'] ?? null) ? count($draft['guidance_outcomes']) : 0;

        $encoded = json_encode(
            self::withheld($draft, $reason),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($path, $encoded) === false) {
            throw new RuntimeException('Cannot write outcome draft: ' . $path);
        }

        return $withheldCount;
    }
}
