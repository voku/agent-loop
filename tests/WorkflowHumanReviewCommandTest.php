<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanReviewCommand;
use voku\AgentRecallCompiler\Review\BlindSpotFinding;
use voku\AgentRecallCompiler\Review\ReviewReport;
use voku\AgentRecallCompiler\Review\ReviewReportWriter;
use voku\AgentRecallCompiler\Review\ReviewSeverity;

final class WorkflowHumanReviewCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testWritesDeterministicHtmlWithoutCreatingAcknowledgement(): void
    {
        if (!$this->gitAvailable()) {
            self::markTestSkipped('Git is required for human review command integration coverage.');
        }

        $root = $this->tempDir();
        $this->git($root, ['init']);
        $this->git($root, ['config', 'user.email', 'review@example.test']);
        $this->git($root, ['config', 'user.name', 'Review Test']);
        file_put_contents($root . '/reviewed.php', "<?php\n\nreturn 'before';\n");
        $this->git($root, ['add', 'reviewed.php']);
        $this->git($root, ['commit', '-m', 'base']);
        $base = trim($this->git($root, ['rev-parse', 'HEAD']));

        $contracts = new TaskContractStore($root);
        $contracts->create(
            'REVIEW-1',
            'Make the reviewed behavior safer.',
            ['reviewed.php'],
            ['No browser-side approval.'],
            ['composer test'],
            'planner',
            $base,
            acceptanceCriteria: ['Review evidence is understandable to a human.'],
        );
        $contract = $contracts->approve('REVIEW-1', 'developer');

        file_put_contents($root . '/reviewed.php', "<?php\n\nreturn '<changed>';\n");
        $snapshot = ImplementationSnapshot::capture($root, $contract);
        $report = new ReviewReport(
            taskId: 'REVIEW-1',
            findings: [new BlindSpotFinding(
                id: 'review.boundary',
                severity: ReviewSeverity::WARN,
                message: 'Confirm the human authority boundary.',
                evidence: ['reviewed.php:3'],
            )],
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
        );
        $outputDirectory = RecallOutputRoot::resolve($root) . '/REVIEW-1';
        (new ReviewReportWriter($root))->write($report, $outputDirectory);

        $command = new WorkflowHumanReviewCommand($root);
        ob_start();
        $firstExit = $command->run(['REVIEW-1']);
        $firstOutput = (string) ob_get_clean();
        self::assertSame(0, $firstExit, $firstOutput);
        self::assertStringNotContainsString('\\n', $firstOutput);
        self::assertStringEndsWith("\n", $firstOutput);
        $path = $command->path('REVIEW-1');
        self::assertFileExists($path);
        $firstHtml = file_get_contents($path);
        self::assertIsString($firstHtml);
        self::assertStringContainsString('review.boundary', $firstHtml);
        self::assertStringContainsString('&lt;changed&gt;', $firstHtml);
        self::assertStringContainsString('unacknowledged', $firstHtml);
        self::assertNull((new ReviewAcknowledgementStore($root))->find('REVIEW-1'));

        ob_start();
        $secondExit = $command->run(['REVIEW-1']);
        ob_end_clean();
        self::assertSame(0, $secondExit);
        $secondHtml = file_get_contents($path);
        self::assertSame($firstHtml, $secondHtml);
        self::assertNull((new ReviewAcknowledgementStore($root))->find('REVIEW-1'));
    }

    private function gitAvailable(): bool
    {
        $result = $this->process(getcwd() ?: '.', ['git', '--version']);

        return $result['exit'] === 0;
    }

    /** @param list<string> $args */
    private function git(string $root, array $args): string
    {
        $result = $this->process($root, ['git', ...$args]);
        self::assertSame(0, $result['exit'], $result['stderr']);

        return $result['stdout'];
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function process(string $root, array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/agent-loop-human-review-command-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o777, true));
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
        rmdir($dir);
    }
}
