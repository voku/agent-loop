<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * Single authoritative source for where workflow, verify, review, and recall
 * commands read and write a task's compiled briefing.
 */
final class RecallOutputRoot
{
    public static function resolve(string $rootPath): string
    {
        return (new ProjectLayout($rootPath))->recallRoot();
    }

    /**
     * Renders an absolute path under $rootPath as a root-relative path for display.
     */
    public static function relativeTo(string $rootPath, string $absolutePath): string
    {
        return PathResolver::relativeTo($rootPath, $absolutePath);
    }
}
