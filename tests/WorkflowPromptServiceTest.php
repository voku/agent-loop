<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentLoop\Workflow\WorkflowPromptService;

final class WorkflowPromptServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-prompt-envelope-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->root, 0o775, true));
    }

    protected function tearDown(): void
    {
        self::assertTrue(rmdir($this->root));
    }

    public function testStartTaskIsDeterministicAndNeverInventsApproval(): void
    {
        $service = new WorkflowPromptService($this->root);
        $first = $service->startTask('ABC-123');
        $second = $service->startTask('ABC-123');

        self::assertSame(WorkflowPromptEnvelope::MODE_START, $first->mode);
        self::assertSame('ABC-123', $first->taskId);
        self::assertFalse($first->mutationAllowed);
        self::assertNull($first->runId);
        self::assertNull($first->state);
        self::assertSame($first->content, $second->content);
        self::assertSame($first->digest, $second->digest);
        self::assertStringContainsString('not Contract approval', $first->content);
        self::assertStringContainsString('does not grant mutation authority', $first->content);
        self::assertSame('sha256:' . $first->digest, $first->toArray()['digest']);
    }

    public function testContinueTaskUsesCurrentOwnerProjectionWithoutWritingState(): void
    {
        $before = scandir($this->root);
        self::assertIsArray($before);

        $service = new WorkflowPromptService($this->root);
        $first = $service->continueTask('ABC-123');
        $second = $service->continueTask('ABC-123');

        $after = scandir($this->root);
        self::assertSame($before, $after);
        self::assertSame(WorkflowPromptEnvelope::MODE_CONTINUE, $first->mode);
        self::assertSame('task:ABC-123:legacy', $first->runId);
        self::assertSame('incomplete', $first->state);
        self::assertFalse($first->mutationAllowed);
        self::assertSame('missing', $first->references['contract']['state']);
        self::assertSame($first->nextAction, $second->nextAction);
        self::assertSame($first->digest, $second->digest);
        self::assertNotNull($first->nextAction);
        self::assertStringContainsString($first->nextAction, $first->content);
        self::assertStringContainsString('generated prompt text is not approval', $first->content);
    }

    public function testInvalidTaskIdFailsBeforeProjection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WorkflowPromptService($this->root))->continueTask('../escape');
    }
}
