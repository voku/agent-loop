<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * The only code that decides what a path means relative to the project root.
 *
 * A configured `paths.*` value, an environment variable naming a host asset
 * directory and a stored artifact reference are the same question asked three
 * ways: is this absolute, and if not, what is it relative to? Every private
 * answer to it agreed with this one only on Unix and only by accident, which is
 * how `init status` and `init sync-hooks` came to report two different Codex
 * hooks directories for one `CODEX_HOME`.
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
     * A directory named by an environment variable, resolved exactly like a
     * configured path. Null when the variable is unset or blank, so a caller
     * falls back to its own default instead of joining an empty string.
     */
    public static function fromEnvironment(string $rootPath, string $variableName): ?string
    {
        $value = getenv($variableName);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return rtrim(self::join($rootPath, trim($value)), '/');
    }

    /**
     * The inverse of join() for display: an absolute path under rootPath is
     * shown relative to it; a path outside rootPath (e.g. a configured
     * absolute override) is returned unchanged rather than mangled.
     */
    public static function relativeTo(string $rootPath, string $absolutePath): string
    {
        $root = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';
        $normalized = str_replace('\\', '/', $absolutePath);

        return str_starts_with($normalized, $root)
            ? substr($normalized, strlen($root))
            : $normalized;
    }
}
