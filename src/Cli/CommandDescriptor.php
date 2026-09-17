<?php

declare(strict_types=1);

namespace voku\AgentLoop\Cli;

/**
 * Immutable descriptor of a top-level Dispatcher command.
 */
final readonly class CommandDescriptor
{
    public function __construct(
        public CommandId $id,
        public CommandGroup $group,
        public CommandOwner $owner,
        public string $summary,
        public ?string $usage = null,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     group: string,
     *     owner: string,
     *     summary: string,
     *     usage: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'group' => $this->group->value,
            'owner' => $this->owner->value,
            'summary' => $this->summary,
            'usage' => $this->usage,
        ];
    }
}
