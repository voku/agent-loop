<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentLoop\Workflow\WorkflowPromptService;
use voku\AgentSession\SessionStore;

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
        $this->removeTree($this->root);
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
        self::assertNull($first->goal);
        self::assertNull($first->continuityAnchor);
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
        self::assertNull($first->contractRevision);
        self::assertNull($first->recallCompilationId);
        self::assertNull($first->recallBundleSha256);
        self::assertNull($first->goal);
        self::assertNull($first->continuityAnchor);
        self::assertSame($first->nextAction, $second->nextAction);
        self::assertSame($first->digest, $second->digest);
        self::assertNotNull($first->nextAction);
        self::assertStringContainsString($first->nextAction, $first->content);
        self::assertStringContainsString('Approved goal: unavailable', $first->content);
        self::assertStringContainsString('Latest durable checkpoint: none available', $first->content);
        self::assertStringContainsString('generated prompt text is not approval', $first->content);
    }

    public function testContinueTaskProjectsApprovedGoalAndNewestCheckpoint(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Resume the current governed change without stale chat assumptions.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'lars',
        );
        $contracts->approve('ABC-123', 'lars');

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', 'prompt', 'lars');
        $sessions->addCheckpoint($session, 'First bounded slice', 'Initial evidence collected.');

        $service = new WorkflowPromptService($this->root);
        $first = $service->continueTask('ABC-123');

        self::assertSame('Resume the current governed change without stale chat assumptions.', $first->goal);
        self::assertNotNull($first->continuityAnchor);
        self::assertSame('checkpoint', $first->continuityAnchor['kind']);
        self::assertSame('First bounded slice', $first->continuityAnchor['title']);
        self::assertStringContainsString('Approved goal: Resume the current governed change without stale chat assumptions.', $first->content);
        self::assertStringContainsString('Latest durable checkpoint: ' . $first->continuityAnchor['id'] . ' First bounded slice', $first->content);

        $current = $sessions->load($this->root . '/.agent-loop/sessions', $session->id);
        self::assertNotNull($current);
        $sessions->addCheckpoint($current, 'Second bounded slice', 'The latest durable re-entry anchor.');

        $second = $service->continueTask('ABC-123');

        self::assertNotNull($second->continuityAnchor);
        self::assertSame('Second bounded slice', $second->continuityAnchor['title']);
        self::assertNotSame($first->continuityAnchor['id'], $second->continuityAnchor['id']);
        self::assertNotSame($first->digest, $second->digest);
        self::assertSame($second->continuityAnchor, $second->toArray()['continuity_anchor']);
        self::assertSame($second->goal, $second->toArray()['goal']);
    }

    public function testEnvelopePublishesTypedContractAndRecallLineage(): void
    {
        $envelope = new WorkflowPromptEnvelope(
            mode: WorkflowPromptEnvelope::MODE_CONTINUE,
            taskId: 'ABC-123',
            content: 'Continue through current workflow authority.',
            mutationAllowed: true,
            runId: 'run:ABC-123:1',
            state: 'implementation',
            nextAction: 'host work',
            nextActionKind: 'host_work',
            contractRevision: 3,
            recallCompilationId: 'compilation.ABC-123.fixed',
            recallBundleSha256: 'sha256:' . str_repeat('a', 64),
            goal: 'Keep the host oriented after resume.',
            continuityAnchor: [
                'kind' => 'checkpoint',
                'id' => 'checkpoint-2',
                'title' => 'Resume here',
            ],
        );

        self::assertSame(3, $envelope->toArray()['contract_revision']);
        self::assertSame('compilation.ABC-123.fixed', $envelope->toArray()['recall_compilation_id']);
        self::assertSame('sha256:' . str_repeat('a', 64), $envelope->toArray()['recall_bundle_sha256']);
        self::assertSame('Keep the host oriented after resume.', $envelope->toArray()['goal']);
        self::assertSame('checkpoint-2', $envelope->toArray()['continuity_anchor']['id']);
    }

    public function testEnvelopeRejectsMutableNestedProvenanceValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provenance must contain JSON-compatible scalar/array values only');

        new WorkflowPromptEnvelope(
            mode: WorkflowPromptEnvelope::MODE_CONTINUE,
            taskId: 'ABC-123',
            content: 'Continue through current workflow authority.',
            mutationAllowed: false,
            runId: 'run:ABC-123:1',
            state: 'blocked',
            nextAction: 'inspect blocker',
            nextActionKind: 'host_work',
            references: ['owner' => ['mutable' => new \stdClass()]],
        );
    }

    public function testInvalidTaskIdFailsBeforeProjection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WorkflowPromptService($this->root))->continueTask('../escape');
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
