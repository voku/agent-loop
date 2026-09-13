<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class RepositorySetupProjection
{
    /**
     * @param list<RepositorySetupContributor> $contributors
     */
    public function __construct(
        public ?string $host,
        public RepositorySetupSelection $selection,
        public ?RepositorySetupRuntime $runtime,
        public ?RepositorySetupIntegration $integration,
        public ?string $policyDetail,
        public ?string $policyPath,
        public ?string $runtimeBoundary,
        public array $contributors,
        public RepositorySetupNextActionKind $nextActionKind,
        public ?string $nextAction,
    ) {
    }

    /**
     * @return array{
     *     schema_version: 1,
     *     host: string|null,
     *     selection: string,
     *     runtime: array{status: string, command: string|null, path: string|null}|null,
     *     integration: array{instructions: string, skills: string, subagents: string, policy: string, git_integration: string}|null,
     *     policy_detail: string|null,
     *     policy_path: string|null,
     *     runtime_boundary: string|null,
     *     contributors: list<array{owner: non-empty-string, scope: string, skill_count: int<0, max>, subagent_count: int<0, max>, instruction_count: int<0, max>}>,
     *     next_action_kind: string,
     *     next_action: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'host' => $this->host,
            'selection' => $this->selection->value,
            'runtime' => $this->runtime?->toArray(),
            'integration' => $this->integration?->toArray(),
            'policy_detail' => $this->policyDetail,
            'policy_path' => $this->policyPath,
            'runtime_boundary' => $this->runtimeBoundary,
            'contributors' => array_map(
                static fn (RepositorySetupContributor $contributor): array => $contributor->toArray(),
                $this->contributors,
            ),
            'next_action_kind' => $this->nextActionKind->value,
            'next_action' => $this->nextAction,
        ];
    }
}
