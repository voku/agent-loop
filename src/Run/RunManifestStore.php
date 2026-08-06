<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use JsonException;
use RuntimeException;

final readonly class RunManifestStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function path(string $taskId): string
    {
        return rtrim($this->rootPath, '/') . '/.agent-loop/runs/' . $taskId . '/manifest.json';
    }

    public function write(RunManifest $manifest): string
    {
        $path = $this->path($manifest->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create run-manifest directory: ' . $directory);
        }

        $temporary = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $manifest->toJson(), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary run manifest: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException('Unable to publish run manifest: ' . $path);
        }

        return $path;
    }

    /** @return array<string, mixed>|null */
    public function read(string $taskId): ?array
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid run manifest JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid run manifest in ' . $path . '.');
        }
        if (($decoded['schema_version'] ?? null) !== RunManifest::SCHEMA_VERSION) {
            throw new RuntimeException('Unsupported run-manifest schema version in ' . $path . '.');
        }

        return $decoded;
    }

    /**
     * @return array{state: 'missing'|'current'|'stale', path: string, current_sha256: string, stored_sha256: string|null}
     */
    public function status(RunManifest $manifest): array
    {
        $currentJson = $manifest->toJson();
        $currentSha = hash('sha256', $currentJson);
        $stored = $this->read($manifest->taskId);
        if ($stored === null) {
            return [
                'state' => 'missing',
                'path' => $this->relativePath($this->path($manifest->taskId)),
                'current_sha256' => 'sha256:' . $currentSha,
                'stored_sha256' => null,
            ];
        }

        $storedJson = CanonicalJson::pretty($stored);
        $storedSha = hash('sha256', $storedJson);

        return [
            'state' => hash_equals($storedSha, $currentSha) ? 'current' : 'stale',
            'path' => $this->relativePath($this->path($manifest->taskId)),
            'current_sha256' => 'sha256:' . $currentSha,
            'stored_sha256' => 'sha256:' . $storedSha,
        ];
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/');
        $normalized = str_replace('\\', '/', $path);
        if ($root !== '' && str_starts_with($normalized, $root . '/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $normalized;
    }
}
