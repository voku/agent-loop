<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\PHPStan\Data;

final class GitDirectoryShapeFixture
{
    public function repository(string $root): bool
    {
        return is_dir($root . '/.git');
    }
}
