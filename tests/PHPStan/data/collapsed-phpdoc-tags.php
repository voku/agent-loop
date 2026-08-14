<?php

declare(strict_types=1);

namespace voku\AgentLoop\Fixture;

final class CollapsedPhpDocTagsFixture
{
    /** @param list<string> $values @return list<string> */
    public function passthrough(array $values): array
    {
        return $values;
    }
}
