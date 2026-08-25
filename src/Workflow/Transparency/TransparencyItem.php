<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * One renderable fact, carrying the category and provenance it must keep.
 *
 * The typed sections remain the detailed API. This flattening exists so a list
 * view can show every category at once without a host re-classifying anything.
 */
final readonly class TransparencyItem
{
    public function __construct(
        public TransparencyCategory $category,
        public string $value,
        public ?string $detail = null,
    ) {
    }

    public function provenance(): TransparencyProvenance
    {
        return $this->category->provenance();
    }

    /**
     * @return array{
     *     category: string,
     *     provenance: string,
     *     value: string,
     *     detail: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'provenance' => $this->provenance()->value,
            'value' => $this->value,
            'detail' => $this->detail,
        ];
    }
}
