<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;

final class TypedLifecycleEmbeddingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-typed-lifecycle-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/docs', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/docs/note.txt', "current\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testHostFrontDoorEmbedsRecallWithoutExternalRunner(): void
    {
        $this->approve('EMBED-1');

        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('enter', ['EMBED-1', '--format=json']);
            $stdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        $payload = $this->json($stdout);
        self::assertTrue($payload['mutation_ready'] ?? false);
        self::assertSame('compiled', $payload['manifest']['references']['recall']['state'] ?? null);
    }

    public function testDispatcherOwnsEnterAndFinishRouting(): void
    {
        $this->approve('EMBED-2');
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        try {
            $enterExit = $dispatcher->run(['agent-loop', 'enter', 'EMBED-2', '--format=json']);
            $enterStdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $enterExit);
        self::assertSame('enter', $this->json($enterStdout)['command'] ?? null);

        ob_start();
        try {
            $finishExit = $dispatcher->run(['agent-loop', 'finish', 'EMBED-2', '--format=json']);
            $finishStdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(1, $finishExit);
        self::assertSame('finish', $this->json($finishStdout)['command'] ?? null);
    }

    private function approve(string $taskId): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Prove lifecycle embedding through typed package APIs.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contracts->approve($taskId, 'fixture-approver');
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        if (!is_string($value)) {
            throw new RuntimeException('Expected captured JSON string.');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected JSON object.');
        }

        return $decoded;
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
