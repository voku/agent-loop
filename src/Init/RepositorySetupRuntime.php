<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class RepositorySetupRuntime
{
    /**
     * @param 'available'|'missing'|'unprobed' $status
     * @param non-empty-string|null $command
     * @param non-empty-string|null $path
     */
    public function __construct(
        public string $status,
        public ?string $command,
        public ?string $path,
    ) {
    }

    /**
     * @return array{
     *     status: 'available'|'missing'|'unprobed',
     *     command: non-empty-string|null,
     *     path: non-empty-string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'command' => $this->command,
            'path' => $this->path,
        ];
    }
}
