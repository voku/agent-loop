<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class HumanReviewDiff
{
    /**
     * @param list<string> $changedFiles
     * @param list<string> $untrackedFiles
     */
    public function __construct(
        public bool $available,
        public ?string $baseCommit,
        public array $changedFiles,
        public array $untrackedFiles,
        public string $patch,
        public ?string $unavailableReason = null,
    ) {
    }

    public static function unavailable(?string $baseCommit, string $reason): self
    {
        return new self(false, $baseCommit, [], [], '', $reason);
    }
}
