<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class ExecutionEnvironmentTool
{
    public function __construct(
        public string $id,
        public bool $available,
        public ?string $version = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $this->id) !== 1) {
            throw new InvalidArgumentException('Execution environment tool id must match [a-z][a-z0-9._-]{0,63}.');
        }
        if (!$this->available && $this->version !== null) {
            throw new InvalidArgumentException('Unavailable execution environment tools must not declare a version.');
        }
        if ($this->version !== null) {
            if ($this->version === '' || strlen($this->version) > 128 || preg_match('/[\x00-\x1F\x7F]/', $this->version) === 1) {
                throw new InvalidArgumentException('Execution environment tool version must be a bounded single-line value.');
            }
        }
    }

    /** @return array{id: string, available: bool, version: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'available' => $this->available,
            'version' => $this->version,
        ];
    }
}
