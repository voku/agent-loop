<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Support;

use RuntimeException;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\MapArtifactPaths;

/**
 * Test-only drop-in for `(new AgentMapBuilder())->build($root, $paths, $excludes)`.
 *
 * A real build starts a PHPStan process (1.5-2 s even for a two-file fixture), and many tests build the
 * same pristine fixture in a fresh temporary root. The built map is identical apart from the absolute root,
 * so it is cached by the fixture's content and re-rooted on a hit.
 *
 * The key covers the PHP files the builder would read, the paths and excludes, the backend identity and a
 * fingerprint of the installed agent-map and PHPStan, so a toolchain change never serves a stale map.
 * Set `AGENT_LOOP_TEST_MAP_CACHE=0` to bypass the cache and run every build for real.
 */
final class CachedAgentMapBuilder
{
    private const ROOT_PLACEHOLDER = '@@AGENT_LOOP_TEST_ROOT@@';

    private const KEY_VERSION = 1;

    public static int $hits = 0;

    public static int $misses = 0;

    private static ?string $toolchainFingerprint = null;

    /** @var list<string> */
    private static array $materialized = [];

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    public static function build(string $root, array $paths, array $excludes = []): AgentMapIndex
    {
        if (getenv('AGENT_LOOP_TEST_MAP_CACHE') === '0') {
            return (new AgentMapBuilder())->build($root, $paths, $excludes);
        }

        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Root directory not found: ' . $root);
        }
        $realRoot = str_replace('\\', '/', $realRoot);

        $builder = new AgentMapBuilder();
        $cacheFile = self::cacheDirectory() . '/' . self::cacheKey($builder, $realRoot, $paths, $excludes) . '.json';
        $cachedRelations = MapArtifactPaths::relationsFileFor($cacheFile);

        if (is_file($cacheFile) && is_file($cachedRelations)) {
            try {
                $index = self::readCached($cacheFile, $cachedRelations, $realRoot);
                ++self::$hits;

                return $index;
            } catch (RuntimeException) {
                // A damaged cache entry is rebuilt below.
            }
        }

        ++self::$misses;
        $map = $builder->build($root, $paths, $excludes);
        self::store($map, $cacheFile, $cachedRelations, $realRoot);

        return $map;
    }

    private static function readCached(string $cacheFile, string $cachedRelations, string $realRoot): AgentMapIndex
    {
        $directory = self::cacheDirectory() . '/materialized-' . getmypid();
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create map cache directory: ' . $directory);
        }

        $target = $directory . '/' . bin2hex(random_bytes(8)) . '.json';
        $targetRelations = MapArtifactPaths::relationsFileFor($target);
        self::publish($target, self::reroot($cacheFile, self::ROOT_PLACEHOLDER, $realRoot));
        self::publish($targetRelations, self::reroot($cachedRelations, self::ROOT_PLACEHOLDER, $realRoot));

        if (self::$materialized === []) {
            register_shutdown_function(static function (): void {
                foreach (self::$materialized as $file) {
                    @unlink($file);
                }
            });
        }
        self::$materialized[] = $target;
        self::$materialized[] = $targetRelations;

        return (new IndexReader())->read($target);
    }

    private static function store(AgentMapIndex $map, string $cacheFile, string $cachedRelations, string $realRoot): void
    {
        $directory = dirname($cacheFile);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $scratch = $directory . '/scratch-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
        $scratchRelations = MapArtifactPaths::relationsFileFor($scratch);
        try {
            (new IndexWriter())->write($map, $scratch);
            // Relations first: a reader that sees the map file always finds its relations.
            self::publish($cachedRelations, self::reroot($scratchRelations, $realRoot, self::ROOT_PLACEHOLDER));
            self::publish($cacheFile, self::reroot($scratch, $realRoot, self::ROOT_PLACEHOLDER));
        } finally {
            @unlink($scratch);
            @unlink($scratchRelations);
        }
    }

    private static function reroot(string $file, string $from, string $to): string
    {
        $content = file_get_contents($file);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read map cache file: ' . $file);
        }

        return str_replace($from, $to, $content);
    }

    /** Writes through a temporary file so parallel test workers never see a half-written entry. */
    private static function publish(string $file, string $content): void
    {
        $temporary = $file . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $content) === false || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to write map cache file: ' . $file);
        }
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    private static function cacheKey(AgentMapBuilder $builder, string $realRoot, array $paths, array $excludes): string
    {
        $files = [];
        foreach ((new PhpFileFinder())->find($realRoot, $paths, $excludes) as $relative) {
            $files[$relative] = hash_file('sha256', $realRoot . '/' . $relative);
        }
        ksort($files, SORT_STRING);

        $rootFiles = [];
        foreach (['composer.lock', 'phpstan.neon', 'phpstan.neon.dist'] as $name) {
            if (is_file($realRoot . '/' . $name)) {
                $rootFiles[$name] = hash_file('sha256', $realRoot . '/' . $name);
            }
        }

        return hash('sha256', json_encode([
            'key_version' => self::KEY_VERSION,
            'backend' => $builder->backend(),
            'toolchain' => self::toolchainFingerprint(),
            'paths' => $paths,
            'excludes' => $excludes,
            'files' => $files,
            'root_files' => $rootFiles,
        ], JSON_THROW_ON_ERROR));
    }

    /** The installed agent-map sources and the PHPStan phar, once per process. */
    private static function toolchainFingerprint(): string
    {
        if (self::$toolchainFingerprint !== null) {
            return self::$toolchainFingerprint;
        }

        $parts = [PHP_VERSION];
        $agentMap = dirname(__DIR__, 2) . '/vendor/voku/agent-map';
        if (is_dir($agentMap)) {
            $files = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($agentMap, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['php', 'neon'], true)) {
                    $files[] = $file->getPathname();
                }
            }
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $parts[] = $file . '|' . hash_file('sha256', $file);
            }
        }

        // The phar is large; size and mtime identify the installed version well enough and stay cheap.
        $phar = dirname(__DIR__, 2) . '/vendor/phpstan/phpstan/phpstan.phar';
        $parts[] = is_file($phar) ? $phar . '|' . filesize($phar) . '|' . filemtime($phar) : 'no-phpstan-phar';

        return self::$toolchainFingerprint = hash('sha256', implode("\n", $parts));
    }

    private static function cacheDirectory(): string
    {
        return sys_get_temp_dir() . '/agent-loop-test-map-cache';
    }
}
