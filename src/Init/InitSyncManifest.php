<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class InitSyncManifest
{
    private const string FILE_NAME = '.agent-loop-manifest.json';
    private const int LEGACY_VERSION = 1;
    private const int DRIFT_VERSION = 2;
    private const int PORTABLE_PROVENANCE_VERSION = 3;

    /**
     * @param array<string, array{source_id:string|null,semantic_owner:string|null,source_path:string|null,source_reference:string|null,source_sha256:string|null,representation_sha256:string|null,adopted:bool}> $entries
     * @param list<string> $requiredCapabilities
     */
    private function __construct(
        private readonly string $path,
        private readonly string $kind,
        private readonly string $agent,
        private array $entries,
        private array $requiredCapabilities,
        private int $version,
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
            return new self($path, $kind, $agent, [], [], self::PORTABLE_PROVENANCE_VERSION);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new InvalidArgumentException('Unable to read sync manifest: ' . $path);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid sync manifest JSON: ' . $path, 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid sync manifest shape: ' . $path);
        }
        $version = $decoded['version'] ?? null;
        if (!in_array($version, [self::LEGACY_VERSION, self::DRIFT_VERSION, self::PORTABLE_PROVENANCE_VERSION], true)) {
            throw new InvalidArgumentException('Unsupported sync manifest version: ' . $path);
        }
        if (($decoded['kind'] ?? null) !== $kind || ($decoded['agent'] ?? null) !== $agent) {
            throw new InvalidArgumentException('Sync manifest kind/agent mismatch: ' . $path);
        }

        $rawEntries = $decoded['entries'] ?? null;
        if (!is_array($rawEntries) || !array_is_list($rawEntries)) {
            throw new InvalidArgumentException('Sync manifest entries must be a list: ' . $path);
        }

        $entries = match ($version) {
            self::LEGACY_VERSION => self::parseLegacyEntries($rawEntries, $path),
            self::DRIFT_VERSION => self::parseDriftEntries($rawEntries, $path, false),
            self::PORTABLE_PROVENANCE_VERSION => self::parseDriftEntries($rawEntries, $path, true),
        };

        $requiredCapabilities = [];
        if ($version !== self::LEGACY_VERSION) {
            $rawCapabilities = $decoded['required_capabilities'] ?? [];
            if (!is_array($rawCapabilities) || !array_is_list($rawCapabilities)) {
                throw new InvalidArgumentException('Sync manifest required_capabilities must be a list: ' . $path);
            }
            foreach ($rawCapabilities as $capability) {
                if (!is_string($capability) || trim($capability) === '') {
                    throw new InvalidArgumentException('Sync manifest capability must be a non-empty string: ' . $path);
                }
                $requiredCapabilities[] = $capability;
            }
            $requiredCapabilities = array_values(array_unique($requiredCapabilities));
            sort($requiredCapabilities, SORT_STRING);
        }

        return new self($path, $kind, $agent, $entries, $requiredCapabilities, $version);
    }

    public function isManaged(string $entry): bool
    {
        return array_key_exists($entry, $this->entries);
    }

    /** @return list<string> */
    public function managedEntries(): array
    {
        $entries = array_keys($this->entries);
        sort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param list<string> $desiredEntries
     * @return list<string>
     */
    public function staleEntries(array $desiredEntries): array
    {
        $desired = array_fill_keys($desiredEntries, true);
        $stale = [];
        foreach (array_keys($this->entries) as $entry) {
            if (!isset($desired[$entry])) {
                $stale[] = $entry;
            }
        }
        sort($stale, SORT_STRING);

        return $stale;
    }

    public function hasDriftEvidence(): bool
    {
        return $this->version !== self::LEGACY_VERSION;
    }

    /** @return list<string> */
    public function requiredCapabilities(): array
    {
        return $this->requiredCapabilities;
    }

    /**
     * @return array{source_id:string|null,semantic_owner:string|null,source_path:string|null,source_reference:string|null,source_sha256:string|null,representation_sha256:string|null,adopted:bool}|null
     */
    public function entry(string $entry): ?array
    {
        return $this->entries[$entry] ?? null;
    }

    /**
     * Legacy writer retained for repository-local manifests that are not host projections.
     *
     * @param list<string> $entries
     */
    public function write(array $entries): void
    {
        $entries = array_values(array_unique($entries));
        sort($entries, SORT_STRING);

        $this->writePayload([
            'version' => self::LEGACY_VERSION,
            'kind' => $this->kind,
            'agent' => $this->agent,
            'entries' => $entries,
        ]);

        $this->entries = self::parseLegacyEntries($entries, $this->path);
        $this->requiredCapabilities = [];
        $this->version = self::LEGACY_VERSION;
    }

    /**
     * @param array<string, ManagedAssetSource> $sources target entry => semantic source
     * @param list<HostCapability> $requiredCapabilities
     * @param list<string> $adoptedEntries
     */
    public function writeProjections(array $sources, array $requiredCapabilities, array $adoptedEntries = []): void
    {
        ksort($sources, SORT_STRING);
        $adopted = array_fill_keys($adoptedEntries, true);
        $targetRoot = dirname($this->path);
        $records = [];
        $entries = [];

        foreach ($sources as $target => $source) {
            $sourceDigest = self::digestPath($source->path);
            if ($sourceDigest === null) {
                throw new InvalidArgumentException('Managed projection source is missing or unreadable: ' . $source->path);
            }
            $representationDigest = self::representationDigest($targetRoot, $target);
            if ($representationDigest === null) {
                throw new InvalidArgumentException('Managed projection target is missing or unreadable: ' . $targetRoot . '/' . $target);
            }

            $entry = [
                'source_id' => $source->id,
                'semantic_owner' => $source->owner,
                'source_path' => $source->reference === null ? $source->path : null,
                'source_reference' => $source->reference,
                'source_sha256' => $sourceDigest,
                'representation_sha256' => $representationDigest,
                'adopted' => isset($adopted[$target]),
            ];
            $entries[$target] = $entry;
            $records[] = self::projectionRecord($target, $entry, self::PORTABLE_PROVENANCE_VERSION);
        }

        $capabilityValues = array_values(array_unique(array_map(
            static fn (HostCapability $capability): string => $capability->value,
            $requiredCapabilities,
        )));
        sort($capabilityValues, SORT_STRING);

        $this->writePayload([
            'version' => self::PORTABLE_PROVENANCE_VERSION,
            'kind' => $this->kind,
            'agent' => $this->agent,
            'required_capabilities' => $capabilityValues,
            'entries' => $records,
        ]);

        $this->entries = $entries;
        $this->requiredCapabilities = $capabilityValues;
        $this->version = self::PORTABLE_PROVENANCE_VERSION;
    }

    /**
     * Drops entries from a drift-evidence manifest, keeping every other record
     * exactly as it was.
     *
     * Uninstall needs this because a partial removal must leave the retained
     * entries' ownership evidence intact — rebuilding the manifest from current
     * sources would quietly re-bless entries the caller was told were blocked.
     * Removing the last managed entry deletes the manifest, so an emptied
     * target does not keep claiming ownership of nothing.
     *
     * @param list<string> $entriesToRemove
     */
    public function removeEntries(array $entriesToRemove): void
    {
        if (!$this->hasDriftEvidence()) {
            throw new InvalidArgumentException(
                'Refusing to rewrite a manifest without drift evidence: ' . $this->path,
            );
        }

        $removal = array_fill_keys($entriesToRemove, true);
        $retained = [];
        foreach ($this->entries as $target => $entry) {
            if (isset($removal[$target])) {
                continue;
            }
            $retained[$target] = $entry;
        }

        if ($retained === []) {
            if (is_file($this->path) && !unlink($this->path)) {
                throw new InvalidArgumentException('Unable to remove emptied sync manifest: ' . $this->path);
            }
            $this->entries = [];

            return;
        }

        ksort($retained, SORT_STRING);
        $records = [];
        foreach ($retained as $target => $entry) {
            $records[] = self::projectionRecord($target, $entry, $this->version);
        }

        $this->writePayload([
            'version' => $this->version,
            'kind' => $this->kind,
            'agent' => $this->agent,
            'required_capabilities' => $this->requiredCapabilities,
            'entries' => $records,
        ]);

        $this->entries = $retained;
    }

    public static function digestPath(string $path): ?string
    {
        if (is_link($path)) {
            return null;
        }
        if (is_file($path)) {
            $hash = hash_file('sha256', $path);

            return $hash === false ? null : 'sha256:' . $hash;
        }
        if (!is_dir($path)) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $path), '/');
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isLink()) {
                return null;
            }
            if (!$item->isFile()) {
                continue;
            }
            $absolute = str_replace('\\', '/', $item->getPathname());
            if (!str_starts_with($absolute, $root . '/')) {
                return null;
            }
            $relative = substr($absolute, strlen($root) + 1);
            $hash = hash_file('sha256', $item->getPathname());
            if ($hash === false) {
                return null;
            }
            $files[$relative] = $hash;
        }
        ksort($files, SORT_STRING);

        $context = hash_init('sha256');
        foreach ($files as $relative => $hash) {
            hash_update($context, strlen($relative) . ':' . $relative . strlen($hash) . ':' . $hash);
        }

        return 'sha256:' . hash_final($context);
    }

    public static function representationDigest(string $targetRoot, string $entry): ?string
    {
        if (!str_contains($entry, '#')) {
            return self::digestPath(rtrim($targetRoot, '/') . '/' . $entry);
        }

        [$relativePath, $fragment] = explode('#', $entry, 2);
        if ($relativePath === '' || $fragment === '') {
            return null;
        }

        $path = rtrim($targetRoot, '/') . '/' . $relativePath;
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists($fragment, $decoded)) {
            return null;
        }

        return 'sha256:' . hash('sha256', serialize([$fragment => self::normalizeJson($decoded[$fragment])]));
    }

    /**
     * @param list<mixed> $rawEntries
     * @return array<string, array{source_id:null,semantic_owner:null,source_path:null,source_reference:null,source_sha256:null,representation_sha256:null,adopted:false}>
     */
    private static function parseLegacyEntries(array $rawEntries, string $path): array
    {
        $entries = [];
        foreach ($rawEntries as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException('Sync manifest entry must be a non-empty string: ' . $path);
            }
            $entries[$entry] = [
                'source_id' => null,
                'semantic_owner' => null,
                'source_path' => null,
                'source_reference' => null,
                'source_sha256' => null,
                'representation_sha256' => null,
                'adopted' => false,
            ];
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param list<mixed> $rawEntries
     * @return array<string, array{source_id:string,semantic_owner:string,source_path:string|null,source_reference:string|null,source_sha256:string,representation_sha256:string,adopted:bool}>
     */
    private static function parseDriftEntries(array $rawEntries, string $path, bool $portableProvenance): array
    {
        $entries = [];
        foreach ($rawEntries as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Sync manifest projection entry must be an object: ' . $path);
            }
            $target = $record['target'] ?? null;
            $sourceId = $record['source_id'] ?? null;
            $owner = $record['semantic_owner'] ?? null;
            $sourcePath = $record['source_path'] ?? null;
            $sourceReference = $portableProvenance ? ($record['source_reference'] ?? null) : null;
            $sourceSha = $record['source_sha256'] ?? null;
            $representationSha = $record['representation_sha256'] ?? null;
            $adopted = $record['adopted'] ?? false;
            foreach ([$target, $sourceId, $owner, $sourceSha, $representationSha] as $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException('Sync manifest projection metadata must use non-empty strings: ' . $path);
                }
            }
            if ($portableProvenance) {
                if ($sourcePath !== null && (!is_string($sourcePath) || trim($sourcePath) === '')) {
                    throw new InvalidArgumentException('Sync manifest source_path must be null or a non-empty string: ' . $path);
                }
                if ($sourceReference !== null && (!is_string($sourceReference) || trim($sourceReference) === '')) {
                    throw new InvalidArgumentException('Sync manifest source_reference must be null or a non-empty string: ' . $path);
                }
                if (($sourcePath === null) === ($sourceReference === null)) {
                    throw new InvalidArgumentException('Sync manifest projection must contain exactly one source locator: ' . $path);
                }
            } elseif (!is_string($sourcePath) || trim($sourcePath) === '') {
                throw new InvalidArgumentException('Sync manifest projection metadata must use non-empty strings: ' . $path);
            }
            if (!is_bool($adopted)) {
                throw new InvalidArgumentException('Sync manifest adopted flag must be boolean: ' . $path);
            }
            if (!str_starts_with($sourceSha, 'sha256:') || !str_starts_with($representationSha, 'sha256:')) {
                throw new InvalidArgumentException('Sync manifest projection digests must use sha256: identities: ' . $path);
            }
            if (isset($entries[$target])) {
                throw new InvalidArgumentException('Duplicate sync manifest projection target: ' . $target);
            }
            $entries[$target] = [
                'source_id' => $sourceId,
                'semantic_owner' => $owner,
                'source_path' => $sourcePath,
                'source_reference' => $sourceReference,
                'source_sha256' => $sourceSha,
                'representation_sha256' => $representationSha,
                'adopted' => $adopted,
            ];
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param array{source_id:string|null,semantic_owner:string|null,source_path:string|null,source_reference:string|null,source_sha256:string|null,representation_sha256:string|null,adopted:bool} $entry
     * @return array<string, string|bool|null>
     */
    private static function projectionRecord(string $target, array $entry, int $version): array
    {
        $record = [
            'target' => $target,
            'source_id' => $entry['source_id'],
            'semantic_owner' => $entry['semantic_owner'],
            'source_path' => $entry['source_path'],
        ];
        if ($version === self::PORTABLE_PROVENANCE_VERSION) {
            $record['source_reference'] = $entry['source_reference'];
        }
        $record['source_sha256'] = $entry['source_sha256'];
        $record['representation_sha256'] = $entry['representation_sha256'];
        $record['adopted'] = $entry['adopted'];

        return $record;
    }

    /** @param array<string, mixed> $payload */
    private function writePayload(array $payload): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create sync manifest directory: ' . $directory);
        }

        try {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode sync manifest: ' . $this->path, 0, $exception);
        }
        if (file_put_contents($this->path, $json) === false) {
            throw new InvalidArgumentException('Unable to write sync manifest: ' . $this->path);
        }
    }

    private static function normalizeJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = self::normalizeJson($item);
            }

            return $normalized;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeJson($item);
        }

        return $value;
    }
}
