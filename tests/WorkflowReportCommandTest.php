<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\WorkflowReportCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

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
        $this->writeApprovedBrief();
        file_put_contents($this->sessionPath . '/validation.md', "# Validation\n\nvendor/bin/phpunit tests/FooTest.php [OK]\n");
        $this->write('recall/ABC-123/meta.json', json_encode(['task_id' => 'ABC-123', 'task_files' => ['src/Foo.php']], JSON_THROW_ON_ERROR));
        $this->write('recall/ABC-123/recall-log.draft.json', '{}');
        $this->write('.agent-recall/reviews/ABC-123.blindspots.json', json_encode(['status' => 'warn'], JSON_THROW_ON_ERROR));
        $this->write('.agent-loop/risks/ABC-123.accepted-risk.md', "# Accepted risk\n");

        $result = $this->runReport(['ABC-123', '--changed-file', 'src/Foo.php', '--changed-file', 'docs/Outside.md']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Workflow report: ABC-123', $result['output']);
        self::assertStringContainsString('Work brief: approved revision 1 (approved by lars)', $result['output']);
        self::assertStringContainsString('Changed files outside approved scope: docs/Outside.md', $result['output']);
        self::assertStringContainsString('[passed_evidence] vendor/bin/phpunit tests/FooTest.php', $result['output']);
        self::assertStringContainsString('Recall: present, outcome draft present', $result['output']);
        self::assertStringContainsString('Review: warn', $result['output']);
        self::assertStringContainsString('Accepted risk: recorded at .agent-loop/risks/ABC-123.accepted-risk.md', $result['output']);
    }

    public function testJsonReportSeparatesMissingEvidenceFromMentionedCommands(): void
    {
        $this->writeApprovedBrief();
        file_put_contents($this->sessionPath . '/validation.md', "# Validation\n\nvendor/bin/phpunit tests/FooTest.php\n");

        $result = $this->runReport(['ABC-123', '--format', 'json']);

        self::assertSame(0, $result['exit']);
        $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ABC-123', $report['task_id']);
        self::assertSame('approved', $report['work_brief']['status']);
        self::assertSame('mentioned_without_result', $report['validation'][0]['status']);
        self::assertSame('missing', $report['validation'][1]['status']);
        self::assertFalse($report['scope']['changed_files_supplied']);
        self::assertSame('missing', $report['recall']['status']);
        self::assertSame('unavailable', $report['learning']['status']);
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
     *
     * @return array{exit: int, output: string}
     */
    private function runReport(array $args): array
    {
        ob_start();
        $exit = (new WorkflowReportCommand($this->root))->run($args);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function writeApprovedBrief(): void
    {
        $session = (new SessionStore())->create($this->root . '/session_plan', 'ABC-123', by: 'lars');
        $this->sessionPath = $session->path;
        $briefs = new WorkBriefStore();
        $briefs->create(
            $session,
            'Keep the task scope reviewable.',
            ['src/Foo.php'],
            ['Do not add a memory layer.'],
            ['vendor/bin/phpunit tests/FooTest.php', 'vendor/bin/phpstan analyse src/Foo.php'],
        );
        $briefs->approve($session, 'lars');
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, $content);
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
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

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
