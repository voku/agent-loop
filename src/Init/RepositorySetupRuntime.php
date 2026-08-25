<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class RepositorySetupRuntime
{
    public function __construct(
        public RepositorySetupRuntimeState $status,
        public ?string $command,
        public ?string $path,
    ) {
    }

    /**
     * @return array{
     *     status: 'available'|'missing'|'unprobed',
     *     command: string|null,
     *     path: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'command' => $this->command,
            'path' => $this->path,
        ];
    }
}
