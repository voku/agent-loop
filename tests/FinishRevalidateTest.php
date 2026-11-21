<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentSession\SessionStore;

/**
 * A validation failure whose cause was the environment (in real use: Composer
 * refusing to run as root) used to stay stored against the unchanged snapshot:
 * finish never re-ran it and only "change the implementation" was offered, so
 * the one way on was a cosmetic edit that games the gate.
 *
 * @internal
 */
final class FinishRevalidateTest extends TestCase
{
    private const string TASK = 'REVALIDATE-1';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-revalidate-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testRevalidateReRunsAnEnvironmentalFailureWithoutAnImplementationChange(): void
    {
        $this->prepare('php -r "exit(is_file(\'environment-ready\') ? 0 : 1);"');

        $failed = $this->finishJson([]);
        self::assertSame('host_work', $failed['next_action_kind'] ?? null);
        self::assertStringContainsString('--revalidate', (string) ($failed['next_action'] ?? ''));

        // The environment is fixed; the implementation is unchanged.
        touch($this->root . '/environment-ready');

        $stillFailed = $this->finishJson([]);
        self::assertSame('host_work', $stillFailed['next_action_kind'] ?? null, 'Plain finish must stay idempotent and not re-run a failed result.');

        $revalidated = $this->finishJson(['--revalidate']);
        self::assertNotSame('host_work', $revalidated['next_action_kind'] ?? null, json_encode($revalidated, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('validation evidence missing or not passed', (string) json_encode($revalidated, JSON_THROW_ON_ERROR));
    }

    public function testRevalidateRecordsARealFailureAgain(): void
    {
        $this->prepare('php -r "exit(1);"');
        $this->finishJson([]);

        $revalidated = $this->finishJson(['--revalidate']);

        self::assertSame('host_work', $revalidated['next_action_kind'] ?? null);
        self::assertStringContainsString('validation evidence missing or not passed', (string) ($revalidated['next_action'] ?? ''));
    }

    private function prepare(string $validation): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(self::TASK, 'Exercise finish --revalidate.', ['src/Foo.php'], [], [$validation], 'fixture-planner');
        $contract = $contracts->approve(self::TASK, 'fixture-approver', $contracts->load(self::TASK)->revision);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', self::TASK, by: 'fixture-agent');
        (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $recall = $this->root . '/.agent-loop/recall/' . self::TASK;
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => self::TASK,
            'compilation_id' => 'revalidate-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved command.\n");
    }

    /**
     * @param list<string> $options
     * @return array<string, mixed>
     */
    private function finishJson(array $options): array
    {
        ob_start();
        try {
            (new HostFrontDoorApplication($this->root))->run('finish', [self::TASK, '--format=json', ...$options]);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Finish JSON did not decode to an object.');
        }

        return $payload;
    }
}
