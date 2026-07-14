<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * Shared absolute/relative path helpers for resolving a configured path
 * (which may be relative to a project root, or an absolute path elsewhere)
 * against that root, and for rendering an absolute path back down to a
 * root-relative one for display. Used wherever a `paths.*` config value or a
 * resolved output location needs the same join/display logic.
 */
final class PathResolver
{
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix / WSL absolute path
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path with drive letter (e.g. C:\ or C:/)
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1) {
            return true;
        }

        // Windows UNC path (e.g. \\server\share or //server/share)
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return false;
    }

    /**
     * Joins a possibly-relative configured path onto a root, leaving an
     * absolute path (Unix or Windows) untouched.
     */
    public static function join(string $rootPath, string $path): string
    {
        if ($path === '') {
            return rtrim(str_replace('\\', '/', $rootPath), '/');
        }

        if (self::isAbsolute($path)) {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        return rtrim(str_replace('\\', '/', $rootPath), '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The inverse of join() for display: an absolute path under rootPath is
     * shown relative to it; a path outside rootPath (e.g. a configured
     * absolute override) is returned unchanged rather than mangled.
     */
    public static function relativeTo(string $rootPath, string $absolutePath): string
    {
        $root = rtrim($rootPath, '/') . '/';

        return str_starts_with($absolutePath, $root) ? substr($absolutePath, strlen($root)) : $absolutePath;
    }
}
