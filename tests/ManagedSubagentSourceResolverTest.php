<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\ManagedAssetTargetCatalog;
use voku\AgentLoop\Init\ManagedSubagentSourceResolver;
use voku\AgentLoop\Init\RepositorySetupService;

/** @internal */
final class ManagedSubagentSourceResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-subagent-source-resolver-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'acme/subagent-resolver-consumer'], JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSubagentResolutionCarriesFirstPartyProvenance(): void
    {
        $paths = AgentAssetSourcePaths::fromSources($this->root);
        $sources = (new ManagedSubagentSourceResolver($this->root))->resolve($paths);

        self::assertArrayHasKey('agent-loop-investigator', $sources);
        self::assertSame('voku/agent-loop', $sources['agent-loop-investigator']->assetSource->owner);
        self::assertNotNull($sources['agent-loop-investigator']->assetSource->reference);
        self::assertSame('agent-loop-investigator', $sources['agent-loop-investigator']->name);

        self::assertArrayHasKey('agent-loop-code-reviewer', $sources);
        self::assertSame('voku/agent-loop', $sources['agent-loop-code-reviewer']->assetSource->owner);
        self::assertNotNull($sources['agent-loop-code-reviewer']->assetSource->reference);
    }

    public function testTargetCatalogUsesTheResolvedSubagentSet(): void
    {
        $paths = AgentAssetSourcePaths::fromSources($this->root);
        $expected = array_map(
            static fn (string $name): string => $name . '.toml',
            array_keys((new ManagedSubagentSourceResolver($this->root))->resolve($paths)),
        );
        sort($expected, SORT_STRING);

        self::assertSame(
            $expected,
            (new ManagedAssetTargetCatalog($this->root))->subagentEntries($paths, '.toml'),
        );
    }

    public function testConfiguredDuplicateOfFirstPartySubagentFailsBeforeMutation(): void
    {
        $customRoot = $this->root . '/custom-subagents';
        mkdir($customRoot, 0o775, true);
        file_put_contents(
            $customRoot . '/agent-loop-investigator.md',
            "---\nname: agent-loop-investigator\ndescription: Conflicting subagent.\n---\n\nPrompt.\n",
        );

        $paths = AgentAssetSourcePaths::fromSources(
            $this->root,
            ['subagents_root' => 'custom-subagents'],
        );

        try {
            (new RepositorySetupService($this->root))->planInstall('codex', false, $paths);
            self::fail('Expected duplicate managed subagent source to block setup planning.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Multiple subagent sources own the same entry: agent-loop-investigator',
                $exception->getMessage(),
            );
        }

        self::assertDirectoryDoesNotExist($this->root . '/.codex/agents');
        self::assertFileDoesNotExist($this->root . '/AGENTS.md');
    }

    public function testConfiguredOnlyResolutionPreservesProjectOwnership(): void
    {
        $customRoot = $this->root . '/custom-subagents';
        mkdir($customRoot, 0o775, true);
        file_put_contents(
            $customRoot . '/acme-project-worker.md',
            "---\nname: acme-project-worker\ndescription: Project worker.\n---\n\nWorker prompt.\n",
        );

        $paths = AgentAssetSourcePaths::fromSources(
            $this->root,
            ['subagents_root' => 'custom-subagents'],
        );
        $sources = (new ManagedSubagentSourceResolver($this->root))->resolve($paths, false);

        self::assertSame(['acme-project-worker'], array_keys($sources));
        self::assertSame('project', $sources['acme-project-worker']->assetSource->owner);
        self::assertNull($sources['acme-project-worker']->assetSource->reference);
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
