<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\LearningDecision;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;
use voku\AgentSession\WorkBriefStore;

final class WorkflowRunManifestTransitionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-transition-manifest-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        mkdir($this->root . '/learning-root');
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testPlanPersistsTheCandidateRunProjection(): void
    {
        ob_start();
        $exit = (new WorkflowPlanCommand($this->root))->run([
            'ABC-123',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Keep the task scope reviewable.',
            '--validation', 'vendor/bin/phpunit',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('run manifest refreshed', $output);
        $manifest = $this->manifest();
        self::assertSame('governed', $this->stringField($manifest, 'mode'));
        self::assertSame('candidate', $this->referenceState($manifest, 'work_brief'));
        self::assertSame('missing', $this->referenceState($manifest, 'approval'));
    }

    public function testApproveCanResumeCompilationAfterTheBriefWasAlreadyApproved(): void
    {
        $session = $this->createApprovedSession();
        $recallCalls = 0;

        $command = new WorkflowApproveCommand(
            $this->root,
            function (array $argv) use (&$recallCalls): int {
                ++$recallCalls;
                $this->writeRecallMeta();

                return 0;
            },
        );

        ob_start();
        $exit = $command->run(['ABC-123', '--by', 'lars']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertSame(1, $recallCalls);
        self::assertStringContainsString('already approved; resuming recall compilation', $output);

        $manifest = $this->manifest();
        self::assertSame('session:' . $session->id, $this->stringField($manifest, 'run_id'));
        self::assertSame('current', $this->referenceState($manifest, 'approval'));
        self::assertSame('compiled', $this->referenceState($manifest, 'recall'));
    }

    public function testFailedCompilationLeavesAnApprovedManifestThatTheSameCommandCanResume(): void
    {
        $session = (new SessionStore())->create($this->root . '/session_plan', 'ABC-123', by: 'lars');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);

        $first = new WorkflowApproveCommand(
            $this->root,
            static fn (array $argv): int => 7,
        );

        ob_start();
        $firstExit = $first->run(['ABC-123', '--by', 'lars']);
        ob_end_clean();

        self::assertSame(7, $firstExit);
        self::assertSame(1, $briefs->approval($session)?->workBriefRevision);
        $afterFailure = $this->manifest();
        self::assertSame('current', $this->referenceState($afterFailure, 'approval'));
        self::assertSame('missing', $this->referenceState($afterFailure, 'recall'));

        $second = new WorkflowApproveCommand(
            $this->root,
            function (array $argv): int {
                $this->writeRecallMeta();

                return 0;
            },
        );

        ob_start();
        $secondExit = $second->run(['ABC-123', '--by', 'lars']);
        ob_end_clean();

        self::assertSame(0, $secondExit);
        self::assertSame('compiled', $this->referenceState($this->manifest(), 'recall'));
    }

    public function testSuccessfulCliClosePersistsTheFinalProjection(): void
    {
        $session = $this->createApprovedSession();
        (new ValidationEvidenceStore())->record(
            $session,
            1,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            10,
            'lars',
        );
        (new LearningDecisionStore())->decide($session, LearningDecision::NO_DURABLE_LEARNING, 'lars');
        $this->writeRecallMeta();
        mkdir($this->root . '/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
        );

        $cli = new WorkflowCli(
            $this->root,
            static fn (array $argv): int => 0,
        );

        ob_start();
        $exit = $cli->run(['close', 'ABC-123', '--status', 'done']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('final run manifest refreshed', $output);
        $manifest = $this->manifest();
        self::assertSame('complete', $this->stringField($manifest, 'state'));
        self::assertSame('done', $this->referenceState($manifest, 'session'));
        self::assertSame('none', $this->stringField($manifest, 'next_action'));
    }

    private function createApprovedSession(): Session
    {
        $session = (new SessionStore())->create($this->root . '/session_plan', 'ABC-123', by: 'lars');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');

        return $session;
    }

    private function writeRecallMeta(): void
    {
        if (!is_dir($this->root . '/recall/ABC-123')) {
            mkdir($this->root . '/recall/ABC-123', 0o775, true);
        }
        file_put_contents(
            $this->root . '/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-001',
                'selected_guidance' => [],
                'selected_constraints' => [],
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifest = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function stringField(array $manifest, string $key): string
    {
        $value = $manifest[$key] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /** @param array<string, mixed> $manifest */
    private function referenceState(array $manifest, string $referenceName): string
    {
        $references = $manifest['references'] ?? null;
        self::assertIsArray($references);
        $reference = $references[$referenceName] ?? null;
        self::assertIsArray($reference);
        $state = $reference['state'] ?? null;
        self::assertIsString($state);

        return $state;
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
