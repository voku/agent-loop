<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Fixtures\SelfShape;

final readonly class SelfEditProbe
{
    public function value(int $input): int
    {
        return 100 + $input;
    }
}
