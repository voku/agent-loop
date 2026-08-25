<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class RepositorySetupIntegration
{
    public function __construct(
        public RepositorySetupIntegrationState $instructions,
        public RepositorySetupIntegrationState $skills,
        public RepositorySetupIntegrationState $subagents,
        public RepositorySetupIntegrationState $policy,
        public RepositorySetupIntegrationState $gitIntegration,
    ) {
    }

    /**
     * @return array{
     *     instructions: string,
     *     skills: string,
     *     subagents: string,
     *     policy: string,
     *     git_integration: string
     * }
     */
    public function toArray(): array
    {
        return [
            'instructions' => $this->instructions->value,
            'skills' => $this->skills->value,
            'subagents' => $this->subagents->value,
            'policy' => $this->policy->value,
            'git_integration' => $this->gitIntegration->value,
        ];
    }
}
