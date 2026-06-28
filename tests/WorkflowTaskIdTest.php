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
        self::assertSame($taskId, (new WorkflowTaskId($taskId))->value);
    }

    #[DataProvider('invalidTaskIds')]
    public function testRejectsUnsafeTaskIds(string $taskId): void
    {
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
