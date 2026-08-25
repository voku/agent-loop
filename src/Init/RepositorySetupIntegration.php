<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class RepositorySetupIntegration
{
    /**
     * @param 'ready'|'missing' $instructions
     * @param 'ready'|'missing' $skills
     * @param 'ready'|'missing' $subagents
     * @param 'ready'|'missing'|'conflict'|'manual'|'unsupported' $policy
     * @param 'ready'|'missing'|'not_declared' $gitIntegration
     */
    public function __construct(
        public string $instructions,
        public string $skills,
        public string $subagents,
        public string $policy,
        public string $gitIntegration,
    ) {
    }

    /**
     * @return array{
     *     instructions: 'ready'|'missing',
     *     skills: 'ready'|'missing',
     *     subagents: 'ready'|'missing',
     *     policy: 'ready'|'missing'|'conflict'|'manual'|'unsupported',
     *     git_integration: 'ready'|'missing'|'not_declared'
     * }
     */
    public function toArray(): array
    {
        return [
            'instructions' => $this->instructions,
            'skills' => $this->skills,
            'subagents' => $this->subagents,
            'policy' => $this->policy,
            'git_integration' => $this->gitIntegration,
        ];
    }
}
