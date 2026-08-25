<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * One category of context dropped because the render budget ran out.
 *
 * A budget omission is a context-construction fact. It says nothing about
 * whether the omitted material mattered, and nothing about implementation.
 */
final readonly class ContextOmission
{
    public function __construct(public string $category, public int $count)
    {
    }

    /** @return array{category: string, count: int} */
    public function toArray(): array
    {
        return ['category' => $this->category, 'count' => $this->count];
    }
}
