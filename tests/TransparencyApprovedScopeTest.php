<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\Transparency\ApprovedScope;

final class TransparencyApprovedScopeTest extends TestCase
{
    public function testDirectoryScopeMatchesContainedPathsOnly(): void
    {
        $scope = ApprovedScope::fromEntries(['src', 'docs/notes.md']);

        self::assertTrue($scope->contains('src/Thing.php'));
        self::assertTrue($scope->contains('src/Deep/Nested/Thing.php'));
        self::assertTrue($scope->contains('src'));
        self::assertTrue($scope->contains('docs/notes.md'));

        self::assertFalse($scope->contains('srcx/Thing.php'));
        self::assertFalse($scope->contains('docs/notes.md.bak'));
        self::assertFalse($scope->contains('other/Thing.php'));
    }

    public function testLeadingAndTrailingSlashesDoNotChangeTheAnswer(): void
    {
        $scope = ApprovedScope::fromEntries(['/src/', 'docs']);

        self::assertTrue($scope->contains('src/Thing.php'));
        self::assertTrue($scope->contains('/src/Thing.php'));
        self::assertTrue($scope->contains('docs/'));
    }

    public function testRepositoryRootScopeContainsEverything(): void
    {
        $scope = ApprovedScope::fromEntries(['.']);

        self::assertTrue($scope->contains('anything/at/all.php'));
    }

    /**
     * A missing Contract must not read as blanket approval. Returning `true`
     * here would make every observed change look in-scope for exactly the tasks
     * that have no approved scope at all.
     */
    public function testEmptyScopeContainsNothing(): void
    {
        $scope = ApprovedScope::fromContract(null);

        self::assertSame([], $scope->entries);
        self::assertFalse($scope->contains('src/Thing.php'));
        self::assertFalse($scope->contains(''));
    }

    public function testPartitionPreservesInputOrderInBothHalves(): void
    {
        $scope = ApprovedScope::fromEntries(['src']);

        $partition = $scope->partition(['src/b.php', 'root.md', 'src/a.php', 'other/c.php']);

        self::assertSame(['src/b.php', 'src/a.php'], $partition['inside']);
        self::assertSame(['root.md', 'other/c.php'], $partition['outside']);
    }

    public function testContractScopeIsReadFromTheApprovedContract(): void
    {
        $contract = new TaskContract(
            taskId: 'SCOPE-1',
            goal: 'Bound the change.',
            scope: ['src/Workflow'],
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-08-25T00:00:00+00:00',
            updatedAt: '2026-08-25T00:00:00+00:00',
            path: '/tmp/contract.json',
            plannedBy: 'planner',
        );

        $scope = ApprovedScope::fromContract($contract);

        self::assertSame(['src/Workflow'], $scope->entries);
        self::assertTrue($scope->contains('src/Workflow/Thing.php'));
        self::assertFalse($scope->contains('src/Run/Thing.php'));
    }
}
