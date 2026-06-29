<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitSyncManifest
{
    private const string FILE_NAME = '.agent-loop-manifest.json';

    /**
     * @param list<string> $entries
     */
    private function __construct(
        private string $path,
        private string $kind,
        private string $agent,
        private array $entries,
    ) {
    }

    public static function fileName(): string
    {
        return self::FILE_NAME;
    }

    public static function load(string $targetRoot, string $kind, string $agent): self
    {
        $path = rtrim($targetRoot, '/') . '/' . self::FILE_NAME;
        if (!is_file($path)) {
            return new self($path, $kind, $agent, []);
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new InvalidArgumentException('Invalid sync manifest: ' . $path);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid sync manifest: ' . $path);
        }

        if (($decoded['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Invalid sync manifest version: ' . $path);
        }

        if (($decoded['kind'] ?? null) !== $kind || ($decoded['agent'] ?? null) !== $agent) {
            throw new InvalidArgumentException('Sync manifest kind/agent mismatch: ' . $path);
        }

        $entries = $decoded['entries'] ?? null;
        if (!is_array($entries)) {
            throw new InvalidArgumentException('Invalid sync manifest entries: ' . $path);
        }

        $normalizedEntries = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new InvalidArgumentException('Invalid sync manifest entries: ' . $path);
            }

            $normalizedEntries[] = $entry;
        }

        sort($normalizedEntries);

        return new self($path, $kind, $agent, $normalizedEntries);
    }

    public function isManaged(string $entry): bool
    {
        return in_array($entry, $this->entries, true);
    }

    /**
     * @param list<string> $desiredEntries
     * @return list<string>
     */
    public function staleEntries(array $desiredEntries): array
    {
        $staleEntries = array_values(array_diff($this->entries, $desiredEntries));
        sort($staleEntries);

        return $staleEntries;
    }

    /**
     * @param list<string> $entries
     */
    public function write(array $entries): void
    {
        $payload = [
            'version' => 1,
            'kind' => $this->kind,
            'agent' => $this->agent,
            'entries' => $entries,
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new InvalidArgumentException('Unable to encode sync manifest: ' . $this->path);
        }

        if (file_put_contents($this->path, $json . "\n") === false) {
            throw new InvalidArgumentException('Unable to write sync manifest: ' . $this->path);
        }
    }
}
