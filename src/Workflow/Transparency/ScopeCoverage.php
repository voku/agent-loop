<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * Observed changes split by the approved Contract scope.
 *
 * `observed` is what separates "nothing changed outside scope" from "nothing
 * could be observed". Collapsing the two would let an unobservable repository
 * read as a clean one.
 */
final readonly class ScopeCoverage
{
    /**
     * @param list<string> $changedInScope
     * @param list<string> $changedOutsideScope
     */
    public function __construct(
        public bool $observed,
        public array $changedInScope,
        public array $changedOutsideScope,
    ) {
    }

    public static function fromObservation(RepositoryObservation $observation, ApprovedScope $scope): self
    {
        if (!$observation->isObserved()) {
            return new self(false, [], []);
        }

        $partition = $scope->partition($observation->changedFiles);

        return new self(true, $partition['inside'], $partition['outside']);
    }

    /**
     * @return array{
     *     observed: bool,
     *     changed_in_scope: list<string>,
     *     changed_outside_scope: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'observed' => $this->observed,
            'changed_in_scope' => $this->changedInScope,
            'changed_outside_scope' => $this->changedOutsideScope,
        ];
    }
}
