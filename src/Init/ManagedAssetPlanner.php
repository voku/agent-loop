<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * Computes what install and uninstall would do, without doing any of it.
 *
 * The removal rules are the reason this class exists, and they are all
 * conservative:
 *
 *  - managed and unchanged  -> may be removed
 *  - managed but locally modified -> blocked, because the change is someone's work
 *  - incompatible or unverifiable -> blocked, because we cannot prove ownership
 *  - project-owned / adopted -> never touched
 *  - unreadable or missing manifest -> nothing is removable at all
 *  - hooks -> only when the caller explicitly asked for them
 *
 * Anything not provably ours stays. That asymmetry is deliberate: the cost of
 * leaving a file behind is a stale file, and the cost of the opposite is
 * someone's uncommitted work.
 */
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

            foreach ($expectation->entries ?? [] as $entry) {
                $operation = $this->installOperationFor($projection, $entry);
                if ($operation === null) {
                    continue;
                }
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
                // No manifest, or one we cannot read: there is no evidence of
                // ownership, so there is nothing this command may delete. Fail
                // closed rather than globbing the host directory.
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

            $blocked = [
                ...$blocked,
                ...$this->blockedRemovals($target, $projection->locallyModified, 'This managed entry was modified locally; removing it would discard that change.'),
                ...$this->blockedRemovals($target, $projection->incompatible, 'This entry requires a host capability that is unsupported here, so its ownership cannot be verified.'),
                ...$this->blockedRemovals($target, $projection->unverifiable, 'The manifest carries no usable evidence for this entry, so ownership is unproven.'),
                ...$this->blockedRemovals($target, $projection->projectOwned, 'This path is project-owned; repository setup never removes it.'),
                ...$this->blockedRemovals($target, $projection->stale, 'This managed entry no longer matches a current source, so it is reported rather than silently deleted.'),
            ];
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

    private function installOperationFor(ManagedAssetDriftProjection $projection, string $entry): ?ManagedAssetOperation
    {
        $target = $projection->target;
        $targetPath = rtrim($target->targetRoot, '/') . '/' . $entry;

        if (in_array($entry, $projection->projectOwned, true)) {
            return new ManagedAssetOperation(
                ManagedAssetOperationKind::BLOCKED,
                $target->host,
                $target->kind,
                $entry,
                $targetPath,
                'This path is project-owned; repository setup will not overwrite it.',
            );
        }

        if (in_array($entry, $projection->locallyModified, true)) {
            return new ManagedAssetOperation(
                ManagedAssetOperationKind::BLOCKED,
                $target->host,
                $target->kind,
                $entry,
                $targetPath,
                'This managed entry was modified locally; overwriting it would discard that change.',
            );
        }

        if (in_array($entry, $projection->incompatible, true)) {
            return new ManagedAssetOperation(
                ManagedAssetOperationKind::BLOCKED,
                $target->host,
                $target->kind,
                $entry,
                $targetPath,
                'This managed entry requires a host capability that is unsupported here.',
            );
        }

        if (in_array($entry, $projection->unverifiable, true)) {
            return new ManagedAssetOperation(
                ManagedAssetOperationKind::BLOCKED,
                $target->host,
                $target->kind,
                $entry,
                $targetPath,
                'The manifest carries no usable evidence for this entry; overwriting it would be unsafe.',
            );
        }

        if (in_array($entry, $projection->current, true)) {
            return null;
        }

        if (in_array($entry, $projection->stale, true)) {
            return new ManagedAssetOperation(
                ManagedAssetOperationKind::UPDATE,
                $target->host,
                $target->kind,
                $entry,
                $targetPath,
            );
        }

        return new ManagedAssetOperation(
            ManagedAssetOperationKind::ADD,
            $target->host,
            $target->kind,
            $entry,
            $targetPath,
        );
    }

    /**
     * @param list<string> $entries
     * @return list<ManagedAssetOperation>
     */
    private function blockedRemovals(ManagedAssetTarget $target, array $entries, string $reason): array
    {
        $operations = [];
        foreach ($entries as $entry) {
            $operations[] = new ManagedAssetOperation(
                ManagedAssetOperationKind::BLOCKED,
                $target->host,
                $target->kind,
                $entry,
                rtrim($target->targetRoot, '/') . '/' . $entry,
                $reason,
            );
        }

        return $operations;
    }

    /**
     * Optional executable hooks are only in scope when the caller asked for
     * them, whichever direction the operation runs in.
     *
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
