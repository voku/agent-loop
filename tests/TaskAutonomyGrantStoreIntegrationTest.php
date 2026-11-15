<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskAutonomyGrant;
use voku\AgentLoop\Workflow\TaskAutonomyGrantStore;
use voku\AgentLoop\Workflow\TaskContractStore;

final class TaskAutonomyGrantStoreIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-autonomy-' . bin2hex(random_bytes(8));
        if (!mkdir($this->root, 0o700) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create isolated autonomy test root.');
        }
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testPersistedOwnerContractRevisionInvalidatesOldAutonomyRequest(): void
    {
        $contracts = new TaskContractStore($this->root);
        $created = $contracts->create(
            'WORK-1',
            'Repair and review the approved source.',
            ['src'],
            ['merge', 'deploy'],
            ['vendor/bin/phpunit'],
            'planner',
        );

        $approved = $contracts->approve('WORK-1', 'project-owner', $created->revision);
        $now = new DateTimeImmutable('now');
        $grant = TaskAutonomyGrant::request($approved, 'project-owner', $now, $now->modify('+30 minutes'));

        self::assertTrue($grant->isEligibleFor($contracts->load('WORK-1'), 'review', $now));

        $revised = $contracts->revise(
            'WORK-1',
            'Repair and review source and configuration.',
            ['src', 'config'],
            ['merge', 'deploy'],
            ['vendor/bin/phpunit'],
            'planner',
        );

        self::assertFalse($grant->isEligibleFor($contracts->load('WORK-1'), 'implement', $now));
        $contracts->approve('WORK-1', 'project-owner', $revised->revision);
        self::assertFalse($grant->isEligibleFor($contracts->load('WORK-1'), 'review', $now));

        self::assertFalse($grant->isEligibleFor($approved, 'learn', $now));
        self::assertFalse($grant->isEligibleFor($approved, 'acknowledge_review', $now));
    }

    public function testPersistedGrantIsRecheckedAgainstContractAndRevocation(): void
    {
        $contracts = new TaskContractStore($this->root);
        $created = $contracts->create(
            'WORK-2',
            'Implement and validate an approved change.',
            ['src'],
            ['merge', 'deploy'],
            ['vendor/bin/phpunit'],
            'planner',
        );
        $contracts->approve('WORK-2', 'project-owner', $created->revision);
        $now = new DateTimeImmutable('now');
        $store = new TaskAutonomyGrantStore($this->root);
        $issued = $store->issue('WORK-2', 'project-owner', $now, $now->modify('+30 minutes'));

        self::assertSame($issued->contractDigest, $store->find('WORK-2')?->contractDigest);
        self::assertTrue($store->currentEligibility('WORK-2', 'review', $now));
        self::assertFalse($store->currentEligibility('WORK-2', 'learn', $now));

        $revoked = $store->revoke('WORK-2', 'project-owner', $now->modify('+1 minute'));
        self::assertSame('project-owner', $revoked->revokedBy);
        $persistedRevocation = $store->find('WORK-2');
        self::assertSame('project-owner', $persistedRevocation->revokedBy);
        self::assertFalse($store->currentEligibility('WORK-2', 'review', $now->modify('+2 minutes')));

        $store->issue('WORK-2', 'project-owner', $now->modify('+2 minutes'), $now->modify('+20 minutes'));
        self::assertCount(1, glob($this->root . '/.agent-loop/runs/WORK-2/task-autonomy-grants/archive/*.json') ?: []);
    }

    public function testGrantCannotBeIssuedWithoutCurrentContractApproval(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('WORK-3', 'Implement an approved change.', ['src'], [], ['vendor/bin/phpunit'], 'planner');

        $this->expectException(RuntimeException::class);
        (new TaskAutonomyGrantStore($this->root))->issue(
            'WORK-3',
            'project-owner',
            new DateTimeImmutable('now'),
            new DateTimeImmutable('+30 minutes'),
        );
    }
}
