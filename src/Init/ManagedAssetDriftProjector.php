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

        return ManagedAssetDriftProjection::fromStates(
            $target,
            ManagedAssetDriftInspector::inspect(
                $manifest,
                $target->targetRoot,
                $target->host,
                $target->desiredEntries(),
            ),
        );
    }
}
