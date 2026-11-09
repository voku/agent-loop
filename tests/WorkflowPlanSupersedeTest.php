<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class WorkflowPlanSupersedeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-plan-supersede-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create supersession fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPlanRefusesToReviseAnActiveGovernedSessionWithoutExplicitSupersession(): void
    {
        $contracts = $this->approvedContract();
        $sessions = new SessionStore();
        $session = $sessions->create((new ProjectLayout($this->root))->sessionsRoot(), 'SUPERSEDE-1', by: 'agent');

        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run($this->revisionArguments());
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertSame(SessionStatus::ACTIVE, $sessions->load(dirname($session->path), $session->id)->status);
        $contract = $contracts->load('SUPERSEDE-1');
        self::assertSame(1, $contract->revision);
        self::assertSame(TaskContract::APPROVED, $contract->status);
    }

    public function testExplicitSupersessionRetiresWorkingSessionAndCreatesUnapprovedCandidateRevisionWithoutDroppingGovernance(): void
    {
        $contracts = $this->approvedContract();
        $sessions = new SessionStore();
        $session = $sessions->create((new ProjectLayout($this->root))->sessionsRoot(), 'SUPERSEDE-1', by: 'agent');

        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run([
                ...$this->revisionArguments(),
                '--supersede',
            ]);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(SessionStatus::DROPPED, $sessions->load(dirname($session->path), $session->id)->status);

        $candidate = $contracts->load('SUPERSEDE-1');
        self::assertSame(2, $candidate->revision);
        self::assertSame(TaskContract::CANDIDATE, $candidate->status);
        self::assertSame('Revised intent after L2 rejection.', $candidate->goal);
        self::assertSame(['src/Foo.php'], $candidate->scope);
        self::assertSame(['composer ci'], $candidate->validation);
        self::assertSame(['Do not remove the selected policy.'], $candidate->nonGoals);
        self::assertSame('base-sha', $candidate->baseCommit);
        self::assertSame(['risk:high'], $candidate->tags);
        self::assertSame(['Parser::parse'], $candidate->behaviorAnchors);
        self::assertSame('skills/operational-prompting/operating-prompts.json', $candidate->operatingPromptManifest);
        self::assertSame([[
            'id' => 'coverage-mutation',
            'arguments' => ['minimum_percentage_points' => 10],
        ]], $candidate->operatingPrompts);
        self::assertSame(['Preserve the selected L2 policy.'], $candidate->acceptanceCriteria);
        self::assertNull($candidate->approvedBy);
        self::assertNull($candidate->approvedAt);
        self::assertStringContainsString('candidate Contract superseded', $output);
        self::assertStringContainsString('agent-loop workflow approve SUPERSEDE-1 --revision 2 --by <named-actor>', $output);
        self::assertSame('planner', $candidate->plannedBy);
    }

    public function testSupersedeCannotCreateAFirstContract(): void
    {
        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run([
                ...$this->revisionArguments(),
                '--supersede',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertNull((new TaskContractStore($this->root))->find('SUPERSEDE-1'));
    }

    public function testScopeOnlySupersessionPreservesApprovedIntentAndStillRequiresHumanApproval(): void
    {
        $contracts = $this->approvedContract();
        $sessions = new SessionStore();
        $session = $sessions->create((new ProjectLayout($this->root))->sessionsRoot(), 'SUPERSEDE-1', by: 'agent');

        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run([
                'SUPERSEDE-1', '--by', 'planner', '--supersede',
                '--add-scope', 'tests/FooTest.php',
                '--add-scope', 'src/Helpers.php',
                '--format=json',
            ]);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(SessionStatus::DROPPED, $sessions->load(dirname($session->path), $session->id)->status);

        $candidate = $contracts->load('SUPERSEDE-1');
        self::assertSame(2, $candidate->revision);
        self::assertSame(TaskContract::CANDIDATE, $candidate->status);
        self::assertSame(['src/Foo.php', 'tests/FooTest.php', 'src/Helpers.php'], $candidate->scope);
        self::assertSame('Original approved intent.', $candidate->goal);
        self::assertSame(['composer ci'], $candidate->validation);
        self::assertSame(['Do not remove the selected policy.'], $candidate->nonGoals);
        self::assertSame('base-sha', $candidate->baseCommit);
        self::assertSame(['risk:high'], $candidate->tags);
        self::assertSame(['Parser::parse'], $candidate->behaviorAnchors);
        self::assertSame('skills/operational-prompting/operating-prompts.json', $candidate->operatingPromptManifest);
        self::assertSame([['id' => 'coverage-mutation', 'arguments' => ['minimum_percentage_points' => 10]]], $candidate->operatingPrompts);
        self::assertSame(['Preserve the selected L2 policy.'], $candidate->acceptanceCriteria);
        self::assertNull($candidate->approvedBy);
        self::assertNull($candidate->approvedAt);

        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame('superseded', $result['action']);
        self::assertSame('decision_required', $result['next_action_kind']);
        self::assertSame('agent-loop workflow approve SUPERSEDE-1 --revision 2 --by <named-actor>', $result['next_action']);
    }

    public function testScopeOnlyNoopRefusesToReviseOrDropActiveSession(): void
    {
        $contracts = $this->approvedContract();
        $sessions = new SessionStore();
        $session = $sessions->create((new ProjectLayout($this->root))->sessionsRoot(), 'SUPERSEDE-1', by: 'agent');

        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run([
                'SUPERSEDE-1', '--by', 'planner', '--supersede', '--add-scope', 'src/Foo.php',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertSame(SessionStatus::ACTIVE, $sessions->load(dirname($session->path), $session->id)->status);
        self::assertSame(1, $contracts->load('SUPERSEDE-1')->revision);
        self::assertSame(TaskContract::APPROVED, $contracts->load('SUPERSEDE-1')->status);
    }

    public function testScopeOnlyRejectsMissingSupersedeMixedInputsAndTraversalWithoutRevising(): void
    {
        $contracts = $this->approvedContract();
        $cases = [
            ['SUPERSEDE-1', '--by', 'planner', '--add-scope', 'tests/FooTest.php'],
            ['SUPERSEDE-1', '--by', 'planner', '--supersede', '--add-scope', '../private/secrets.php'],
            ['SUPERSEDE-1', '--by', 'planner', '--supersede', '--add-scope', 'tests/FooTest.php', '--scope', 'src/'],
            ['SUPERSEDE-1', '--by', 'planner', '--supersede', '--add-scope', 'tests/FooTest.php', '--goal', 'Different goal'],
        ];

        foreach ($cases as $args) {
            ob_start();
            try {
                $exit = (new WorkflowPlanCommand($this->root))->run($args);
            } finally {
                ob_end_clean();
            }
            self::assertSame(1, $exit);
            self::assertSame(1, $contracts->load('SUPERSEDE-1')->revision);
            self::assertSame(TaskContract::APPROVED, $contracts->load('SUPERSEDE-1')->status);
        }
    }

    public function testScopeOnlyCannotInventFirstContract(): void
    {
        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run([
                'SUPERSEDE-1', '--by', 'planner', '--supersede', '--add-scope', 'tests/FooTest.php',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertNull((new TaskContractStore($this->root))->find('SUPERSEDE-1'));
    }

    private function approvedContract(): TaskContractStore
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'SUPERSEDE-1',
            'Original approved intent.',
            ['src/Foo.php'],
            ['Do not remove the selected policy.'],
            ['composer ci'],
            'planner',
            baseCommit: 'base-sha',
            tags: ['risk:high'],
            behaviorAnchors: ['Parser::parse'],
            operatingPromptManifest: 'skills/operational-prompting/operating-prompts.json',
            operatingPrompts: [[
                'id' => 'coverage-mutation',
                'arguments' => ['minimum_percentage_points' => 10],
            ]],
            acceptanceCriteria: ['Preserve the selected L2 policy.'],
        );
        $contracts->approve('SUPERSEDE-1', 'approver', $contracts->load('SUPERSEDE-1')->revision);

        return $contracts;
    }

    /** @return list<string> */
    private function revisionArguments(): array
    {
        return [
            'SUPERSEDE-1',
            '--by', 'planner',
            '--file', 'src/Foo.php',
            '--goal', 'Revised intent after L2 rejection.',
            '--validation', 'composer ci',
        ];
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
