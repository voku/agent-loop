<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\InitDoctorCommand;
use voku\AgentLoop\Init\ManagedAssetDriftProjection;
use voku\AgentLoop\Init\RepositorySetupDiagnostic;
use voku\AgentLoop\Init\RepositorySetupDiagnosticKind;
use voku\AgentLoop\Init\RepositorySetupDiagnosticLevel;
use voku\AgentLoop\Init\RepositorySetupService;

/**
 * @internal
 */
final class RepositorySetupDiagnosticsTest extends TestCase
{
    private string $root;

    private string $binRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-diagnostics-' . bin2hex(random_bytes(6));
        $this->binRoot = $this->root . '/bin';
        if (!mkdir($this->binRoot, 0o775, true) && !is_dir($this->binRoot)) {
            throw new RuntimeException('Unable to create diagnostics fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDiagnosticsExposeTypedLevelKindAndFactsWithoutParsingText(): void
    {
        $diagnostics = $this->service()->diagnostics();

        $php = $diagnostics->byKind(RepositorySetupDiagnosticKind::PHP_RUNTIME);
        self::assertCount(1, $php);
        self::assertSame(RepositorySetupDiagnosticLevel::OK, $php[0]->level);
        self::assertSame(\PHP_VERSION, $php[0]->facts['version'] ?? null);
        self::assertSame('8.3.0', $php[0]->facts['minimum'] ?? null);
    }

    public function testMissingComposerIsATypedWarningNotJustAMessage(): void
    {
        $composer = $this->service()->diagnostics()->byKind(RepositorySetupDiagnosticKind::COMPOSER);

        self::assertCount(1, $composer);
        self::assertSame(RepositorySetupDiagnosticLevel::WARN, $composer[0]->level);
        self::assertSame('missing', $composer[0]->facts['state'] ?? null);
        self::assertTrue($composer[0]->level->needsAction());
    }

    public function testHostCapabilitiesAreScopedToTheirHost(): void
    {
        $diagnostics = $this->service()->diagnostics();

        $codex = $diagnostics->forHost('codex');
        self::assertNotSame([], $codex);
        foreach ($codex as $diagnostic) {
            self::assertSame('codex', $diagnostic->host);
        }

        $runtimes = $diagnostics->byKind(RepositorySetupDiagnosticKind::HOST_RUNTIME);
        $hosts = array_map(
            static fn (RepositorySetupDiagnostic $diagnostic): ?string => $diagnostic->host,
            $runtimes,
        );
        self::assertContains('codex', $hosts);
        self::assertContains('claude', $hosts);
    }

    public function testManagedAssetDriftIsReportedForEveryHostTarget(): void
    {
        $drift = $this->service()->diagnostics()->byKind(RepositorySetupDiagnosticKind::MANAGED_ASSET_DRIFT);

        self::assertNotSame([], $drift);
        foreach ($drift as $diagnostic) {
            self::assertNotNull($diagnostic->host);
            self::assertArrayHasKey('kind', $diagnostic->facts);
            self::assertArrayHasKey('manifest_state', $diagnostic->facts);
        }
    }

    public function testAbsentManifestIsInformationalRatherThanAFailure(): void
    {
        foreach ($this->service()->managedAssetDrift() as $projection) {
            self::assertSame(ManagedAssetDriftProjection::MANIFEST_MISSING, $projection->manifestState);
            self::assertFalse($projection->hasDrift());
            self::assertFalse($projection->blockingRemoval());
        }
    }

    public function testUnreadableManifestFailsClosedAndBlocksRemoval(): void
    {
        $skillsRoot = $this->root . '/.codex/skills';
        if (!mkdir($skillsRoot, 0o775, true) && !is_dir($skillsRoot)) {
            throw new RuntimeException('Unable to create fixture skills root.');
        }
        file_put_contents($skillsRoot . '/.agent-loop-manifest.json', '{ this is not valid json');

        $projections = array_values(array_filter(
            $this->service()->managedAssetDrift(),
            static fn (ManagedAssetDriftProjection $projection): bool => $projection->target->host === 'codex'
                && $projection->target->kind->value === 'skills',
        ));

        self::assertCount(1, $projections);
        self::assertSame(ManagedAssetDriftProjection::MANIFEST_UNREADABLE, $projections[0]->manifestState);
        self::assertTrue($projections[0]->blockingRemoval());
        self::assertNotNull($projections[0]->failure);
    }

    public function testDoctorRendersExactlyTheTypedDiagnostics(): void
    {
        $service = $this->service();
        $expected = [];
        foreach ($service->diagnostics()->diagnostics as $diagnostic) {
            $expected[] = $diagnostic->render();
        }

        ob_start();
        $exit = (new InitDoctorCommand($this->root, $this->probe()))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        foreach ($expected as $line) {
            self::assertStringContainsString($line, $output);
        }
    }

    public function testDiagnosticsNeverMutateTheRepository(): void
    {
        $before = $this->snapshot($this->root);
        $this->service()->diagnostics();
        $this->service()->managedAssetDrift();

        self::assertSame($before, $this->snapshot($this->root));
    }

    public function testWorstLevelSummarisesWithoutHidingIndividualFacts(): void
    {
        $diagnostics = $this->service()->diagnostics();

        self::assertSame(RepositorySetupDiagnosticLevel::WARN, $diagnostics->worstLevel());
        self::assertNotSame([], $diagnostics->needingAction());
        self::assertGreaterThan(count($diagnostics->needingAction()), count($diagnostics->diagnostics));
    }

    private function service(): RepositorySetupService
    {
        return new RepositorySetupService($this->root, $this->probe());
    }

    private function probe(): HostRuntimeProbe
    {
        return new HostRuntimeProbe($this->binRoot, DIRECTORY_SEPARATOR === '\\' ? '.EXE' : null);
    }

    /** @return list<string> */
    private function snapshot(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            $entries[] = $item->getPathname() . ':' . ($item->isFile() ? (string) $item->getSize() : 'dir');
        }
        sort($entries, SORT_STRING);

        return $entries;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
