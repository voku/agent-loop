<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * Repository-relative paths that currently differ from the Contract baseline.
 *
 * This is observation, never completion evidence: a changed file proves work
 * touched a path, and an empty change set proves nothing about acceptance
 * criteria. Callers that need authority read the Contract and the validation,
 * verification and review evidence instead.
 */
final readonly class RepositoryObservation
{
    /**
     * @param list<string> $committed Changed between the Contract base commit and current HEAD.
     * @param list<string> $staged Changed between current HEAD and the index.
     * @param list<string> $unstaged Changed between the index and the working tree.
     * @param list<string> $untracked Present in the working tree and not tracked or ignored.
     * @param list<string> $changedFiles Sorted union of the four sets above.
     */
    private function __construct(
        public RepositoryObservationStatus $status,
        public ?string $baseCommit,
        public ?string $headCommit,
        public array $committed,
        public array $staged,
        public array $unstaged,
        public array $untracked,
        public array $changedFiles,
        public ?string $unavailableReason,
    ) {
    }

    /**
     * @param list<string> $committed
     * @param list<string> $staged
     * @param list<string> $unstaged
     * @param list<string> $untracked
     */
    public static function observed(
        string $baseCommit,
        ?string $headCommit,
        array $committed,
        array $staged,
        array $unstaged,
        array $untracked,
    ): self {
        $changed = array_values(array_unique([...$committed, ...$staged, ...$unstaged, ...$untracked]));
        sort($changed, SORT_STRING);

        return new self(
            RepositoryObservationStatus::OBSERVED,
            $baseCommit,
            $headCommit,
            $committed,
            $staged,
            $unstaged,
            $untracked,
            $changed,
            null,
        );
    }

    public static function unavailable(
        RepositoryObservationStatus $status,
        ?string $baseCommit,
        string $reason,
    ): self {
        return new self($status, $baseCommit, null, [], [], [], [], [], $reason);
    }

    public function isObserved(): bool
    {
        return $this->status->isObserved();
    }

    /**
     * @return array{
     *     status: string,
     *     observed: bool,
     *     base_commit: string|null,
     *     head_commit: string|null,
     *     committed: list<string>,
     *     staged: list<string>,
     *     unstaged: list<string>,
     *     untracked: list<string>,
     *     changed_files: list<string>,
     *     unavailable_reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'observed' => $this->isObserved(),
            'base_commit' => $this->baseCommit,
            'head_commit' => $this->headCommit,
            'committed' => $this->committed,
            'staged' => $this->staged,
            'unstaged' => $this->unstaged,
            'untracked' => $this->untracked,
            'changed_files' => $this->changedFiles,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}
