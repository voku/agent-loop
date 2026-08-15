<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowKanbanContextWriter;
use voku\AgentSession\SessionStore;

final class WorkflowKanbanContextWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-kanban-context-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        mkdir($this->root . '/.agent-loop/sessions', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/todo/kanban.config.json',
            json_encode(['projectPrefix' => 'ABC'], JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRecallProjectionContainsOnlyConsumedFieldsWithoutTruncation(): void
    {
        $summary = str_repeat('S', 1100);
        $nextAction = str_repeat('N', 1600);
        $validation = str_repeat('V', 900);
        $handoffNotes = str_repeat('H', 2600);
        $taskBrief = str_repeat('T', 2800);

        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/ABC-123.md',
            sprintf(
                <<<'CARD'
# ABC-123: Keep the Recall projection small

- **Ticket:** ABC-123
- **Lane:** READY
- **Status:** Selected
- **Summary:** %s
- **Next:** %s
- **Validation:** %s
- **Priority:** 1

## Handoff / Context
%s

## Agent Task Brief
%s
CARD,
                $summary,
                $nextAction,
                $validation,
                $handoffNotes,
                $taskBrief,
            ) . "\n",
        );

        $session = (new SessionStore())->create(
            $this->root . '/.agent-loop/sessions',
            'ABC-123',
            'token-diet',
            'lars',
        );

        $path = (new WorkflowKanbanContextWriter($this->root))->write('ABC-123', $session);
        self::assertNotNull($path);

        $json = (string) file_get_contents($path);
        $context = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($context);
        self::assertIsArray($context['card'] ?? null);
        self::assertSame(
            ['title', 'lane', 'status', 'priority', 'next_action'],
            array_keys($context['card']),
        );
        self::assertSame($nextAction, $context['card']['next_action']);
        self::assertSame(0, substr_count(rtrim($json, "\n"), "\n"));
        self::assertLessThan(2500, strlen($json));
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
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
