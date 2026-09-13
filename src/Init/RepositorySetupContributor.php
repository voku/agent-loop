<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class RepositorySetupContributor
{
    public function __construct(
        public string $owner,
        public RepositorySetupContributorRole $role,
        public int $skills,
        public int $subagents,
    ) {
    }

    /**
     * @return array{
     *     owner: string,
     *     role: string,
     *     skills: int,
     *     subagents: int
     * }
     */
    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            'role' => $this->role->value,
            'skills' => $this->skills,
            'subagents' => $this->subagents,
        ];
    }
}
