<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Applies the removals a {@see ManagedAssetChangePlan} authorised, and nothing
 * else.
 *
 * Every removal is verified twice: the plan decided the entry was managed and
 * unchanged, and this class re-checks the manifest evidence immediately before
 * deleting. Between those two points a developer may have edited the file, and
 * that edit must win.
 *
 * Fragment entries such as `settings.json#hooks` remove a single key from a
 * host settings file. The file itself, and every other key in it — Auto Mode,
 * project trust, anything the user configured — is left exactly as it was.
 */
final readonly class ManagedAssetUninstaller
{
    /**
     * @return array{applied: list<ManagedAssetOperation>, blocked: list<ManagedAssetOperation>, messages: list<string>}
     */
    public function apply(ManagedAssetChangePlan $plan): array
    {
        $applied = [];
        $blocked = [];
        $messages = [];

        foreach ($this->groupByTarget($plan) as $group) {
            $targetRoot = $group['targetRoot'];
            $manifestPath = rtrim($targetRoot, '/') . '/' . InitSyncManifest::fileName();
            if (!is_file($manifestPath)) {
                foreach ($group['operations'] as $operation) {
                    $blocked[] = $this->block($operation, 'The manifest disappeared before removal, so ownership is unproven.');
                }

                continue;
            }

            try {
                $manifest = InitSyncManifest::load($targetRoot, $group['kind'], $group['host']);
            } catch (InvalidArgumentException $exception) {
                foreach ($group['operations'] as $operation) {
                    $blocked[] = $this->block($operation, 'The manifest became unreadable before removal: ' . $exception->getMessage());
                }

                continue;
            }

            $removed = [];
            foreach ($group['operations'] as $operation) {
                $verification = $this->verifyStillRemovable($manifest, $targetRoot, $operation->entry);
                if ($verification !== null) {
                    $blocked[] = $this->block($operation, $verification);

                    continue;
                }

                $failure = $this->removeEntry($targetRoot, $operation->entry);
                if ($failure !== null) {
                    $blocked[] = $this->block($operation, $failure);

                    continue;
                }

                $removed[] = $operation->entry;
                $applied[] = $operation;
            }

            if ($removed !== []) {
                $manifest->removeEntries($removed);
                $messages[] = 'Removed ' . count($removed) . ' managed ' . $group['kind'] . ' entrie(s) for ' . $group['host'] . '.';
            }
        }

        return ['applied' => $applied, 'blocked' => $blocked, 'messages' => $messages];
    }

    /** Returns the reason removal is no longer safe, or null when it still is. */
    private function verifyStillRemovable(InitSyncManifest $manifest, string $targetRoot, string $entry): ?string
    {
        if (!$manifest->isManaged($entry)) {
            return 'The manifest no longer claims this entry, so it is not ours to remove.';
        }

        $metadata = $manifest->entry($entry);
        if ($metadata === null || $metadata['representation_sha256'] === null) {
            return 'The manifest carries no representation evidence for this entry.';
        }
        if ($metadata['adopted']) {
            return 'This path is project-owned; repository setup never removes it.';
        }

        $current = InitSyncManifest::representationDigest($targetRoot, $entry);
        if ($current === null) {
            return 'The entry is already gone or unreadable; nothing was removed.';
        }
        if (!hash_equals($metadata['representation_sha256'], $current)) {
            return 'This managed entry changed after the plan was computed; removing it would discard that change.';
        }

        return null;
    }

    /** Returns a failure reason, or null on success. */
    private function removeEntry(string $targetRoot, string $entry): ?string
    {
        if (str_contains($entry, '#')) {
            return $this->removeJsonFragment($targetRoot, $entry);
        }

        $path = $this->containedPath($targetRoot, $entry);
        if ($path === null) {
            return 'Refusing to remove a path that resolves outside the managed target root.';
        }

        if (is_link($path)) {
            return 'Refusing to remove a symlink in a managed target root.';
        }

        if (is_dir($path)) {
            return $this->removeDirectory($path) ? null : 'The managed directory could not be fully removed.';
        }

        if (!is_file($path)) {
            return 'The entry is already gone; nothing was removed.';
        }

        return unlink($path) ? null : 'The managed file could not be removed.';
    }

    private function removeJsonFragment(string $targetRoot, string $entry): ?string
    {
        [$relativePath, $fragment] = explode('#', $entry, 2);
        if ($relativePath === '' || $fragment === '') {
            return 'The fragment entry is malformed.';
        }

        $path = $this->containedPath($targetRoot, $relativePath);
        if ($path === null) {
            return 'Refusing to edit a settings file outside the managed target root.';
        }
        if (!is_file($path)) {
            return 'The settings file is already gone; nothing was removed.';
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return 'The settings file could not be read.';
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return 'The settings file is not valid JSON, so it was left untouched: ' . $exception->getMessage();
        }
        if (!is_array($decoded)) {
            return 'The settings file does not contain a JSON object, so it was left untouched.';
        }
        if (!array_key_exists($fragment, $decoded)) {
            return 'The managed key is already absent; nothing was removed.';
        }

        unset($decoded[$fragment]);

        try {
            $json = json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            return 'The remaining settings could not be re-encoded, so nothing was changed: ' . $exception->getMessage();
        }

        return file_put_contents($path, $json) === false
            ? 'The settings file could not be written.'
            : null;
    }

    /**
     * Resolves an entry inside its target root, refusing anything that escapes.
     */
    private function containedPath(string $targetRoot, string $entry): ?string
    {
        if ($entry === '' || str_contains($entry, "\0")) {
            return null;
        }

        $root = rtrim($targetRoot, '/');
        $candidate = $root . '/' . $entry;
        $normalizedRoot = $this->normalize($root);
        $normalizedCandidate = $this->normalize($candidate);

        if ($normalizedCandidate === null || $normalizedRoot === null) {
            return null;
        }

        return str_starts_with($normalizedCandidate, $normalizedRoot . '/') ? $candidate : null;
    }

    private function normalize(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private function removeDirectory(string $path): bool
    {
        $entries = scandir($path);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_link($child)) {
                if (!unlink($child)) {
                    return false;
                }

                continue;
            }
            if (is_dir($child)) {
                if (!$this->removeDirectory($child)) {
                    return false;
                }

                continue;
            }
            if (!unlink($child)) {
                return false;
            }
        }

        return rmdir($path);
    }

    private function block(ManagedAssetOperation $operation, string $reason): ManagedAssetOperation
    {
        return new ManagedAssetOperation(
            ManagedAssetOperationKind::BLOCKED,
            $operation->host,
            $operation->kind,
            $operation->entry,
            $operation->targetPath,
            $reason,
        );
    }

    /**
     * @return list<array{host: non-empty-string, kind: string, targetRoot: string, operations: list<ManagedAssetOperation>}>
     */
    private function groupByTarget(ManagedAssetChangePlan $plan): array
    {
        $groups = [];
        foreach ($plan->operations as $operation) {
            if ($operation->operation !== ManagedAssetOperationKind::REMOVE) {
                throw new RuntimeException('The uninstaller only applies removal operations.');
            }
            $targetRoot = substr($operation->targetPath, 0, -1 * (strlen($operation->entry) + 1));
            $key = $operation->host . '|' . $operation->kind->value . '|' . $targetRoot;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'host' => $operation->host,
                    'kind' => $operation->kind->value,
                    'targetRoot' => $targetRoot,
                    'operations' => [],
                ];
            }
            $groups[$key]['operations'][] = $operation;
        }

        return array_values($groups);
    }
}
