<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/** Read-only classification of persisted host projections against current source, target and capability truth. */
final readonly class ManagedAssetDriftInspector
{
    /**
     * @param list<string>|null $desiredEntries
     * @return array{
     *     current:list<string>,
     *     locally_modified:list<string>,
     *     stale:list<string>,
     *     incompatible:list<string>,
     *     project_owned:list<string>,
     *     unverifiable:list<string>
     * }
     */
    public static function inspect(
        InitSyncManifest $manifest,
        string $targetRoot,
        string $agent,
        ?array $desiredEntries,
    ): array {
        $states = [
            'current' => [],
            'locally_modified' => [],
            'stale' => [],
            'incompatible' => [],
            'project_owned' => [],
            'unverifiable' => [],
        ];
        $capabilitiesCompatible = self::capabilitiesCompatible($manifest, $agent);
        $desired = $desiredEntries === null ? null : array_fill_keys($desiredEntries, true);

        foreach ($manifest->managedEntries() as $entry) {
            $metadata = $manifest->entry($entry);
            if (!$manifest->hasDriftEvidence() || $metadata === null || $metadata['source_path'] === null || $metadata['source_sha256'] === null || $metadata['representation_sha256'] === null) {
                $states['unverifiable'][] = $entry;

                continue;
            }
            if ($desired !== null && !isset($desired[$entry])) {
                $states['stale'][] = $entry;

                continue;
            }
            if ($metadata['adopted']) {
                $states['project_owned'][] = $entry;

                continue;
            }
            if (!$capabilitiesCompatible) {
                $states['incompatible'][] = $entry;

                continue;
            }

            $representationDigest = InitSyncManifest::representationDigest($targetRoot, $entry);
            if ($representationDigest === null || !hash_equals($metadata['representation_sha256'], $representationDigest)) {
                $states['locally_modified'][] = $entry;

                continue;
            }

            $sourceDigest = InitSyncManifest::digestPath($metadata['source_path']);
            if ($sourceDigest === null || !hash_equals($metadata['source_sha256'], $sourceDigest)) {
                $states['stale'][] = $entry;

                continue;
            }

            $states['current'][] = $entry;
        }

        if ($desiredEntries !== null) {
            foreach ($desiredEntries as $entry) {
                if ($manifest->isManaged($entry)) {
                    continue;
                }

                if (InitSyncManifest::representationDigest($targetRoot, $entry) !== null) {
                    $states['project_owned'][] = $entry;
                }
            }
        }

        foreach (array_keys($states) as $state) {
            $states[$state] = array_values(array_unique($states[$state]));
            sort($states[$state], SORT_STRING);
        }

        return $states;
    }

    private static function capabilitiesCompatible(InitSyncManifest $manifest, string $agent): bool
    {
        foreach ($manifest->requiredCapabilities() as $capabilityId) {
            $capability = HostCapability::tryFrom($capabilityId);
            if ($capability === null || HostCapabilityMatrix::status($agent, $capability) === HostCapabilityStatus::Unsupported) {
                return false;
            }
        }

        return true;
    }
}
