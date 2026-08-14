<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

function gitDirectoryShapeAssumption(string $rootPath): bool
{
    // A linked worktree stores .git as a file, so this reports a valid checkout
    // as "not a repository".
    return is_dir($rootPath . '/.git');
}
