<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood\Routing;

use InvalidArgumentException;

final readonly class RoutingExperimentReceipt
{
    /** @var 'baseline'|'hinted' */
    public string $arm;

    /**
     * @param 'baseline'|'hinted' $arm
     * @param non-empty-list<string> $candidateIds
     */
    private function __construct(
        public string $fixtureId,
        string $arm,
        public string $baseSha,
        public array $candidateIds,
        public ?RoutingHint $hint,
        public ?string $selectedCandidateId,
        public bool $spawnObserved,
        public bool $childStarted,
        public bool $waitCompleted,
    ) {
        $this->arm = $arm;
    }

    public static function observe(
        RoutingExperimentFixture $fixture,
        string $baseSha,
        ?string $selectedCandidateId,
        bool $spawnObserved,
        bool $childStarted,
        bool $waitCompleted,
    ): self {
        if (preg_match('/^[a-f0-9]{40}$/', $baseSha) !== 1) {
            throw new InvalidArgumentException('Routing experiment base SHA must be a full Git commit SHA.');
        }

        $candidateIds = $fixture->candidateIds();
        if ($selectedCandidateId !== null && !in_array($selectedCandidateId, $candidateIds, true)) {
            throw new InvalidArgumentException('Observed routing selection is outside the fixture candidate set.');
        }
        if ($childStarted && !$spawnObserved) {
            throw new InvalidArgumentException('Routing experiment cannot observe a child start without an observed spawn.');
        }
        if ($waitCompleted && !$childStarted) {
            throw new InvalidArgumentException('Routing experiment cannot complete wait before the selected child starts.');
        }

        return new self(
            $fixture->fixtureId,
            $fixture->arm(),
            $baseSha,
            $candidateIds,
            $fixture->hint,
            $selectedCandidateId,
            $spawnObserved,
            $childStarted,
            $waitCompleted,
        );
    }

    /**
     * @return array{
     *     schema_version: '1.0',
     *     fixture_id: string,
     *     arm: 'baseline'|'hinted',
     *     base_sha: string,
     *     candidate_ids: non-empty-list<string>,
     *     hint: array{candidate_id: string, probability: float}|null,
     *     selected_candidate_id: string|null,
     *     spawn_observed: bool,
     *     child_started: bool,
     *     wait_completed: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'fixture_id' => $this->fixtureId,
            'arm' => $this->arm,
            'base_sha' => $this->baseSha,
            'candidate_ids' => $this->candidateIds,
            'hint' => $this->hint?->toArray(),
            'selected_candidate_id' => $this->selectedCandidateId,
            'spawn_observed' => $this->spawnObserved,
            'child_started' => $this->childStarted,
            'wait_completed' => $this->waitCompleted,
        ];
    }
}
