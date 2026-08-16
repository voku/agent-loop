<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class GovernedRecallInputArchiveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-recall-archive-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0o775, true));
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

    public function testSupersededRunKeepsExactApprovedContractBesideRecallEnvelope(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Implement the original bounded task.',
            ['docs/example.md'],
            [],
            ['composer ci'],
            'agent',
        );

        self::assertSame(0, $this->approve('ABC-123'));

        $activeRunDirectory = $this->root . '/.agent-loop/runs/ABC-123';
        $activeRecallInput = $activeRunDirectory . '/recall-input.json';
        $activeContractSnapshot = $activeRunDirectory . '/contract.json';
        self::assertFileExists($activeRecallInput);
        self::assertFileExists($activeContractSnapshot);

        $firstInput = $this->json($activeRecallInput);
        self::assertSame('contract.json', $firstInput['contract']['path'] ?? null);
        self::assertSame(1, $firstInput['contract']['revision'] ?? null);
        self::assertSame($this->sha256($activeContractSnapshot), $firstInput['contract']['sha256'] ?? null);

        $firstContractBytes = file_get_contents($activeContractSnapshot);
        self::assertIsString($firstContractBytes);
        $firstSnapshot = $this->json($activeContractSnapshot);
        self::assertSame('approved', $firstSnapshot['status'] ?? null);
        self::assertSame(1, $firstSnapshot['revision'] ?? null);

        $runBeforeResume = $this->json($activeRunDirectory . '/run.json');
        self::assertSame(0, $this->approve('ABC-123'));
        self::assertSame($runBeforeResume, $this->json($activeRunDirectory . '/run.json'));
        self::assertSame($firstContractBytes, file_get_contents($activeContractSnapshot));

        $sessions = new SessionStore();
        $firstSessions = $sessions->all($this->root . '/.agent-loop/sessions');
        self::assertCount(1, $firstSessions);
        $sessions->setStatus($firstSessions[0], SessionStatus::DROPPED);

        $contracts->revise(
            'ABC-123',
            'Implement the bounded task with newly discovered scope.',
            ['docs/example.md', 'docs/example-2.md'],
            [],
            ['composer ci'],
            'agent',
        );

        self::assertSame(0, $this->approve('ABC-123'));
        self::assertSame(2, $contracts->load('ABC-123')->revision);
        self::assertSame('approved', $contracts->load('ABC-123')->status);

        $archivedInputs = glob($this->root . '/.agent-loop/runs/.history/ABC-123/*/recall-input.json') ?: [];
        self::assertCount(1, $archivedInputs);

        $archivedInput = $this->json($archivedInputs[0]);
        self::assertSame('contract.json', $archivedInput['contract']['path'] ?? null);
        self::assertSame(1, $archivedInput['contract']['revision'] ?? null);

        $archivedContractSnapshot = dirname($archivedInputs[0]) . '/contract.json';
        self::assertFileExists($archivedContractSnapshot);
        self::assertSame($this->sha256($archivedContractSnapshot), $archivedInput['contract']['sha256'] ?? null);
        self::assertSame($firstContractBytes, file_get_contents($archivedContractSnapshot));

        $archivedSnapshot = $this->json($archivedContractSnapshot);
        self::assertSame('approved', $archivedSnapshot['status'] ?? null);
        self::assertSame(1, $archivedSnapshot['revision'] ?? null);

        $taskContractHistory = $this->json(
            $this->root . '/.agent-loop/contracts/ABC-123/history/contract.001.json',
        );
        self::assertSame('superseded', $taskContractHistory['status'] ?? null);
        self::assertNotSame(
            $this->sha256($archivedContractSnapshot),
            $this->sha256($this->root . '/.agent-loop/contracts/ABC-123/history/contract.001.json'),
            'The Task Contract history representation is not the exact approved source bound to the old Run.',
        );
    }

    private function approve(string $taskId): int
    {
        ob_start();
        try {
            return (new WorkflowApproveCommand(
                $this->root,
                static fn (array $arguments): int => 0,
            ))->run([$taskId, '--by', 'lars']);
        } finally {
            ob_end_clean();
        }
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function sha256(string $path): string
    {
        $digest = hash_file('sha256', $path);
        self::assertIsString($digest);

        return 'sha256:' . $digest;
    }
}
