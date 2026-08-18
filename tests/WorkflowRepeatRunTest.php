<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
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

        self::assertSame(0, $this->approve());
        self::assertSame(0, $this->enter());

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

        self::assertSame(0, $this->approve());
        self::assertSame(0, $this->enter());

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

    public function testNewApprovedRevisionRetiresActiveOldSessionBeforeRunSupersession(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'SELF-SHAPE',
            'Govern the first active candidate.',
            ['src/Foo.php'],
            [],
            ['composer ci'],
            'agent-loop-self-shape',
        );

        self::assertSame(0, $this->approve());
        self::assertSame(0, $this->enter());

        $layout = new ProjectLayout($this->root);
        $sessions = new SessionStore();
        $firstSessions = $sessions->all($layout->sessionsRoot());
        self::assertCount(1, $firstSessions);
        $firstSession = $firstSessions[0];
        self::assertSame(SessionStatus::ACTIVE, $firstSession->status);

        $runs = new GovernedRunStore($this->root);
        $firstRun = $runs->find('SELF-SHAPE');
        self::assertNotNull($firstRun);
        self::assertSame(1, $firstRun->contractRevision);

        $contracts->revise(
            'SELF-SHAPE',
            'Govern the second active candidate.',
            ['src/Foo.php'],
            [],
            ['composer ci'],
            'agent-loop-self-shape',
        );
        self::assertSame(0, $this->approve());

        $stale = (new RunManifestProjector($this->root))->project('SELF-SHAPE');
        self::assertSame('incomplete', $stale->state);
        self::assertSame('agent-loop enter SELF-SHAPE', $stale->nextAction);
        self::assertSame(2, $stale->references['contract']['revision'] ?? null);
        self::assertSame(1, $stale->references['contract']['run_revision'] ?? null);

        self::assertSame(0, $this->enter());

        $retired = $sessions->load($layout->sessionsRoot(), $firstSession->id);
        self::assertSame(SessionStatus::DROPPED, $retired->status);

        $allSessions = $sessions->all($layout->sessionsRoot());
        $activeSessions = array_values(array_filter(
            $allSessions,
            static fn ($session): bool => $session->status === SessionStatus::ACTIVE,
        ));
        self::assertCount(1, $activeSessions);
        $secondSession = $activeSessions[0];
        self::assertNotSame($firstSession->id, $secondSession->id);

        $secondRun = $runs->find('SELF-SHAPE');
        self::assertNotNull($secondRun);
        self::assertNotSame($firstRun->runId, $secondRun->runId);
        self::assertSame(2, $secondRun->contractRevision);
        self::assertSame($secondSession->id, $secondRun->sessionId);

        $archivedRuns = glob($layout->runHistoryRoot('SELF-SHAPE') . '/*/run.json') ?: [];
        self::assertCount(1, $archivedRuns);
    }

    private function approve(): int
    {
        ob_start();
        try {
            return (new WorkflowApproveCommand($this->root))->run(['SELF-SHAPE', '--by', 'fixture-approver']);
        } finally {
            ob_end_clean();
        }
    }

    private function enter(): int
    {
        ob_start();
        try {
            return (new HostFrontDoorCommand(
                $this->root,
                function (array $argv): int {
                    $this->writeRecallMeta();

                    return 0;
                },
            ))->run('enter', ['SELF-SHAPE', '--format=json']);
        } finally {
            ob_end_clean();
        }
    }

    private function writeRecallMeta(): void
    {
        $directory = RecallOutputRoot::resolve($this->root) . '/SELF-SHAPE';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Recall fixture directory.');
        }
        file_put_contents(
            $directory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'SELF-SHAPE',
                'compilation_id' => 'SELF-SHAPE-' . bin2hex(random_bytes(4)),
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
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
