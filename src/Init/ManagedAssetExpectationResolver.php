<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/** Resolves the effective managed-entry expectation from current sources plus persisted first-party provenance. */
final readonly class ManagedAssetExpectationResolver
{
    /** @var list<string> */
    private const array FIRST_PARTY_OWNERS = [
        'voku/agent-loop',
        'voku/agent-recall-compiler',
    ];

    /**
     * `init install-assets` projects package-owned assets into a consumer whose
     * repository-local source roots may legitimately contain none of them.
     * Preserve those entries only while their recorded first-party source still
     * exists and remains readable. Removed package assets therefore still become
     * stale instead of being kept alive by old manifest metadata.
     *
     * @param list<string>|null $desiredEntries
     * @return list<string>|null
     */
    public static function resolve(InitSyncManifest $manifest, ?array $desiredEntries): ?array
    {
        if ($desiredEntries === null || !$manifest->hasDriftEvidence()) {
            return $desiredEntries;
        }

        foreach ($manifest->managedEntries() as $entry) {
            $metadata = $manifest->entry($entry);
            if ($metadata === null
                || !in_array($metadata['semantic_owner'], self::FIRST_PARTY_OWNERS, true)
                || $metadata['source_path'] === null
                || InitSyncManifest::digestPath($metadata['source_path']) === null
            ) {
                continue;
            }

            $desiredEntries[] = $entry;
        }

        $desiredEntries = array_values(array_unique($desiredEntries));
        sort($desiredEntries, SORT_STRING);

        return $desiredEntries;
    }
}
