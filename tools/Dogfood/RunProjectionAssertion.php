<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Checks the durable Run projection instead of trusting an exit code.
 *
 * `workflow close` exits 0 on success, but the question the gate actually asks
 * is whether every owner agrees afterwards - and once more after Session
 * pruning, because the point of a durable receipt is that it outlives the
 * working memory that produced it.
 *
 * Returns the problems it found rather than throwing on the first one: a
 * reviewer fixing a projection wants to see every disagreement, not the
 * alphabetically first.
 */
final readonly class RunProjectionAssertion
{
    /**
     * @param array<string, mixed> $status decoded `workflow status --format json`
     * @return list<string>
     */
    public function beforePrune(array $status, string $expectedRunId, string $expectedLearningDecision): array
    {
        $problems = $this->common($status, $expectedRunId);
        $problems = [...$problems, ...$this->expect($status, ['manifest', 'next_action'], 'none')];
        $problems = [...$problems, ...$this->expect($status, ['manifest', 'references', 'session', 'state'], 'done')];

        return [
            ...$problems,
            ...$this->expect($status, ['manifest', 'references', 'learning', 'decision'], $expectedLearningDecision),
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @return list<string>
     */
    public function afterPrune(array $status, string $expectedRunId): array
    {
        return [
            ...$this->common($status, $expectedRunId),
            // The Session is expected to be gone. Everything durable is not.
            ...$this->expect($status, ['manifest', 'references', 'session', 'state'], 'missing'),
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @return list<string>
     */
    private function common(array $status, string $expectedRunId): array
    {
        return [
            ...$this->expect($status, ['manifest', 'run_id'], $expectedRunId),
            ...$this->expect($status, ['manifest', 'state'], 'complete'),
            ...$this->expect($status, ['manifest', 'references', 'verification', 'state'], 'passed'),
            ...$this->expect($status, ['manifest', 'references', 'learning', 'state'], 'decided'),
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @param non-empty-list<string> $path
     * @return list<string>
     */
    private function expect(array $status, array $path, string $expected): array
    {
        $value = $this->valueAt($status, $path);
        if ($value === $expected) {
            return [];
        }

        if ($value === null) {
            return [sprintf('%s is missing; expected %s.', implode('.', $path), $expected)];
        }

        return [sprintf(
            '%s is %s; expected %s.',
            implode('.', $path),
            is_scalar($value) ? var_export($value, true) : gettype($value),
            var_export($expected, true),
        )];
    }

    /**
     * @param array<string, mixed> $status
     * @param non-empty-list<string> $path
     */
    private function valueAt(array $status, array $path): mixed
    {
        $cursor = $status;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }
}
