<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use InvalidArgumentException;

/** Resolves a Composer path repository to a URL independent of the caller's cwd. */
final class ComposerPathRepository
{
    public static function url(string $path): string
    {
        $resolved = realpath($path);
        if (!is_string($resolved)) {
            throw new InvalidArgumentException('Composer path repository does not exist: ' . $path);
        }

        return str_replace('\\', '/', $resolved);
    }
}
