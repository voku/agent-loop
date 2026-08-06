<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

final class RelativePath
{
    public static function fromRoot(string $rootPath, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $rootPath), '/');
        $normalized = str_replace('\\', '/', $path);
        if ($root !== '' && str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $normalized;
    }
}
