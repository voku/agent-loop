<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/** Computes conservative managed-asset install/uninstall plans. */
final readonly class ManagedAssetPlanner
{
    /** @param list<ManagedAssetDriftProjection> $projections */
    public function planInstall(string $agent, bool $withHooks, array $projections): ManagedAssetChangePlan
    {
        $operations = [];
        $blocked = [];

        foreach ($this->relevant($projections, $agent, $withHooks) as $projection) {
            $target = $projection->target;
            $expectation = $target->expectation;
            if (!$expectation->isKnown()) {
                $blocked[] = new ManagedAssetOperation(
                    ManagedAssetOperationKind::BLOCKED,
                    $target->host,
                    $target->kind,
                    $target->label,
                    $target->targetRoot,
                    $expectation->failure ?? 'The source definition for this target could not be read.',
                );
                continue;
            }

            $desired = array_fill_keys($expectation->entries ?? [], true);
            foreach ($projection->stale as $entry) {
                if (isset($desired[$entry])) {
                    continue;
                }
                $blocked[] = new ManagedAssetOperation(
                    ManagedAssetOperationKind::BLOCKED,
                    $target->host,
                    $target->kind,
                    $entry,
                    rtrim($target->targetRoot, '/') . '/' . $entry,
                    'This managed entry is no longer part of the desired projection. Safe install keeps its ownership evidence intact; review or remove it explicitly.',
                );
            }

            foreach ($expectation->entries ?? [] as $entry) {
                $operation = $this->installOperationFor($projection, $entry);
                if ($operation->operation === ManagedAssetOperationKind::BLOCKED) {
                    $blocked[] = $operation;
                    continue;
                }
                $operations[] = $operation;
            }
        }

        return new ManagedAssetChangePlan(
            ManagedAssetChangePlan::INTENT_INSTALL,
            $agent,
            $withHooks,
            RepositorySetupStateToken::fromDriftProjections($projections),
            $operations,
            $blocked,
        );
    }

    /** @param list<ManagedAssetDriftProjection> $projections */
    public function planUninstall(string $agent, bool $withHooks, array $projections): ManagedAssetChangePlan
    {
        $operations = [];
        $blocked = [];

        foreach ($this->relevant($projections, $agent, $withHooks) as $projection) {
            $target = $projection->target;
            if ($projection->manifestState !== ManagedAssetDriftProjection::MANIFEST_PRESENT) {
                $blocked[] = new ManagedAssetOperation(
                    ManagedAssetOperationKind::BLOCKED,
                    $target->host,
                    $target->kind,
                    $target->label,
                    $target->targetRoot,
                    $projection->manifestState === ManagedAssetDriftProjection::MANIFEST_MISSING
                        ? 'No manifest proves agent-loop owns anything here, so nothing is removable.'
                        : ($projection->failure ?? 'The manifest could not be read, so ownership is unproven.'),
                );
                continue;
            }

            foreach ($projection->current as $entry) {
                $operations[] = new ManagedAssetOperation(
                    ManagedAssetOperationKind::REMOVE,
                    $target->host,
                    $target->kind,
                    $entry,
                    rtrim($target->targetRoot, '/') . '/' . $entry,
                );
            }

            $blocked = array_values([
                ...$blocked,
                ...$this->blockedRemovals($target, $projection->locallyModified, 'This managed entry was modified locally; removing it would discard that change.'),
                ...$this->blockedRemovals($target, $projection->incompatible, 'This entry requires a host capability that is unsupported here, so its ownership cannot be verified.'),
                ...$this->blockedRemovals($target, $projection->unverifiable, 'The manifest carries no usable evidence for this entry, so ownership is unproven.'),
                ...$this->blockedRemovals($target, $projection->projectOwned, 'This path is project-owned; repository setup never removes it.'),
                ...$this->blockedRemovals($target, $projection->stale, 'This managed entry no longer matches a current source, so it is reported rather than silently deleted.'),
            ]);
        }

        return new ManagedAssetChangePlan(
            ManagedAssetChangePlan::INTENT_UNINSTALL,
            $agent,
            $withHooks,
            RepositorySetupStateToken::fromDriftProjections($projections),
            $operations,
            $blocked,
        );
    }

    private function installOperationFor(ManagedAssetDriftProjection $projection, string $entry): ManagedAssetOperation
    {
        $target = $projection->target;
        $targetPath = rtrim($target->targetRoot, '/') . '/' . $entry;
        foreach ([
            'projectOwned' => 'This path is project-owned; repository setup will not overwrite it.',
            'locallyModified' => 'This managed entry was modified locally; overwriting it would discard that change.',
            'incompatible' => 'This managed entry requires a host capability that is unsupported here.',
            'unverifiable' => 'The manifest carries no usable evidence for this entry; overwriting it would be unsafe.',
        ] as $property => $reason) {
            if (in_array($entry, $projection->{$property}, true)) {
                return new ManagedAssetOperation(
                    ManagedAssetOperationKind::BLOCKED,
                    $target->host,
                    $target->kind,
                    $entry,
                    $targetPath,
                    $reason,
                );
            }
        }

        if (in_array($entry, $projection->current, true) || in_array($entry, $projection->stale, true)) {
            return new ManagedAssetOperation(
                ManagedAssetOperationKind::UPDATE,
                $target->host,
                $target->kind,
                $entry,
                $targetPath,
            );
        }

        return new ManagedAssetOperation(ManagedAssetOperationKind::ADD, $target->host, $target->kind, $entry, $targetPath);
    }

    /**
     * @param list<string> $entries
     * @return list<ManagedAssetOperation>
     */
    private function blockedRemovals(ManagedAssetTarget $target, array $entries, string $reason): array
    {
        return array_map(
            static fn (string $entry): ManagedAssetOperation => new ManagedAssetOperation(
                ManagedAssetOperationKind::BLOCKED,
                $target->host,
                $target->kind,
                $entry,
                rtrim($target->targetRoot, '/') . '/' . $entry,
                $reason,
            ),
            $entries,
        );
    }

    /**
     * @param list<ManagedAssetDriftProjection> $projections
     * @return list<ManagedAssetDriftProjection>
     */
    private function relevant(array $projections, string $agent, bool $withHooks): array
    {
        return array_values(array_filter(
            $projections,
            static fn (ManagedAssetDriftProjection $projection): bool => $projection->target->host === $agent
                && ($withHooks || !$projection->target->kind->isOptionalExecutable()),
        ));
    }
}
