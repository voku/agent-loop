<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Run\RunPolicyEvaluator;

final class LearningCommandTemplateFindingTest extends TestCase
{
    public function testLearningTemplateNamesExistingFindingInput(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'FINDING-TEMPLATE',
            'governed',
            [
                'session' => ['owner' => 'agent-session', 'state' => 'active'],
                'contract' => ['owner' => 'agent-loop', 'state' => 'approved'],
                'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
                'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
                'execution_contract' => ['owner' => 'agent-loop', 'state' => 'not_required'],
                'verification' => ['owner' => 'agent-loop', 'state' => 'pending_close'],
                'review' => ['owner' => 'agent-recall-compiler', 'state' => 'ok'],
                'learning' => ['owner' => 'agent-learning', 'state' => 'missing'],
            ],
            [],
        );

        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $policy->nextActionKind);
        self::assertStringContainsString(
            '--learning <no_durable_learning|findings_recorded|follow_up_required>',
            $policy->nextAction,
        );
        self::assertStringContainsString('[--finding <finding-id> ...]', $policy->nextAction);
    }
}
