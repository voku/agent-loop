<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReportCommand;

final class AcceptanceCriteriaProjectionTest extends TestCase
{
    public function testReportAndRunManifestExposeRequiredOutcomesWithoutInventingStatus(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-acceptance-projection-' . bin2hex(random_bytes(6));
        $criteria = [
            'Recall exposes the approved outcome.',
            'Workflow review keeps the outcome visible.',
        ];

        try {
            (new TaskContractStore($root))->create(
                'ACCEPT-1',
                'Keep acceptance intent visible.',
                ['src/Foo.php'],
                [],
                ['composer ci'],
                'agent',
                acceptanceCriteria: $criteria,
            );

            $report = (new WorkflowReportCommand($root))->buildReport('ACCEPT-1');
            self::assertSame($criteria, $report['contract']['acceptance_criteria'] ?? null);
            self::assertArrayNotHasKey('acceptance_status', $report['contract']);
            self::assertArrayNotHasKey('acceptance_passed', $report['contract']);

            $manifest = (new RunManifestProjector($root))->project('ACCEPT-1');
            self::assertSame($criteria, $manifest->references['contract']['acceptance_criteria'] ?? null);
            self::assertArrayNotHasKey('acceptance_status', $manifest->references['contract']);
            self::assertArrayNotHasKey('acceptance_passed', $manifest->references['contract']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
