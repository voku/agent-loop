<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * An immutable fingerprint of everything a setup plan was computed against.
 *
 * This is what stops a stale browser page from applying an old plan. A UI
 * renders a plan together with its token; the mutation call sends the token
 * back; the owner recomputes it immediately before writing and refuses if the
 * repository moved underneath in between.
 *
 * The token covers manifest content and the current representation of every
 * entry the plan can touch, so a locally modified managed file invalidates a
 * removal plan that was computed while it was still pristine.
 */
final readonly class RepositorySetupStateToken
{
    private function __construct(public string $value)
    {
    }

    /** @param list<ManagedAssetDriftProjection> $projections */
    public static function fromDriftProjections(array $projections): self
    {
        $parts = [];
        foreach ($projections as $projection) {
            $target = $projection->target;
            $entryDigests = [];
            foreach ($projection->buckets() as $bucket => $entries) {
                foreach ($entries as $entry) {
                    $entryDigests[] = $bucket . '=' . $entry . '@'
                        . (InitSyncManifest::representationDigest($target->targetRoot, $entry) ?? 'absent');
                }
            }
            sort($entryDigests, SORT_STRING);

            $manifestPath = $target->manifestPath();
            $manifestDigest = is_file($manifestPath)
                ? (InitSyncManifest::digestPath($manifestPath) ?? 'unreadable')
                : 'absent';

            $parts[] = implode('|', [
                $target->host,
                $target->kind->value,
                $target->targetRoot,
                $projection->manifestState,
                $manifestDigest,
                implode(',', $entryDigests),
            ]);
        }

        sort($parts, SORT_STRING);

        return new self('setup-state:sha256:' . hash('sha256', implode("\n", $parts)));
    }

    public function matches(string $candidate): bool
    {
        return hash_equals($this->value, $candidate);
    }
}
