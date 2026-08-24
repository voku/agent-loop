<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use RuntimeException;

/**
 * Decides what a pull request recorded, from the paths it touched.
 *
 * This is the part of the self-shape gate that has actually been wrong. As
 * Bash it watched `findings/validated/` alone, but `learn finding-transition`
 * moves a consolidated finding out of that directory - the normal end of its
 * lifecycle - so a change whose entire subject was recording learning looked
 * as though it had recorded none, and the gate then chose a status it could
 * not satisfy.
 *
 * Findings are therefore matched by identity across every state directory. A
 * shell script could have done that too; what it could not do is be tested,
 * which is why the defect shipped.
 */
final readonly class SelfShapeEvidence
{
    private const string FINDINGS_PREFIX = '.agent-loop/learning/findings/';

    private const string MEMORY_FILE = 'MEMORY.md';

    /**
     * @param list<string> $changedPaths every path added or renamed between base and head
     * @param bool $memoryChanged whether MEMORY.md differs between base and head
     */
    public function __construct(
        private array $changedPaths,
        private bool $memoryChanged,
    ) {
    }

    /**
     * Finding IDs this change recorded, in stable order and without repeats.
     *
     * A rename shows the same finding under two paths; the ID is what the
     * Learning decision cites, so the ID is what gets de-duplicated.
     *
     * @return list<string>
     */
    public function recordedFindingIds(): array
    {
        $ids = [];
        foreach ($this->changedPaths as $path) {
            $normalized = str_replace('\\', '/', $path);
            if (!str_starts_with($normalized, self::FINDINGS_PREFIX) || !str_ends_with($normalized, '.json')) {
                continue;
            }
            $id = basename($normalized, '.json');
            if (str_starts_with($id, 'finding.')) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The Learning decision this change's own evidence supports.
     *
     * `findings_recorded` requires at least one cited finding, so it can only
     * be chosen when one exists. A `MEMORY.md` change with no finding behind it
     * is not a status to be selected around: the repository's promotion rule
     * says a durable rule needs evidence, so it fails here instead.
     */
    public function learningStatus(): string
    {
        if ($this->recordedFindingIds() !== []) {
            return 'findings_recorded';
        }

        if ($this->memoryChanged) {
            throw new RuntimeException(
                self::MEMORY_FILE . ' changed but no project finding was recorded; a durable rule needs evidence.',
            );
        }

        return 'no_durable_learning';
    }

    public function learningReason(): string
    {
        return $this->recordedFindingIds() === []
            ? 'The self-shape gate observed no new reusable guidance beyond the durable changes already represented by this pull request.'
            : 'The pull-request evidence recorded project findings; cite that evidence in the governed Run.';
    }
}
