<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

use JsonException;
use RuntimeException;
use Throwable;

/** Stores explicit, user-scoped trust for repository shell-hook configuration. */
final readonly class HookConfigTrustStore
{
    private ?string $storePath;

    public function __construct(?string $storePath = null)
    {
        $this->storePath = $storePath;
    }

    /** Record the current bytes of one repository-local hook config after explicit invocation. */
    public function trust(string $repositoryRoot, string $configPath): string
    {
        $root = self::canonicalRoot($repositoryRoot);
        [$canonicalConfig, $relativeConfig] = $this->resolveConfig($root, $configPath);
        $content = file_get_contents($canonicalConfig);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read hook configuration: ' . $canonicalConfig);
        }

        $digest = hash('sha256', $content);
        $storePath = $this->resolvedStorePath($root, createDirectory: true);
        $store = $this->readStore($storePath);
        $store['repositories'][$root][$relativeConfig] = $digest;
        $this->writeStore($storePath, $store, $root);

        return $digest;
    }

    /** Verify the exact bytes that the caller will parse and execute. */
    public function assertTrusted(string $repositoryRoot, string $configPath, string $content): void
    {
        $root = self::canonicalRoot($repositoryRoot);
        [, $relativeConfig] = $this->resolveConfig($root, $configPath);
        $storePath = $this->resolvedStorePath($root, createDirectory: false);
        $store = $this->readStore($storePath);
        $trustedDigest = $store['repositories'][$root][$relativeConfig] ?? null;
        if (!is_string($trustedDigest)) {
            throw new RuntimeException(sprintf(
                'Hook configuration is not trusted: %s. Review it, then run `agent-loop githooks trust --config=%s`.',
                $relativeConfig,
                $relativeConfig,
            ));
        }

        $currentDigest = hash('sha256', $content);
        if (!hash_equals($trustedDigest, $currentDigest)) {
            throw new RuntimeException(sprintf(
                'Hook configuration changed since it was trusted: %s. Review it, then run `agent-loop githooks trust --config=%s` again.',
                $relativeConfig,
                $relativeConfig,
            ));
        }
    }

    private static function defaultStorePath(): string
    {
        $xdgConfigHome = getenv('XDG_CONFIG_HOME');
        if (is_string($xdgConfigHome) && $xdgConfigHome !== '' && str_starts_with($xdgConfigHome, DIRECTORY_SEPARATOR)) {
            return rtrim($xdgConfigHome, DIRECTORY_SEPARATOR) . '/agent-loop/hook-trust.json';
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '' && str_starts_with($home, DIRECTORY_SEPARATOR)) {
            return rtrim($home, DIRECTORY_SEPARATOR) . '/.config/agent-loop/hook-trust.json';
        }

        throw new RuntimeException('Hook trust requires an absolute XDG_CONFIG_HOME or HOME directory.');
    }

    private static function canonicalRoot(string $repositoryRoot): string
    {
        $root = realpath($repositoryRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Repository root must resolve to an existing directory.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    /** @return array{string, string} canonical file path and checkout-relative identity */
    private function resolveConfig(string $root, string $configPath): array
    {
        if ($configPath === '' || str_contains($configPath, "\0")) {
            throw new RuntimeException('Hook configuration path is invalid.');
        }

        $candidate = str_starts_with($configPath, DIRECTORY_SEPARATOR)
            ? $configPath
            : $root . DIRECTORY_SEPARATOR . $configPath;
        $candidate = self::normalizeAbsolutePath($candidate);
        if (!self::isWithin($root, $candidate)) {
            throw new RuntimeException('Hook configuration path must stay inside the repository.');
        }

        $relative = substr($candidate, strlen($root) + 1);
        $cursor = $root;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new RuntimeException('Hook configuration path must not traverse parent directories.');
            }

            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException('Refusing hook configuration reached through a symlink: ' . $configPath);
            }
        }

        $canonical = realpath($candidate);
        if ($canonical === false || !is_file($canonical) || !self::isWithin($root, $canonical)) {
            throw new RuntimeException('Hook configuration must resolve to a regular file inside the repository.');
        }

        return [$canonical, substr($canonical, strlen($root) + 1)];
    }

    private function resolvedStorePath(string $repositoryRoot, bool $createDirectory): string
    {
        $storePath = $this->storePath ?? self::defaultStorePath();
        if (!str_starts_with($storePath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Hook trust store path must be absolute.');
        }

        $path = self::normalizeAbsolutePath($storePath);
        if (self::isWithin($repositoryRoot, $path)) {
            throw new RuntimeException('Hook trust store must be outside the repository checkout.');
        }

        $directory = dirname($path);
        self::assertNoSymlinkedPathComponents($directory);
        if ($createDirectory && !is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create external hook trust directory: ' . $directory);
            }
        }
        self::assertNoSymlinkedPathComponents($directory);

        if (!is_dir($directory)) {
            return $path;
        }

        $canonicalDirectory = realpath($directory);
        if ($canonicalDirectory === false || self::isWithin($repositoryRoot, $canonicalDirectory)) {
            throw new RuntimeException('Hook trust store must be outside the repository checkout.');
        }
        self::assertSecureStoreDirectory($canonicalDirectory);
        if (is_link($path)) {
            throw new RuntimeException('Refusing a symlinked hook trust store.');
        }
        if (file_exists($path) && !is_file($path)) {
            throw new RuntimeException('Hook trust store path must be a regular file.');
        }

        return $canonicalDirectory . DIRECTORY_SEPARATOR . basename($path);
    }

    private static function assertNoSymlinkedPathComponents(string $path): void
    {
        $cursor = DIRECTORY_SEPARATOR;
        foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
            if ($segment === '') {
                continue;
            }

            $cursor = rtrim($cursor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException('Refusing a symlinked hook trust directory ancestor: ' . $cursor);
            }
            if (!file_exists($cursor)) {
                break;
            }
        }
    }

    private static function assertSecureStoreDirectory(string $directory): void
    {
        $effectiveUserId = self::effectiveUserId();
        $cursor = DIRECTORY_SEPARATOR;
        $segments = explode(DIRECTORY_SEPARATOR, trim($directory, DIRECTORY_SEPARATOR));
        foreach ($segments as $index => $segment) {
            $cursor = rtrim($cursor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
            $owner = fileowner($cursor);
            $permissions = fileperms($cursor);
            if (!is_int($owner) || !is_int($permissions)) {
                throw new RuntimeException('Unable to verify hook trust directory ownership: ' . $cursor);
            }

            $isStoreDirectory = $index === array_key_last($segments);
            if ($isStoreDirectory && $owner !== $effectiveUserId) {
                throw new RuntimeException('Hook trust directory is not owned by the current user: ' . $cursor);
            }
            if (!$isStoreDirectory && $owner !== $effectiveUserId && $owner !== 0) {
                throw new RuntimeException('Hook trust path ancestor has an untrusted owner: ' . $cursor);
            }
            if (($permissions & 0022) !== 0 && ($permissions & 01000) === 0) {
                throw new RuntimeException('Hook trust directory is writable by other users: ' . $cursor);
            }
        }
    }

    private static function effectiveUserId(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $status = @file_get_contents('/proc/self/status');
            if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        throw new RuntimeException('Unable to verify the current user for hook trust store ownership checks.');
    }

    /** @return array{schema_version: string, repositories: array<string, array<string, string>>} */
    private function readStore(string $path): array
    {
        if (!file_exists($path)) {
            return ['schema_version' => '1.0', 'repositories' => []];
        }
        if (is_link($path)) {
            throw new RuntimeException('Refusing a symlinked hook trust store.');
        }

        $owner = fileowner($path);
        if (!is_int($owner) || $owner !== self::effectiveUserId()) {
            throw new RuntimeException('Hook trust store file is not owned by the current user: ' . $path);
        }
        $permissions = fileperms($path);
        if (!is_int($permissions) || ($permissions & 0022) !== 0) {
            throw new RuntimeException('Hook trust store is writable by other users: ' . $path);
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read hook trust store: ' . $path);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Hook trust store is malformed: ' . $path, previous: $exception);
        }

        if (!is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== '1.0'
            || !is_array($decoded['repositories'] ?? null)
        ) {
            throw new RuntimeException('Hook trust store has an unsupported schema: ' . $path);
        }

        $repositories = [];
        foreach ($decoded['repositories'] as $root => $configs) {
            if (!is_string($root) || !is_array($configs)) {
                throw new RuntimeException('Hook trust store contains an invalid repository entry.');
            }
            foreach ($configs as $config => $digest) {
                if (!is_string($config) || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                    throw new RuntimeException('Hook trust store contains an invalid config digest.');
                }
            }
            $repositories[$root] = $configs;
        }

        return ['schema_version' => '1.0', 'repositories' => $repositories];
    }

    /** @param array{schema_version: string, repositories: array<string, array<string, string>>} $store */
    private function writeStore(string $path, array $store, string $repositoryRoot): void
    {
        try {
            $content = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode hook trust store.', previous: $exception);
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to create temporary hook trust store: ' . $temporary);
        }

        try {
            try {
                if (!chmod($temporary, 0600)) {
                    throw new RuntimeException('Unable to restrict temporary hook trust store permissions: ' . $temporary);
                }
                $written = fwrite($handle, $content);
                if ($written !== strlen($content) || !fflush($handle)) {
                    throw new RuntimeException('Unable to write temporary hook trust store: ' . $temporary);
                }
            } finally {
                fclose($handle);
            }
        } catch (Throwable $exception) {
            if (!is_link($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }

            throw $exception;
        }

        if (is_link($path)) {
            unlink($temporary);
            throw new RuntimeException('Refusing a symlinked hook trust store.');
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('Unable to publish hook trust store: ' . $path);
        }
        if (!chmod($path, 0600)) {
            throw new RuntimeException('Unable to restrict hook trust store permissions: ' . $path);
        }

        $canonicalDirectory = realpath(dirname($path));
        if ($canonicalDirectory === false || self::isWithin($repositoryRoot, $canonicalDirectory)) {
            throw new RuntimeException('Hook trust store must be outside the repository checkout.');
        }
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Path must be absolute.');
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private static function isWithin(string $directory, string $path): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
