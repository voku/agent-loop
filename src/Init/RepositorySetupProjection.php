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
        public RepositorySetupNextActionKind $nextActionKind,
        public ?string $nextAction,
        public array $contributors = [],
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
     *     next_action_kind: string,
     *     next_action: string|null,
     *     contributors: list<array{
     *         owner: non-empty-string,
     *         scope: RepositorySetupContributor::SCOPE_CONSUMER|RepositorySetupContributor::SCOPE_OWNER_REPOSITORY|RepositorySetupContributor::SCOPE_PROJECT|RepositorySetupContributor::SCOPE_LOCAL,
     *         skill_count: int<0, max>,
     *         subagent_count: int<0, max>,
     *         instruction_count: int<0, max>
     *     }>
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
            'next_action_kind' => $this->nextActionKind->value,
            'next_action' => $this->nextAction,
            'contributors' => array_map(
                static fn (RepositorySetupContributor $contributor): array => $contributor->toArray(),
                $this->contributors,
            ),
        ];
    }
}
