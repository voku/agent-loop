<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/**
 * Turns managed-asset targets into typed drift projections.
 *
 * Fails closed by design: an unreadable manifest is reported as unreadable
 * rather than being treated as an empty one, because "we cannot tell what we
 * own here" must never license a removal.
 */
final readonly class ManagedAssetDriftProjector
{
    /** @return list<ManagedAssetDriftProjection> */
    public function project(ManagedAssetTargetCatalog $catalog, AgentAssetSourcePaths $paths): array
    {
        $projections = [];
        foreach ($catalog->targets($paths) as $target) {
            $projections[] = $this->projectTarget($target);
        }

        return $projections;
    }

    public function projectTarget(ManagedAssetTarget $target): ManagedAssetDriftProjection
    {
        $expectationFailure = $target->expectation->failure;
        if ($expectationFailure !== null && $target->hasManifest()) {
            return ManagedAssetDriftProjection::unreadable($target, $expectationFailure);
        }

        if (!$target->hasManifest()) {
            return ManagedAssetDriftProjection::missing($target);
        }

        try {
            $manifest = InitSyncManifest::load($target->targetRoot, $target->kind->value, $target->host);
        } catch (InvalidArgumentException $exception) {
            return ManagedAssetDriftProjection::unreadable($target, $exception->getMessage());
        }

        if (!$manifest->hasDriftEvidence()) {
            return ManagedAssetDriftProjection::unreadable(
                $target,
                'Manifest carries no drift evidence, so managed entries cannot be verified.',
            );
        }

        // The target expectation is the owner-computed desired set. Manifest
        // provenance never widens it: an entry the resolved config no longer
        // wants is stale here exactly as it is for sync.
        $states = ManagedAssetDriftInspector::inspect(
            $manifest,
            $target->targetRoot,
            $target->host,
            $target->desiredEntries(),
        );

        if ($target->host === 'claude' && $target->kind === ManagedAssetKind::HOOKS) {
            $states = $this->withClaudeRegistrationState($target, $states);
        }

        return ManagedAssetDriftProjection::fromStates($target, $states);
    }
    /**
     * The manifest owns the receipt file, while the receipt owns only agent-loop's
     * live handlers inside the shared project settings hooks object.
     *
     * @param array{
     *     current:list<string>,
     *     locally_modified:list<string>,
     *     stale:list<string>,
     *     incompatible:list<string>,
     *     project_owned:list<string>,
     *     unverifiable:list<string>
     * } $states
     * @return array{
     *     current:list<string>,
     *     locally_modified:list<string>,
     *     stale:list<string>,
     *     incompatible:list<string>,
     *     project_owned:list<string>,
     *     unverifiable:list<string>
     * }
     */
    private function withClaudeRegistrationState(ManagedAssetTarget $target, array $states): array
    {
        $receipt = ClaudeHookRegistrationProjector::RECEIPT_ENTRY;
        if (!in_array($receipt, $states['current'], true)
            && !in_array($receipt, $states['stale'], true)
        ) {
            return $states;
        }

        $registration = (new ClaudeHookRegistrationProjector(dirname($target->targetRoot)))->inspect();
        if ($registration['status'] === 'ready') {
            return $states;
        }

        $this->removeFromBuckets($states, $receipt);
        $bucket = $registration['status'] === 'missing' ? 'stale' : 'locally_modified';
        $states[$bucket][] = $receipt;
        sort($states[$bucket], SORT_STRING);

        return $states;
    }

    /**
     * @param array{
     *     current:list<string>,
     *     locally_modified:list<string>,
     *     stale:list<string>,
     *     incompatible:list<string>,
     *     project_owned:list<string>,
     *     unverifiable:list<string>
     * } $states
     */
    private function removeFromBuckets(array &$states, string $entry): void
    {
        foreach (array_keys($states) as $bucket) {
            $states[$bucket] = array_values(array_filter(
                $states[$bucket],
                static fn (string $candidate): bool => $candidate !== $entry,
            ));
        }
    }

}
