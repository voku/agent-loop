<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\RunRetentionInventory;
use voku\AgentLoop\Workflow\WorkflowCli;

final class RunRetentionInventoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-retention-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o700, true)) {
            throw new RuntimeException('Cannot create fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEmptyRootIsNotMutated(): void
    {
        $report = (new RunRetentionInventory($this->root))->inspect();
        self::assertSame([], $report['records']);
        self::assertSame(0, $report['totals']['files']);
        self::assertFileDoesNotExist($this->root . '/.agent-loop');
    }

    public function testCurrentAndHistoryAreReportedButNeverDeclaredEligible(): void
    {
        $layout = new ProjectLayout($this->root);
        $this->writeJson($layout->runRoot('ITP-10') . '/run.json', $this->run('ITP-10', 'run:ITP-10:new'));
        $history = $layout->runHistoryRoot('ITP-10') . '/old-run';
        $this->writeJson($history . '/run.json', $this->run('ITP-10', 'run:ITP-10:old'));
        $this->writeJson($history . '/supersession.json', [
            'kind' => 'governed_run_supersession',
            'task_id' => 'ITP-10',
            'superseded_run_id' => 'run:ITP-10:old',
            'replacement_run_id' => 'run:ITP-10:new',
        ]);
        $before = $this->snapshot();
        $report = (new RunRetentionInventory($this->root))->inspect(90, new DateTimeImmutable('2026-11-12T00:00:00+00:00'));

        self::assertSame(1, $report['totals']['current']);
        self::assertSame(1, $report['totals']['history']);
        self::assertSame(3, $report['totals']['files']);
        self::assertSame(2, $report['totals']['age_threshold_met']);
        self::assertSame(['current', 'history'], array_column($report['records'], 'scope'));
        foreach ($report['records'] as $record) {
            self::assertSame('valid', $record['run_document']);
            self::assertSame('blocked', $record['retention_decision']);
            self::assertContains('cross_owner_references_unverified', $record['reasons']);
        }
        self::assertNotContains('missing_or_symlink_supersession', $report['records'][1]['reasons']);
        self::assertSame($before, $this->snapshot());
    }

    public function testMissingCorruptAndCrossOwnerEvidenceFailClosed(): void
    {
        $layout = new ProjectLayout($this->root);
        $this->writeText($layout->runRoot('ITP-20') . '/run.json', '{bad');
        $this->writeText($layout->runHistoryRoot('ITP-20') . '/old-run/verification.json', '{}');
        $before = $this->snapshot();
        $report = (new RunRetentionInventory($this->root))->inspect(0);

        self::assertContains('malformed_run_document', $report['records'][0]['reasons']);
        self::assertContains('missing_run_document', $report['records'][1]['reasons']);
        self::assertSame(0, $report['totals']['age_threshold_met']);
        self::assertSame($before, $this->snapshot());
    }

    public function testSymlinksAndWrongRunIdentityAreBlocked(): void
    {
        $layout = new ProjectLayout($this->root);
        $history = $layout->runHistoryRoot('ITP-30') . '/old-run';
        $this->writeJson($history . '/run.json', $this->run('WRONG-30', 'run:WRONG-30:old'));
        $external = $this->root . '/external.txt';
        $this->writeText($external, 'do-not-follow');
        self::assertTrue(symlink($external, $history . '/linked.txt'));

        $report = (new RunRetentionInventory($this->root))->inspect();
        self::assertContains('invalid_run_identity_or_schema', $report['records'][0]['reasons']);
        self::assertContains('symlink_evidence', $report['records'][0]['reasons']);
        self::assertSame('blocked', $report['records'][0]['retention_decision']);
        self::assertSame('do-not-follow', file_get_contents($external));
    }

    public function testCliReportsJsonAndNeverWritesArtifacts(): void
    {
        $before = $this->snapshot();
        ob_start();
        $exit = (new WorkflowCli($this->root))->run(['retention', 'report', '--format=json', '--older-than-days=30']);
        $content = (string) ob_get_clean();
        /** @var array{kind: string, policy: array{minimum_age_days: int}, records: list<mixed>} $report */
        $report = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $exit);
        self::assertSame('governed_run_retention_inventory', $report['kind']);
        self::assertSame(30, $report['policy']['minimum_age_days']);
        self::assertSame([], $report['records']);
        self::assertSame($before, $this->snapshot());
    }

    /** @return array<string, mixed> */
    private function run(string $taskId, string $runId): array
    {
        return [
            'schema_version' => '1.0', 'kind' => 'governed_run',
            'task_id' => $taskId, 'run_id' => $runId, 'contract_revision' => 1,
            'contract_source' => ['path' => 'contracts/ITP-10/contract.json', 'sha256' => 'sha256:' . str_repeat('a', 64)],
            'session_id' => 'session:fixture', 'learning_root' => null,
            'prepared_at' => '2020-01-01T00:00:00+00:00',
        ];
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->writeText($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function writeText(string $path, string $content): void
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o700, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create fixture directory.');
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Cannot write fixture.');
        }
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $name = substr($file->getPathname(), strlen($this->root) + 1);
            $files[$name] = $file->isLink() ? 'link:' . (string) readlink($file->getPathname()) : (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
