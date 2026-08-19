<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHandoffCommand;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentSession\SessionStore;

final class WorkflowHandoffCommandTest extends TestCase
{
    public function testCompilesExistingTodoCardHandoffRecipeFromExplicitBoundedContext(): void
    {
        $root = $this->temporaryRoot();
        $layout = new ProjectLayout($root);
        $contractStore = new TaskContractStore($root);
        $contractStore->create(
            'TASK-1',
            'Finish the recovery slice.',
            ['README.md'],
            [],
            ['composer test'],
            'planner',
        );
        $contract = $contractStore->approve('TASK-1', 'reviewer');

        $sessionStore = new SessionStore();
        $session = $sessionStore->create($layout->sessionsRoot(), 'TASK-1', by: 'agent');
        self::assertTrue(is_dir($layout->learningRoot()) || mkdir($layout->learningRoot(), 0777, true));
        (new GovernedRunStore($root))->prepare($contract, $session, $layout->learningRoot());

        $received = null;
        $command = new WorkflowHandoffCommand(
            $root,
            static function (array $args) use (&$received): int {
                $received = $args;

                return 0;
            },
            $sessionStore,
            '/installed/agent-recall-compiler/operating-prompts.json',
        );

        $context = 'Verified: PR #230 is green. Next: verify merged-main ancestry, then run the installed-consumer falsification.';
        self::assertSame(0, $command->run(['TASK-1', '--context', $context]));
        self::assertIsArray($received);
        self::assertSame('compile', $received[0]);
        self::assertSame('TASK-1', $received[2]);
        self::assertContains('{"id":"todo-card-handoff","arguments":{}}', $received);
        self::assertContains('/installed/agent-recall-compiler/operating-prompts.json', $received);
        self::assertContains($layout->recallRoot() . '/TASK-1/handoff', $received);

        $descriptionIndex = array_search('--description', $received, true);
        self::assertIsInt($descriptionIndex);
        $description = $received[$descriptionIndex + 1];
        self::assertStringContainsString($context, $description);
        self::assertStringContainsString('candidate context, not durable authority', $description);
        self::assertStringContainsString('Finish the recovery slice.', $description);
        self::assertStringContainsString($session->id, $description);
    }

    public function testRealInstalledRecallCompilesSelfContainedHandoffPrompt(): void
    {
        $root = $this->temporaryRoot();
        $layout = new ProjectLayout($root);
        $contractStore = new TaskContractStore($root);
        $contractStore->create('REAL-1', 'Persist a resumable task handoff.', ['README.md'], [], ['composer test'], 'planner');
        $contract = $contractStore->approve('REAL-1', 'reviewer');
        $sessionStore = new SessionStore();
        $session = $sessionStore->create($layout->sessionsRoot(), 'REAL-1', by: 'agent');
        self::assertTrue(is_dir($layout->learningRoot()) || mkdir($layout->learningRoot(), 0777, true));
        (new GovernedRunStore($root))->prepare($contract, $session, $layout->learningRoot());

        $command = new WorkflowHandoffCommand(
            $root,
            static fn (array $args): int => (new RecallCli())->run([
                'agent-recall-compiler',
                ...$args,
                '--root', $layout->learningRoot(),
            ]),
            $sessionStore,
        );

        self::assertSame(0, $command->run([
            'REAL-1',
            '--context',
            'VERIFIED: implementation is complete. UNKNOWN: external review result. Next: inspect exact-head review and update the existing task card.',
        ]));

        $systemPath = $layout->recallRoot() . '/REAL-1/handoff/system.md';
        self::assertFileExists($systemPath);
        $system = file_get_contents($systemPath);
        self::assertIsString($system);
        self::assertStringContainsString('REAL-1', $system);
        self::assertStringContainsString('coding agent', strtolower($system));
        self::assertStringContainsString('VERIFIED', $system);
        self::assertStringContainsString('existing', strtolower($system));
    }

    public function testReadsExplicitContextFile(): void
    {
        $root = $this->temporaryRoot();
        $layout = new ProjectLayout($root);
        $contractStore = new TaskContractStore($root);
        $contractStore->create('TASK-2', 'Prepare handoff.', ['README.md'], [], ['composer test'], 'planner');
        $contract = $contractStore->approve('TASK-2', 'reviewer');
        $sessionStore = new SessionStore();
        $session = $sessionStore->create($layout->sessionsRoot(), 'TASK-2', by: 'agent');
        self::assertTrue(is_dir($layout->learningRoot()) || mkdir($layout->learningRoot(), 0777, true));
        (new GovernedRunStore($root))->prepare($contract, $session, $layout->learningRoot());
        $contextFile = $root . '/handoff-notes.md';
        file_put_contents($contextFile, "Verified current state.\nRemaining blocker: external review.\n");

        $received = null;
        $command = new WorkflowHandoffCommand(
            $root,
            static function (array $args) use (&$received): int {
                $received = $args;

                return 0;
            },
            $sessionStore,
            '/installed/agent-recall-compiler/operating-prompts.json',
        );

        self::assertSame(0, $command->run(['TASK-2', '--context-file', $contextFile]));
        self::assertIsArray($received);
        $descriptionIndex = array_search('--description', $received, true);
        self::assertIsInt($descriptionIndex);
        self::assertStringContainsString('Remaining blocker: external review.', $received[$descriptionIndex + 1]);
    }

    public function testFailsClosedWithoutGovernedRun(): void
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

        self::assertSame(1, $command->run(['TASK-3', '--context', 'Bounded notes.']));
        self::assertFalse($called);
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/agent-loop-handoff-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0777, true));

        return $root;
    }
}
