<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\LearningDecision;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
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
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testPlanPersistsTheCandidateRunProjection(): void
    {
        $session = (new SessionStore())->create($this->root . '/session_plan', 'ABC-123', by: 'lars');
        $runner = function (array $argv) use ($session): int {
            self::assertSame(['brief', 'create'], array_slice($argv, 0, 2));
            (new WorkBriefStore())->create(
                $session,
                'Keep the task scope reviewable.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit'],
            );

            return 0;
        };

        ob_start();
        $exit = (new WorkflowPlanCommand($this->root, $runner))->run([
            'ABC-123',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Keep the task scope reviewable.',
            '--validation', 'vendor/bin/phpunit',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('run manifest refreshed', $output);
        $manifest = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($manifest);
        self::assertSame('governed', $manifest['mode']);
        self::assertSame('candidate', $manifest['references']['work_brief']['state']);
        self::assertSame('missing', $manifest['references']['approval']['state']);
    }

    public function testApproveCanResumeCompilationAfterTheBriefWasAlreadyApproved(): void
    {
        $session = $this->createApprovedSession();
        $sessionCalls = 0;
        $recallCalls = 0;

        $command = new WorkflowApproveCommand(
            $this->root,
            static function (array $argv) use (&$sessionCalls): int {
                ++$sessionCalls;

                return 99;
            },
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
        self::assertSame(0, $sessionCalls, 'the current approved revision must not be approved twice');
        self::assertSame(1, $recallCalls);
        self::assertStringContainsString('already approved; resuming recall compilation', $output);

        $manifest = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($manifest);
        self::assertSame('session:' . $session->id, $manifest['run_id']);
        self::assertSame('current', $manifest['references']['approval']['state']);
        self::assertSame('compiled', $manifest['references']['recall']['state']);
    }

    public function testFailedCompilationLeavesAnApprovedManifestThatTheSameCommandCanResume(): void
    {
        $session = (new SessionStore())->create($this->root . '/session_plan', 'ABC-123', by: 'lars');
        (new WorkBriefStore())->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $approvalCalls = 0;

        $first = new WorkflowApproveCommand(
            $this->root,
            function (array $argv) use ($session, &$approvalCalls): int {
                ++$approvalCalls;
                (new WorkBriefStore())->approve($session, 'lars');

                return 0;
            },
            static fn (array $argv): int => 7,
        );

        ob_start();
        $firstExit = $first->run(['ABC-123', '--by', 'lars']);
        $firstOutput = (string) ob_get_clean();

        self::assertSame(7, $firstExit);
        self::assertSame(1, $approvalCalls);
        self::assertStringContainsString('remains approved', $firstOutput);
        $afterFailure = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($afterFailure);
        self::assertSame('current', $afterFailure['references']['approval']['state']);
        self::assertSame('missing', $afterFailure['references']['recall']['state']);

        $second = new WorkflowApproveCommand(
            $this->root,
            static function (array $argv): int {
                throw new RuntimeException('approval must not be repeated');
            },
            function (array $argv): int {
                $this->writeRecallMeta();

                return 0;
            },
        );

        ob_start();
        $secondExit = $second->run(['ABC-123', '--by', 'lars']);
        ob_end_clean();

        self::assertSame(0, $secondExit);
        self::assertSame(1, $approvalCalls);
        $afterResume = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($afterResume);
        self::assertSame('compiled', $afterResume['references']['recall']['state']);
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

        $sessions = new SessionStore();
        $cli = new WorkflowCli(
            $this->root,
            static function (array $argv) use ($sessions, $session): int {
                self::assertSame(['close', 'ABC-123', '--status', 'done'], $argv);
                $sessions->setStatus($session, SessionStatus::DONE);

                return 0;
            },
            static fn (array $argv): int => 0,
            static fn (array $argv): int => 0,
        );

        ob_start();
        $exit = $cli->run(['close', 'ABC-123', '--status', 'done']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('final run manifest refreshed', $output);
        $manifest = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($manifest);
        self::assertSame('complete', $manifest['state']);
        self::assertSame('done', $manifest['references']['session']['state']);
        self::assertSame('none', $manifest['next_action']);
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
