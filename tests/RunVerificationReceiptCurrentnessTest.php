<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentSession\SessionStore;

final class RunVerificationReceiptCurrentnessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-verification-currentness-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class FooA {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReceiptForPreviousImplementationIsProjectedAsStale(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'VERIFY-328',
            'Keep verification evidence bound to the exact implementation.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contract = $contracts->approve('VERIFY-328', 'fixture-approver');
        $snapshotA = ImplementationSnapshot::capture($this->root, $contract);

        $session = (new SessionStore())->create(
            $this->root . '/.agent-loop/sessions',
            'VERIFY-328',
            by: 'fixture-agent',
        );
        $run = (new GovernedRunStore($this->root))->prepare(
            $contract,
            $session,
            $this->root . '/.agent-loop/learning',
        );
        (new RunVerificationReceiptStore($this->root))->record(
            $run,
            $contract,
            $session,
            $snapshotA->digest,
            'satisfied',
            [[
                'command' => 'php -r "exit(0);"',
                'status' => 'passed',
                'exit_code' => 0,
                'executed_at' => '2026-09-02T00:00:00+00:00',
                'recorded_by' => 'fixture-agent',
                'duration_ms' => 1,
            ]],
        );

        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class FooB {}\n");
        $snapshotB = ImplementationSnapshot::capture($this->root, $contract);
        self::assertNotSame($snapshotA->digest, $snapshotB->digest);

        $manifest = (new RunManifestProjector($this->root))->project('VERIFY-328');
        $verification = $manifest->references['verification'];

        self::assertSame('stale', $verification['state'] ?? null);
        self::assertSame($snapshotA->digest, $verification['implementation_snapshot'] ?? null);
        self::assertSame($snapshotB->digest, $verification['current_implementation_snapshot'] ?? null);
        self::assertStringContainsString('different implementation snapshot', (string) ($verification['reason'] ?? ''));

        $persisted = (new RunVerificationReceiptStore($this->root))->find('VERIFY-328');
        self::assertNotNull($persisted);
        self::assertSame('satisfied', $persisted->verdict, 'Currentness projection must not rewrite historical evidence.');
        self::assertSame($snapshotA->digest, $persisted->implementationSnapshot);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
