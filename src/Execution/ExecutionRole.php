<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class ExecutionRole
{
    /** @param list<non-empty-string> $requiredCapabilities */
    public function __construct(
        public string $id,
        public bool $mayMutate,
        public array $requiredCapabilities,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $this->id) !== 1) {
            throw new InvalidArgumentException('Execution role id must match [a-z][a-z0-9-]*.');
        }
        foreach ($this->requiredCapabilities as $capability) {
            if (trim($capability) === '') {
                throw new InvalidArgumentException('Execution role capabilities must be non-empty strings.');
            }
        }
    }

    /** @return array{id: string, may_mutate: bool, required_capabilities: list<non-empty-string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'may_mutate' => $this->mayMutate,
            'required_capabilities' => $this->requiredCapabilities,
        ];
    }
}
