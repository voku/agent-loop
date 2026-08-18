<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReportCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowReportCommandTest extends TestCase
{
    private string $root;
    private string $sessionPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-report-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTextReportProjectsCurrentTaskArtifacts(): void
    {
        $this->writeApprovedContract();
        $this->writeValidation(1, 'vendor/bin/phpunit tests/FooTest.php', ValidationStatus::PASSED, 0);
        $this->write('.agent-loop/recall/ABC-123/meta.json', json_encode(['task_id' => 'ABC-123', 'task_files' => ['src/Foo.php']], JSON_THROW_ON_ERROR));
        $this->write('.agent-loop/recall/ABC-123/recall-log.draft.json', '{}');
        $this->write('.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json', json_encode([
            'version' => 2,
            'task_id' => 'ABC-123',
            'status' => 'warn',
            'contract_revision' => 1,
            'implementation_snapshot' => null,
            'findings' => [[
                'id' => 'fixture_warning',
                'severity' => 'WARN',
                'message' => 'Fixture warning.',
                'evidence' => ['Report command projection fixture.'],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $this->write('.agent-loop/risks/ABC-123.accepted-risk.md', "# Accepted risk\n");

        $result = $this->runReport(['ABC-123', '--changed-file', 'src/Foo.php', '--changed-file', 'docs/Outside.md']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Workflow report: ABC-123', $result['output']);
        self::assertStringContainsString('Contract: approved revision 1 (approved by lars)', $result['output']);
        self::assertStringContainsString('Behavior anchors: request -> FooService -> persisted state', $result['output']);
        self::assertStringContainsString('Changed files outside Contract scope: docs/Outside.md', $result['output']);
        self::assertStringContainsString('[passed] vendor/bin/phpunit tests/FooTest.php via session (exit 0', $result['output']);
        self::assertStringContainsString('Verification receipt: missing', $result['output']);
        self::assertStringContainsString('Recall: present, outcome draft present', $result['output']);
        self::assertStringContainsString('Review: warn', $result['output']);
        self::assertStringContainsString('Accepted risk: recorded at .agent-loop/risks/ABC-123.accepted-risk.md', $result['output']);
    }

    public function testJsonReportSeparatesMissingAndCurrentEvidence(): void
    {
        $this->writeApprovedContract();
        $this->writeValidation(1, 'vendor/bin/phpunit tests/FooTest.php', ValidationStatus::PASSED, 0);

        $result = $this->runReport(['ABC-123', '--format', 'json']);

        self::assertSame(0, $result['exit']);
        $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.0', $report['schema_version']);
        self::assertSame('ABC-123', $report['task_id']);
        self::assertSame('approved', $report['contract']['status']);
        self::assertSame(['request -> FooService -> persisted state'], $report['contract']['behavior_anchors']);
        self::assertSame('passed', $report['validation'][0]['status']);
        self::assertSame(1, $report['validation'][0]['contract_revision']);
        self::assertSame('session', $report['validation'][0]['source']);
        self::assertSame('missing', $report['validation'][1]['status']);
        self::assertFalse($report['scope']['changed_files_supplied']);
        self::assertSame('missing', $report['verification']['status']);
        self::assertSame('missing', $report['recall']['status']);
        self::assertSame('unavailable', $report['learning']['status']);
        self::assertArrayNotHasKey('work_brief', $report);
    }

    public function testJsonReportMarksEvidenceForSupersededContractAsStale(): void
    {
        $this->writeApprovedContract();
        $this->writeValidation(1, 'vendor/bin/phpunit tests/FooTest.php', ValidationStatus::PASSED, 0);
        $contracts = new TaskContractStore($this->root);
        $contracts->revise(
            'ABC-123',
            'Keep the task scope reviewable.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit tests/FooTest.php', 'vendor/bin/phpstan analyse src/Foo.php'],
            'lars',
            behaviorAnchors: ['request -> FooService -> persisted state'],
        );

        $result = $this->runReport(['ABC-123', '--format', 'json']);
        $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('stale', $report['validation'][0]['status']);
        self::assertSame(2, $report['validation'][0]['contract_revision']);
        self::assertArrayNotHasKey('work_brief_revision', $report['validation'][0]);
        self::assertSame('candidate', $report['contract']['status']);
    }

    public function testContractRemainsInspectableWithoutSessionWorkingMemory(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Durable intent.', ['src/Foo.php'], [], ['composer ci'], 'lars');
        $contracts->approve('ABC-123', 'lars');

        $result = $this->runReport(['ABC-123', '--format', 'json']);
        $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $result['exit']);
        self::assertSame('missing', $report['session']['status']);
        self::assertSame('approved', $report['contract']['status']);
        self::assertSame('Durable intent.', $report['contract']['goal']);
        self::assertSame('missing', $report['validation'][0]['status']);
    }

    public function testInvalidInputDoesNotWriteArtifacts(): void
    {
        $before = $this->files();

        $result = $this->runReport(['ABC-123', '--format', 'yaml']);

        self::assertSame(1, $result['exit']);
        self::assertSame($before, $this->files());
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, output: string}
     */
    private function runReport(array $args): array
    {
        ob_start();
        $exit = (new WorkflowReportCommand($this->root))->run($args);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function writeApprovedContract(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Keep the task scope reviewable.',
            ['src/Foo.php'],
            ['Do not add a memory layer.'],
            ['vendor/bin/phpunit tests/FooTest.php', 'vendor/bin/phpstan analyse src/Foo.php'],
            'lars',
            behaviorAnchors: ['request -> FooService -> persisted state'],
        );
        $contracts->approve('ABC-123', 'lars');

        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $this->sessionPath = $session->path;
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, $content);
    }

    private function writeValidation(int $revision, string $command, ValidationStatus $status, int $exitCode): void
    {
        $session = (new SessionStore())->load($this->root . '/.agent-loop/sessions', basename($this->sessionPath));
        (new ValidationEvidenceStore())->record($session, $revision, $command, $status, $exitCode, 10, 'lars');
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
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
