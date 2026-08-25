<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class RepositorySetupProjection
{
    /**
     * @param non-empty-string|null $host
     * @param 'explicit'|'auto'|'ambiguous'|'missing' $selection
     * @param non-empty-string|null $policyDetail
     * @param non-empty-string|null $policyPath
     * @param non-empty-string|null $runtimeBoundary
     * @param 'command'|'host_work'|'decision_required'|'none' $nextActionKind
     * @param non-empty-string|null $nextAction
     */
    public function __construct(
        public ?string $host,
        public string $selection,
        public ?RepositorySetupRuntime $runtime,
        public ?RepositorySetupIntegration $integration,
        public ?string $policyDetail,
        public ?string $policyPath,
        public ?string $runtimeBoundary,
        public string $nextActionKind,
        public ?string $nextAction,
    ) {
    }

    /**
     * @return array{
     *     schema_version: 1,
     *     host: non-empty-string|null,
     *     selection: 'explicit'|'auto'|'ambiguous'|'missing',
     *     runtime: array{status: 'available'|'missing'|'unprobed', command: non-empty-string|null, path: non-empty-string|null}|null,
     *     integration: array{instructions: 'ready'|'missing', skills: 'ready'|'missing', subagents: 'ready'|'missing', policy: 'ready'|'missing'|'conflict'|'manual'|'unsupported', git_integration: 'ready'|'missing'|'not_declared'}|null,
     *     policy_detail: non-empty-string|null,
     *     policy_path: non-empty-string|null,
     *     runtime_boundary: non-empty-string|null,
     *     next_action_kind: 'command'|'host_work'|'decision_required'|'none',
     *     next_action: non-empty-string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'host' => $this->host,
            'selection' => $this->selection,
            'runtime' => $this->runtime?->toArray(),
            'integration' => $this->integration?->toArray(),
            'policy_detail' => $this->policyDetail,
            'policy_path' => $this->policyPath,
            'runtime_boundary' => $this->runtimeBoundary,
            'next_action_kind' => $this->nextActionKind,
            'next_action' => $this->nextAction,
        ];
    }
}
