<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\AcceptedRiskWriter;

final class WorkflowActorAttributionTest extends TestCase
{
    public function testAcceptedRiskWriterRejectsReservedActorWithoutCreatingArtifacts(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-actor-attribution-' . bin2hex(random_bytes(5));
        $writer = new AcceptedRiskWriter($root);

        try {
            try {
                $writer->write('TASK-1', 'A risk.', ' standing:spoof ');
                self::fail('Reserved standing actor was accepted as risk owner.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('standing:', $exception->getMessage());
            }
            self::assertFileDoesNotExist($root . '/' . $writer->relativePath('TASK-1'));
            self::assertFileDoesNotExist($root . '/' . $writer->relativeJsonPath('TASK-1'));

            $writer->write('TASK-1', 'A risk.', ' operator-1 ');
            $record = json_decode((string) file_get_contents($root . '/' . $writer->relativeJsonPath('TASK-1')), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('operator-1', $record['accepted_by']);
        } finally {
            if (is_dir($root)) {
                exec('rm -rf ' . escapeshellarg($root));
            }
        }
    }
}
