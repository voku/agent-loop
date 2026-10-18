<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;

final class WorkflowApproveExecutionProfileNoteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-approve-note-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/docs', 0o775, true) && !is_dir($this->root . '/docs')) {
            throw new RuntimeException('Unable to create fixture.');
        }
        file_put_contents($this->root . '/docs/note.txt', "current\n");
        (new TaskContractStore($this->root))->create(
            'NOTE-1',
            'Approve one bounded text change.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param list<string> $args */
    private function approve(array $args): string
    {
        ob_start();
        try {
            $exit = (new WorkflowApproveCommand($this->root))->run($args);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit, $output);

        return $output;
    }

    public function testTextNoteBeforeNextNamesProfileAndCommand(): void
    {
        $output = $this->approve(['NOTE-1', '--by', 'approver']);

        $note = strpos($output, '[NOTE]');
        self::assertNotFalse($note);
        self::assertLessThan(strpos($output, '[NEXT]'), $note);
        self::assertSame(1, substr_count($output, '[NOTE]'));
        self::assertStringContainsString('manual', $output);
        self::assertStringContainsString('fixed by the first enter', $output);
        self::assertStringContainsString('workflow execution-profile NOTE-1 --profile <manual|surgical|standard|hardened> --by <actor>', $output);
    }

    public function testJsonCarriesNotesAndKeepsNextAction(): void
    {
        $data = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--format=json']), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('1.0', $data['schema_version']);
        self::assertSame('command', $data['next_action_kind']);
        self::assertStringContainsString('enter NOTE-1 --format=json', $data['next_action']);
        self::assertCount(1, $data['notes']);
        self::assertStringContainsString('fixed by the first enter', $data['notes'][0]);
    }

    public function testNoNoteWhenAlreadyApproved(): void
    {
        $this->approve(['NOTE-1', '--by', 'approver']);
        $output = $this->approve(['NOTE-1', '--by', 'approver']);
        self::assertStringNotContainsString('[NOTE]', $output);

        $data = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('notes', $data);
    }
}
