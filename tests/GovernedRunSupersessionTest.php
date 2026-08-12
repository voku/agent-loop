<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class GovernedRunSupersessionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-run-supersession-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testApprovedContractRevisionSupersedesClosedRunWithoutDeletingItsEvidence(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Fix the original bounded defect.',
            ['src/Foo.php'],
            [],
            ['composer ci'],
            'lars',
        );
        $firstContract = $contracts->approve('ABC-123', 'lars');

        $layout = new ProjectLayout($this->root);
        $sessions = new SessionStore();
        $firstSession = $sessions->create($layout->sessionsRoot(), 'ABC-123', null, 'lars');
        $runs = new GovernedRunStore($this->root);
        $firstRun = $runs->prepare($firstContract, $firstSession, $layout->learningRoot());

        $evidencePath = dirname($firstRun->path) . '/verification.json';
        file_put_contents($evidencePath, "{\"run_id\":\"{$firstRun->runId}\"}\n");
        $sessions->setStatus($firstSession, SessionStatus::DROPPED);

        $contracts->revise(
            'ABC-123',
            'Fix the bounded defect with the newly discovered scope.',
            ['src/Foo.php', 'src/Bar.php'],
            [],
            ['composer ci'],
            'lars',
        );
        $secondContract = $contracts->approve('ABC-123', 'lars');
        $secondSession = $sessions->create($layout->sessionsRoot(), 'ABC-123', null, 'lars');

        $secondRun = $runs->prepare($secondContract, $secondSession, $layout->learningRoot());

        self::assertNotSame($firstRun->runId, $secondRun->runId);
        self::assertSame(1, $firstRun->contractRevision);
        self::assertSame(2, $secondRun->contractRevision);
        self::assertSame($secondSession->id, $secondRun->sessionId);
        self::assertSame($secondRun->runId, $runs->find('ABC-123')?->runId);

        $archivedRuns = glob($layout->runHistoryRoot('ABC-123') . '/*/run.json') ?: [];
        self::assertCount(1, $archivedRuns);
        $archivedRun = json_decode((string) file_get_contents($archivedRuns[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($archivedRun);
        self::assertSame($firstRun->runId, $archivedRun['run_id'] ?? null);
        self::assertSame(1, $archivedRun['contract_revision'] ?? null);

        $archiveDirectory = dirname($archivedRuns[0]);
        self::assertFileExists($archiveDirectory . '/verification.json');
        self::assertFileExists($archiveDirectory . '/supersession.json');
        $supersession = json_decode(
            (string) file_get_contents($archiveDirectory . '/supersession.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($supersession);
        self::assertSame($firstRun->runId, $supersession['superseded_run_id'] ?? null);
        self::assertSame($secondRun->runId, $supersession['replacement_run_id'] ?? null);
        self::assertSame(1, $supersession['superseded_contract_revision'] ?? null);
        self::assertSame(2, $supersession['replacement_contract_revision'] ?? null);
        self::assertSame('lars', $supersession['approved_by'] ?? null);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
