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
}
