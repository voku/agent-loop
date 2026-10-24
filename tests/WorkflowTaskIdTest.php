<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowTaskId;

final class WorkflowTaskIdTest extends TestCase
{
    #[DataProvider('validTaskIds')]
    public function testAcceptsSafeTaskIds(string $taskId): void
    {
        self::assertTrue(WorkflowTaskId::isValid($taskId));
        self::assertSame($taskId, (new WorkflowTaskId($taskId))->value);
    }

    #[DataProvider('invalidTaskIds')]
    public function testRejectsUnsafeTaskIds(string $taskId): void
    {
        self::assertFalse(WorkflowTaskId::isValid($taskId));
        $this->expectException(InvalidArgumentException::class);

        new WorkflowTaskId($taskId);
    }

    /** @return list<array{string}> */
    public static function validTaskIds(): array
    {
        return [
            ['ABC-123'],
            ['ABC_123'],
            ['ABC.123'],
            ['A'],
            ['A-1_2.3'],
        ];
    }

    /** @return list<array{string}> */
    public static function invalidTaskIds(): array
    {
        return [
            [''],
            ['.'],
            ['-'],
            ['_'],
            ['.hidden'],
            ['../ABC-123'],
            ['../bad'],
            ['ABC/123'],
            ['bad/path'],
            ['ABC\\123'],
            ['bad\\path'],
            ['ABC..123'],
            ['ABC 123'],
        ];
    }
}
