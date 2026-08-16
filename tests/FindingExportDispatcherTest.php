<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

final class FindingExportDispatcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-finding-export-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/learning/findings/validated', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExportsOnlyFindingsForRequestedPackageFromProjectLearningRoot(): void
    {
        $this->writeFinding('finding.2026-08-16.001', 'voku/agent-loop');
        $this->writeFinding('finding.2026-08-16.002', 'voku/agent-map');

        ob_start();
        $exit = (new Dispatcher($this->root))->run([
            'agent-loop',
            'learn',
            'finding-export',
            '--target-package',
            'voku/agent-loop',
            '--source-repository',
            'voku/httpful',
        ]);
        $output = (string) ob_get_clean();

        /**
         * @var array{
         *   kind: string,
         *   source_repository: string,
         *   target_package: string,
         *   finding_count: int,
         *   findings: list<array{record: array<string, mixed>, run_ids: list<string>}>
         * } $export
         */
        $export = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame('finding_export', $export['kind']);
        self::assertSame('voku/httpful', $export['source_repository']);
        self::assertSame('voku/agent-loop', $export['target_package']);
        self::assertSame(1, $export['finding_count']);
        self::assertCount(1, $export['findings']);
        self::assertSame('finding.2026-08-16.001', $export['findings'][0]['record']['id']);
        self::assertSame([], $export['findings'][0]['run_ids']);
    }

    public function testLearnHelpAdvertisesFindingExport(): void
    {
        ob_start();
        $exit = (new Dispatcher($this->root))->run(['agent-loop', 'learn', 'help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('finding-export --target-package PACKAGE --source-repository OWNER/REPO', $output);
    }

    private function writeFinding(string $id, string $targetPackage): void
    {
        $record = [
            'id' => $id,
            'task_id' => 'HTTPFUL-1',
            'session' => 'session-httpful-1',
            'created_at' => '2026-08-16T00:30:00+02:00',
            'created_by' => 'dogfood-agent',
            'scope' => ['tools/agent-loop'],
            'observation' => 'The host run exposed reproducible friction in an external agent package.',
            'evidence' => [[
                'type' => 'runtime_observation',
                'summary' => 'Observed during a governed consumer run.',
            ]],
            'hypothesis' => 'The friction belongs to the external package rather than host-project guidance.',
            'validated_conclusion' => 'The observation is reproducible and should be reviewable by the target package.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'target_package' => $targetPackage,
            'tested_ref' => '0.16.3',
        ];
        file_put_contents(
            $this->root . '/.agent-loop/learning/findings/validated/' . $id . '.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
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
