<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowRunPreparer;

/**
 * A discovery refusal has to name the index it judged.
 *
 * A repository can hold more than one agent-map index - a repository-local
 * `.agent-map/` beside the governed `.agent-loop/map/` - and only one of them
 * is the one preparation reads. A refusal that lists stale files but not the
 * file it read them from leaves a host refreshing the other index, seeing no
 * change, and running the same prescribed command again.
 */
final class DiscoveryRefusalNamesTheIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-discovery-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o700, true)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAMissingIndexRefusalNamesWhereItLooked(): void
    {
        $this->assertRefusal($this->contract(), ['src/Greeter.php', $this->governedIndex()]);
    }

    public function testAStaleIndexRefusalNamesTheIndexItJudgedNotOnlyTheStaleFiles(): void
    {
        $this->writeGovernedIndex(hash('sha256', 'a hash that no longer matches the file'));

        $this->assertRefusal($this->contract(), ['stale map entries', 'src/Greeter.php', $this->governedIndex()]);
    }

    public function testARepositoryLocalIndexIsNotTheOneNamed(): void
    {
        // The failure this exists for: a fresh `.agent-map/` sitting beside a
        // stale governed index. Preparation reads the governed one, so that is
        // the path the refusal must name - naming the other would send the
        // reader to refresh a file preparation never opens.
        if (!mkdir($this->root . '/.agent-map', 0o775, true)) {
            throw new RuntimeException('Unable to create repository-local map root.');
        }
        file_put_contents(
            $this->root . '/.agent-map/php-symbols.json',
            (string) file_get_contents($this->writeGovernedIndex(hash_file('sha256', $this->root . '/src/Greeter.php') ?: '')),
        );
        $this->writeGovernedIndex(hash('sha256', 'stale'));

        $this->assertRefusal($this->contract(), [$this->governedIndex()], ['/.agent-map/']);
    }

    public function testScopeThatIsNotIndexedNamesTheIndexThatDoesNotCarryIt(): void
    {
        $this->writeGovernedIndex(hash_file('sha256', $this->root . '/src/Greeter.php') ?: '', indexTheFile: false);

        $this->assertRefusal($this->contract(), ['scope not indexed', $this->governedIndex()]);
    }

    /**
     * @param list<string> $contains
     * @param list<string> $absent
     */
    private function assertRefusal(TaskContract $contract, array $contains, array $absent = []): void
    {
        try {
            (new WorkflowRunPreparer($this->root))->discoveryReadiness($contract);
        } catch (RuntimeException $exception) {
            foreach ($contains as $needle) {
                self::assertStringContainsString($needle, $exception->getMessage());
            }
            foreach ($absent as $needle) {
                self::assertStringNotContainsString($needle, $exception->getMessage());
            }

            return;
        }

        self::fail('discovery readiness did not refuse.');
    }

    private function contract(): TaskContract
    {
        return new TaskContract(
            taskId: 'DISCOVERY-1',
            goal: 'Change one indexed file.',
            scope: ['src/Greeter.php'],
            nonGoals: [],
            validation: ['composer ci'],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-09-09T00:00:00+00:00',
            updatedAt: '2026-09-09T00:00:00+00:00',
            path: $this->root . '/contract.json',
            plannedBy: 'planner',
            approvedBy: 'approver',
            approvedAt: '2026-09-09T00:00:00+00:00',
        );
    }

    private function governedIndex(): string
    {
        return $this->root . '/.agent-loop/map/php-symbols.json';
    }

    private function writeGovernedIndex(string $sha256, bool $indexTheFile = true): string
    {
        $path = $this->governedIndex();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create governed map root.');
        }

        file_put_contents($path, json_encode([
            'schema_version' => '2.0',
            'root' => $this->root,
            'backend' => 'simple-php-code-parser+structural-only',
            'files' => $indexTheFile ? [[
                'path' => 'src/Greeter.php',
                'sha256' => $sha256,
                'namespace' => 'Demo',
                'symbols' => [],
                'semantic_status' => 'analyzed',
            ]] : [],
            'relations' => [],
            'diagnostics' => [],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            // is_dir() follows a directory symlink, so a link inside the
            // fixture would send this recursion outside it. Unlink the link.
            if (is_link($full)) {
                unlink($full);
                continue;
            }
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
