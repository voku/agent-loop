<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLoop\Init\HostCapability;
use voku\AgentLoop\Init\InitSyncManifest;
use voku\AgentLoop\Init\ManagedAssetDriftInspector;
use voku\AgentLoop\Init\ManagedAssetExpectationResolver;
use voku\AgentLoop\Init\ManagedAssetSource;

/**
 * @internal
 */
final class ManagedAssetPortableProvenanceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-portable-provenance-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFirstPartyProjectionPersistsPortableReferenceAndSurvivesPathRelocation(): void
    {
        $sourcePath = dirname(__DIR__) . '/src/Init/ManagedAssetSource.php';
        $targetRoot = $this->root . '/.claude/skills';
        $target = 'managed-source.php';
        mkdir($targetRoot, 0o775, true);
        copy($sourcePath, $targetRoot . '/' . $target);

        $source = ManagedAssetSource::fromPath($this->root, $sourcePath, 'skill:managed-source');
        $manifest = InitSyncManifest::load($targetRoot, 'skills', 'claude');
        $manifest->writeProjections([$target => $source], [HostCapability::SkillProjection]);

        $payload = json_decode(
            (string) file_get_contents($targetRoot . '/' . InitSyncManifest::fileName()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(3, $payload['version']);
        self::assertNull($payload['entries'][0]['source_path']);
        self::assertSame('src/Init/ManagedAssetSource.php', $payload['entries'][0]['source_reference']);
        self::assertStringNotContainsString(dirname(__DIR__), json_encode($payload['entries'][0], JSON_THROW_ON_ERROR));

        $reloaded = InitSyncManifest::load($targetRoot, 'skills', 'claude');
        self::assertSame([$target], ManagedAssetExpectationResolver::resolve($reloaded, []));

        $states = ManagedAssetDriftInspector::inspect($reloaded, $targetRoot, 'claude', [$target]);
        self::assertSame([$target], $states['current']);
        self::assertSame([], $states['stale']);
        self::assertSame([], $states['unverifiable']);
    }

    public function testPackageRootProjectionUsesExplicitPortableRootReference(): void
    {
        $packageRoot = dirname(__DIR__);
        $source = ManagedAssetSource::fromPath($this->root, $packageRoot, 'skill:package-root');

        self::assertSame('voku/agent-loop', $source->owner);
        self::assertSame('.', $source->reference);
        self::assertSame(
            realpath($packageRoot),
            ManagedAssetSource::resolvePersistedPath($source->owner, $source->reference, null),
        );
    }

    public function testPortableReferenceStillDetectsSourceDigestDrift(): void
    {
        $sourcePath = dirname(__DIR__) . '/src/Init/ManagedAssetSource.php';
        $targetRoot = $this->root . '/.claude/skills';
        $target = 'managed-source.php';
        mkdir($targetRoot, 0o775, true);
        copy($sourcePath, $targetRoot . '/' . $target);

        $source = ManagedAssetSource::fromPath($this->root, $sourcePath, 'skill:managed-source');
        $manifest = InitSyncManifest::load($targetRoot, 'skills', 'claude');
        $manifest->writeProjections([$target => $source], [HostCapability::SkillProjection]);

        $manifestPath = $targetRoot . '/' . InitSyncManifest::fileName();
        $payload = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $payload['entries'][0]['source_sha256'] = 'sha256:' . str_repeat('0', 64);
        file_put_contents($manifestPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $reloaded = InitSyncManifest::load($targetRoot, 'skills', 'claude');
        $states = ManagedAssetDriftInspector::inspect($reloaded, $targetRoot, 'claude', [$target]);

        self::assertSame([], $states['current']);
        self::assertSame([$target], $states['stale']);
    }

    public function testVersionTwoPathProvenanceRemainsReadableAndResyncMigratesToVersionThree(): void
    {
        $sourcePath = dirname(__DIR__) . '/src/Init/ManagedAssetSource.php';
        $targetRoot = $this->root . '/.claude/skills';
        $target = 'managed-source.php';
        mkdir($targetRoot, 0o775, true);
        copy($sourcePath, $targetRoot . '/' . $target);

        $sourceDigest = InitSyncManifest::digestPath($sourcePath);
        $representationDigest = InitSyncManifest::representationDigest($targetRoot, $target);
        self::assertNotNull($sourceDigest);
        self::assertNotNull($representationDigest);

        file_put_contents(
            $targetRoot . '/' . InitSyncManifest::fileName(),
            json_encode([
                'version' => 2,
                'kind' => 'skills',
                'agent' => 'claude',
                'required_capabilities' => ['skill-projection'],
                'entries' => [[
                    'target' => $target,
                    'source_id' => 'voku/agent-loop:skill:managed-source',
                    'semantic_owner' => 'voku/agent-loop',
                    'source_path' => $sourcePath,
                    'source_sha256' => $sourceDigest,
                    'representation_sha256' => $representationDigest,
                    'adopted' => false,
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $manifest = InitSyncManifest::load($targetRoot, 'skills', 'claude');
        self::assertTrue($manifest->hasDriftEvidence());
        self::assertNull($manifest->entry($target)['source_reference'] ?? null);
        self::assertSame([$target], ManagedAssetDriftInspector::inspect($manifest, $targetRoot, 'claude', [$target])['current']);

        $source = ManagedAssetSource::fromPath($this->root, $sourcePath, 'skill:managed-source');
        $manifest->writeProjections([$target => $source], [HostCapability::SkillProjection]);
        $migrated = json_decode(
            (string) file_get_contents($targetRoot . '/' . InitSyncManifest::fileName()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(3, $migrated['version']);
        self::assertSame('src/Init/ManagedAssetSource.php', $migrated['entries'][0]['source_reference']);
        self::assertNull($migrated['entries'][0]['source_path']);
    }

    public function testUnsafePortableReferencesFailClosed(): void
    {
        self::assertNull(ManagedAssetSource::resolvePersistedPath('voku/agent-loop', '../src/Init/ManagedAssetSource.php', null));
        self::assertNull(ManagedAssetSource::resolvePersistedPath('voku/agent-loop', '/tmp/ManagedAssetSource.php', null));
        self::assertNull(ManagedAssetSource::resolvePersistedPath('project', 'src/Init/ManagedAssetSource.php', null));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }
            unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
