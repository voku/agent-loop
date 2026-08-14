<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\SessionStore;

final class WorkflowDurableContractTest extends TestCase
{
    public function testPlanPersistsCandidateContractWithoutStartingSession(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-contract-plan-' . bin2hex(random_bytes(6));

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-123',
                '--by', 'lars',
                '--file', 'src/Foo.php',
                '--goal', 'Keep the approved task boundary durable.',
                '--non-goal', 'Do not make Session durable.',
                '--validation', 'composer ci',
                '--base-commit', 'abc123',
            ]);
            ob_end_clean();

            self::assertSame(0, $exit);
            self::assertSame([], (new SessionStore())->all($root . '/.agent-loop/sessions'));

            $contractPath = $root . '/.agent-loop/contracts/ABC-123/contract.json';
            self::assertFileExists($contractPath);
            $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($contract);
            self::assertSame('ABC-123', $contract['task_id'] ?? null);
            self::assertSame('candidate', $contract['status'] ?? null);
            self::assertSame(1, $contract['revision'] ?? null);
            self::assertSame(['src/Foo.php'], $contract['scope'] ?? null);
            self::assertSame(['composer ci'], $contract['validation'] ?? null);
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
