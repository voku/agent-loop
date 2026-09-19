<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LearningCaptureDispatcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-learning-capture-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/learning/findings', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCaptureDelegatesPlainHumanReportToLearningOwner(): void
    {
        $id = 'finding.2026-09-19.abc123';

        [$exit, $output] = $this->dispatch([
            'learn',
            'capture',
            '--id', $id,
            '--task', 'TEAM-123',
            '--by', 'tester',
            '--scope', 'docs/',
            '--observation', 'The visible instructions omit the approval step.',
            '--hypothesis', 'Naming the step will prevent incomplete requests.',
            '--evidence', 'Observed during a representative acceptance walkthrough.',
        ]);

        self::assertSame(0, $exit, $output);
        /** @var array{id: string, path: string, status: string, validation_status: string} $result */
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($id, $result['id']);
        self::assertSame('candidate', $result['status']);
        self::assertSame('unverified', $result['validation_status']);
        self::assertSame($this->root . '/.agent-loop/learning/findings/candidate/' . $id . '.json', $result['path']);

        $record = json_decode((string) file_get_contents($result['path']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('manual:' . $id, $record['session']);
        self::assertSame('tester', $record['created_by']);
        self::assertNull($record['validated_conclusion']);
        self::assertSame([['type' => 'human_report', 'summary' => 'Observed during a representative acceptance walkthrough.']], $record['evidence']);
    }

    public function testReviewerConclusionIsRequiredBeforeCaptureCanBecomeValidated(): void
    {
        $id = 'finding.2026-09-19.def456';
        [$captureExit, $captureOutput] = $this->dispatch([
            'learn',
            'capture',
            '--id', $id,
            '--task', 'TEAM-123',
            '--by', 'developer',
            '--observation', 'The command output does not name its validation boundary.',
            '--hypothesis', 'A visible boundary prevents premature guidance claims.',
            '--evidence', 'Observed while documenting the command behavior.',
        ]);
        self::assertSame(0, $captureExit, $captureOutput);

        [$missingConclusionExit, $missingConclusionOutput] = $this->dispatch([
            'learn',
            'finding-transition',
            $id,
            'validated',
            '--by', 'reviewer',
        ]);
        self::assertSame(1, $missingConclusionExit, $missingConclusionOutput);
        self::assertStringContainsString('requires an explicit conclusion', $missingConclusionOutput);

        [$transitionExit, $transitionOutput] = $this->dispatch([
            'learn',
            'finding-transition',
            $id,
            'validated',
            '--by', 'reviewer',
            '--conclusion', 'The observation is reproducible and ready for Learning triage.',
        ]);
        self::assertSame(0, $transitionExit, $transitionOutput);

        $record = json_decode((string) file_get_contents($this->root . '/.agent-loop/learning/findings/validated/' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('validated', $record['status']);
        self::assertSame('validated', $record['validation_status']);
        self::assertSame('reviewer', $record['validated_by']);
        self::assertSame('The observation is reproducible and ready for Learning triage.', $record['validated_conclusion']);
    }

    public function testLearnHelpAdvertisesCapture(): void
    {
        [$exit, $output] = $this->dispatch(['learn', 'help']);

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('capture --task TASK --by ACTOR --observation TEXT --hypothesis TEXT --evidence TEXT [--scope PATH]', $output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function dispatch(array $arguments): array
    {
        $command = [PHP_BINARY, '-n', __DIR__ . '/../bin/agent-loop', ...$arguments];
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            return [1, 'proc_open failed'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, trim((string) $stdout . (string) $stderr)];
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
