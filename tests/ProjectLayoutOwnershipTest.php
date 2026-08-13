<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\InitPathsCommand;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\AcceptedRiskWriter;
use voku\AgentLoop\Workflow\TaskContractStore;

/**
 * ProjectLayout is the only thing allowed to spell a state location.
 *
 * A project that configures its own state root must move every owned path with
 * it. Anything that keeps a private literal stays behind in the default tree,
 * which is how a read-only projection once kept reading a pre-consolidation
 * session root while the rest of the system had moved on.
 */
final class ProjectLayoutOwnershipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-layout-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testConfiguredStateRootMovesEveryOwnedPath(): void
    {
        $this->writeConfig(['state_root' => 'var/agent-state']);
        $layout = new ProjectLayout($this->root);

        foreach ($layout->describe() as $name => $path) {
            if ($name === 'project_root' || $name === 'config') {
                continue;
            }

            self::assertStringStartsWith(
                'var/agent-state',
                $path,
                $name . ' ignored the configured state root and stayed in the default tree.',
            );
        }
    }

    public function testOwnedStoresWriteBelowTheConfiguredStateRoot(): void
    {
        $this->writeConfig(['state_root' => 'var/agent-state']);

        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Prove the layout owns every path.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');

        self::assertFileExists($this->root . '/var/agent-state/contracts/ABC-123/contract.json');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/contracts');

        self::assertStringStartsWith(
            $this->root . '/var/agent-state/runs/ABC-123',
            (new GovernedRunStore($this->root))->path('ABC-123'),
        );
        self::assertStringStartsWith(
            $this->root . '/var/agent-state/runs/ABC-123',
            (new RunManifestStore($this->root))->path('ABC-123'),
        );
        self::assertStringStartsWith(
            $this->root . '/var/agent-state/runs/ABC-123',
            (new RunVerificationReceiptStore($this->root))->path('ABC-123'),
        );
        self::assertSame(
            'var/agent-state/risks/ABC-123.accepted-risk.md',
            (new AcceptedRiskWriter($this->root))->relativePath('ABC-123'),
        );
    }

    public function testIndividualBranchesCanBeOverriddenWithoutMovingTheRest(): void
    {
        $this->writeConfig(['learning_root' => 'infra/doc/agent-learning']);
        $layout = new ProjectLayout($this->root);

        self::assertSame($this->root . '/infra/doc/agent-learning', $layout->learningRoot());
        self::assertSame('infra/doc/agent-learning', $layout->display($layout->learningRoot()));
        self::assertSame('.agent-loop/runs', $layout->display($layout->runsRoot()));
    }

    public function testProjectRecallDocumentManifestsArePortableAndDeterministic(): void
    {
        mkdir($this->root . '/docs/agents', 0o775, true);
        mkdir($this->root . '/modules/payments', 0o775, true);
        touch($this->root . '/docs/agents/recall-documents.json');
        touch($this->root . '/modules/payments/recall-documents.json');
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'recall' => [
                    'document_manifests' => [
                        'modules/payments/recall-documents.json',
                        'docs/agents/recall-documents.json',
                        'docs/agents/recall-documents.json',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame([
            $this->root . '/docs/agents/recall-documents.json',
            $this->root . '/modules/payments/recall-documents.json',
        ], (new ProjectLayout($this->root))->recallDocumentManifests());
    }

    public function testProjectRecallDocumentManifestCannotEscapeTheProject(): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'recall' => ['document_manifests' => ['../shared/policy.json']],
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recall document manifest must stay inside the project');

        (new ProjectLayout($this->root))->recallDocumentManifests();
    }

    public function testProjectRecallDocumentManifestCannotEscapeThroughSymlink(): void
    {
        $outside = sys_get_temp_dir() . '/agent-loop-policy-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($outside, '{}');
        symlink($outside, $this->root . '/linked-policy.json');
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'recall' => ['document_manifests' => ['linked-policy.json']],
            ], JSON_THROW_ON_ERROR),
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('recall document manifest escapes the project');

            (new ProjectLayout($this->root))->recallDocumentManifests();
        } finally {
            unlink($outside);
        }
    }

    public function testAnAbsoluteOverrideOutsideTheProjectIsKeptAbsolute(): void
    {
        $elsewhere = sys_get_temp_dir() . '/agent-loop-elsewhere-' . bin2hex(random_bytes(4));
        $this->writeConfig(['learning_root' => $elsewhere]);

        self::assertSame($elsewhere, (new ProjectLayout($this->root))->learningRoot());
    }

    public function testInitPathsReportsTheLayoutAsJsonForAgents(): void
    {
        $this->writeConfig(['state_root' => 'var/agent-state']);

        ob_start();
        $exit = (new InitPathsCommand($this->root))->run(['--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $paths = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($paths);
        self::assertSame('var/agent-state', $paths['state_root'] ?? null);
        self::assertSame('var/agent-state/runs', $paths['runs_root'] ?? null);
        self::assertSame('var/agent-state/contracts', $paths['contracts_root'] ?? null);
    }

    public function testInitPathsRejectsAnUnknownOption(): void
    {
        ob_start();
        $exit = (new InitPathsCommand($this->root))->run(['--bogus']);
        ob_end_clean();

        self::assertSame(1, $exit);
    }

    /** @param array<string, string> $paths */
    private function writeConfig(array $paths): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'paths' => $paths], JSON_THROW_ON_ERROR),
        );
    }

    private function rm(string $path): void
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
