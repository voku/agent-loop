<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/** Projects contributor-level setup detail from the same resolved asset truth used for planning. */
final readonly class RepositorySetupContributorProjector
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<RepositorySetupContributor> */
    public function project(AgentAssetSourcePaths $paths): array
    {
        /** @var array<string, array{skills:int,subagents:int,instructions:int}> $counts */
        $counts = [];
        foreach (FirstPartyPackageCatalog::firstPartyOwners() as $owner) {
            if (FirstPartyPackageCatalog::packageRootForOwner($owner) !== null) {
                $counts[$owner] = ['skills' => 0, 'subagents' => 0, 'instructions' => 0];
            }
        }

        foreach ((new ManagedSkillSourceResolver($this->rootPath))->resolve($paths) as $source) {
            $this->increment($counts, $source->owner, 'skills');
        }
        foreach ((new ManagedSubagentSourceResolver($this->rootPath))->resolve($paths) as $source) {
            $this->increment($counts, $source->assetSource->owner, 'subagents');
        }

        if (isset($counts['voku/agent-loop'])) {
            ++$counts['voku/agent-loop']['instructions'];
        }
        foreach (array_keys(FirstPartyPackageCatalog::instructionFragments($this->rootPath)) as $owner) {
            $this->increment($counts, $owner, 'instructions');
        }

        $contributors = [];
        foreach ($counts as $owner => $count) {
            $contributors[] = new RepositorySetupContributor(
                owner: $owner,
                scope: $this->scope($owner),
                skillCount: $count['skills'],
                subagentCount: $count['subagents'],
                instructionCount: $count['instructions'],
            );
        }

        return $contributors;
    }

    /**
     * @param array<string, array{skills:int,subagents:int,instructions:int}> $counts
     * @param 'skills'|'subagents'|'instructions' $kind
     */
    private function increment(array &$counts, string $owner, string $kind): void
    {
        $counts[$owner] ??= ['skills' => 0, 'subagents' => 0, 'instructions' => 0];
        ++$counts[$owner][$kind];
    }

    /**
     * @return RepositorySetupContributor::SCOPE_CONSUMER
     *     |RepositorySetupContributor::SCOPE_OWNER_REPOSITORY
     *     |RepositorySetupContributor::SCOPE_PROJECT
     *     |RepositorySetupContributor::SCOPE_LOCAL
     */
    private function scope(string $owner): string
    {
        if ($owner === 'project') {
            return RepositorySetupContributor::SCOPE_PROJECT;
        }
        if ($owner === 'local') {
            return RepositorySetupContributor::SCOPE_LOCAL;
        }

        return FirstPartyPackageCatalog::isOwnerRepository($this->rootPath, $owner)
            ? RepositorySetupContributor::SCOPE_OWNER_REPOSITORY
            : RepositorySetupContributor::SCOPE_CONSUMER;
    }
}
