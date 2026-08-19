<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\WorkflowHandoffCommand;
use voku\AgentSession\SessionStore;

final class WorkflowHandoffCommandTest extends TestCase
{
    public function testCompilesExistingTodoCardHandoffRecipeFromBoundedSessionProjection(): void
    {
        $root = $this->temporaryRoot();
        $layout = new ProjectLayout($root);
        $store = new SessionStore();
        $session = $store->create($layout->sessionsRoot(), 'TASK-1', by: 'agent');
        file_put_contents(
            $session->path . '/plan.md',
            "# Session plan\n\n## Goal\n\nFinish the measured recovery slice.\n\n## Next action\n\nRun the installed-consumer falsification.\n",
        );
        $store->addCheckpoint($session, 'Recovered current state', 'PR #230 is green; verify merged-main ancestry next.');

        $received = null;
        $command = new WorkflowHandoffCommand(
            $root,
            static function (array $args) use (&$received): int {
                $received = $args;

                return 0;
            },
            $store,
            operatingPromptManifest: '/installed/agent-recall-compiler/operating-prompts.json',
        );

        self::assertSame(0, $command->run(['TASK-1']));
        self::assertIsArray($received);
        self::assertSame('compile', $received[0]);
        self::assertSame('TASK-1', $received[2]);
        self::assertContains('{"id":"todo-card-handoff","arguments":{}}', $received);
        self::assertContains('/installed/agent-recall-compiler/operating-prompts.json', $received);
        self::assertContains($layout->recallRoot() . '/TASK-1/handoff', $received);

        $descriptionIndex = array_search('--description', $received, true);
        self::assertIsInt($descriptionIndex);
        $description = $received[$descriptionIndex + 1];
        self::assertStringContainsString('Finish the measured recovery slice.', $description);
        self::assertStringContainsString('Run the installed-consumer falsification.', $description);
        self::assertStringContainsString('Recovered current state', $description);
        self::assertStringContainsString('derived working memory, not durable authority', $description);
        self::assertStringContainsString('No durable Contract was found for this task. Do not invent one.', $description);
    }

    public function testFailsClosedWithoutActiveSession(): void
    {
        $called = false;
        $command = new WorkflowHandoffCommand(
            $this->temporaryRoot(),
            static function (array $args) use (&$called): int {
                $called = true;

                return 0;
            },
            operatingPromptManifest: '/installed/agent-recall-compiler/operating-prompts.json',
        );

        self::assertSame(1, $command->run(['TASK-2']));
        self::assertFalse($called);
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-loop-handoff-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0777, true));

        return $root;
    }
}
