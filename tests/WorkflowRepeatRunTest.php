<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class WorkflowRepeatRunTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-repeat-run-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testNewContractRevisionAfterPruneGetsFreshSessionIdentity(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'SELF-SHAPE',
            'Govern the first candidate.',
            ['src/Foo.php'],
            [],
            ['composer ci'],
            'agent-loop-self-shape',
        );
        $approve = new WorkflowApproveCommand($this->root, static fn (array $argv): int => 0);

        ob_start();
        self::assertSame(0, $approve->run(['SELF-SHAPE', '--by', 'fixture-approver']));
        ob_end_clean();

        $layout = new ProjectLayout($this->root);
        $sessions = new SessionStore();
        $firstSessions = $sessions->all($layout->sessionsRoot());
        self::assertCount(1, $firstSessions);
        $firstSession = $firstSessions[0];
        $firstRun = (new GovernedRunStore($this->root))->find('SELF-SHAPE');
        self::assertNotNull($firstRun);

        $sessions->setStatus($firstSession, SessionStatus::DONE);
        self::assertSame(
            [$firstSession->id],
            $sessions->prune($layout->sessionsRoot(), 0, [SessionStatus::DONE]),
        );

        $contracts->revise(
            'SELF-SHAPE',
            'Govern the second candidate.',
            ['src/Foo.php'],
            [],
            ['composer ci'],
            'agent-loop-self-shape',
        );

        ob_start();
        self::assertSame(0, $approve->run(['SELF-SHAPE', '--by', 'fixture-approver']));
        ob_end_clean();

        $secondSessions = $sessions->all($layout->sessionsRoot());
        self::assertCount(1, $secondSessions);
        $secondSession = $secondSessions[0];
        self::assertNotSame($firstSession->id, $secondSession->id);

        $secondRun = (new GovernedRunStore($this->root))->find('SELF-SHAPE');
        self::assertNotNull($secondRun);
        self::assertNotSame($firstRun->runId, $secondRun->runId);
        self::assertSame(2, $secondRun->contractRevision);
        self::assertSame($secondSession->id, $secondRun->sessionId);

        $archivedRuns = glob($layout->runHistoryRoot('SELF-SHAPE') . '/*/run.json') ?: [];
        self::assertCount(1, $archivedRuns);
        $archivedRun = json_decode((string) file_get_contents($archivedRuns[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($archivedRun);
        self::assertSame($firstRun->runId, $archivedRun['run_id'] ?? null);
        self::assertSame(1, $archivedRun['contract_revision'] ?? null);
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
