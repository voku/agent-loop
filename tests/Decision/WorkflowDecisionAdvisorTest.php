<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Decision;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Decision\DecisionChoiceResult;
use voku\AgentLoop\Decision\DecisionModel;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowDecisionAdvisor;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector;
use voku\AgentLoop\Run\RunPolicyEvaluation;

final class WorkflowDecisionAdvisorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-decision-advice-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/.agent-loop', 0o700, true));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);
    }

    public function testDefaultHumanApprovalIncludesConservativeAdvisoryWithoutCallingModel(): void
    {
        $model = new FakeDecisionModel('approve', true);
        $recommendation = (new WorkflowDecisionAdvisor($this->root, $model))->advise(
            'contract_approval',
            [
                'goal' => 'Private company details never sent.',
                'scope' => ['src/Secret.php'],
                'validation' => ['phpunit'],
                'acceptance_criteria' => ['Validated result'],
            ],
        );

        self::assertSame('off', $recommendation['provider_mode']);
        self::assertSame('abstain', $recommendation['disposition']);
        self::assertNull($recommendation['suggested_choice']);
        self::assertSame('not_enabled', $recommendation['provider_disposition']);
        self::assertTrue($recommendation['human_decision_required']);
    }

    public function testIncompleteContractIsNotRecommendedForApproval(): void
    {
        $recommendation = (new WorkflowDecisionAdvisor($this->root))->advise(
            'contract_approval',
            [
                'scope' => ['src/Foo.php'],
                'validation' => [],
                'acceptance_criteria' => [],
            ],
        );

        self::assertSame('request_changes', $recommendation['suggested_choice']);
        self::assertSame('deterministic', $recommendation['source']);
        self::assertContains('acceptance_criteria', $recommendation['evidence_keys']);
    }

    public function testEveryKnownDecisionTypeHasHumanAuthorityAndBoundedChoices(): void
    {
        $advisor = new WorkflowDecisionAdvisor($this->root);
        foreach ([
            'contract_approval',
            'review_acknowledgement',
            'learning_disposition',
            'risk_acceptance',
            'contract_supersession',
            'human_authority',
        ] as $type) {
            $advice = $advisor->advise($type, []);
            self::assertSame('advisory_only', $advice['authority']);
            self::assertTrue($advice['human_decision_required']);
            self::assertContains($advice['disposition'], ['suggest', 'abstain']);
            $choice = $advice['suggested_choice'];
            if (is_string($choice)) {
                self::assertContains($choice, $advice['available_choices']);
            }
        }
    }

    public function testAssistUsesModelForPublicStructuralMetadataOnly(): void
    {
        $this->configure('assist', 'public_metadata');
        $model = new FakeDecisionModel('request_changes');
        $advice = (new WorkflowDecisionAdvisor($this->root, $model))->advise(
            'contract_approval',
            [
                'goal' => 'SECRET_UNRELATED_GOAL',
                'scope' => ['private/SECRET_INTERNAL_FILE.php'],
                'validation' => ['phpunit'],
                'acceptance_criteria' => ['SECRET_PRIVATE_AC'],
            ],
        );

        self::assertSame('decision_model', $advice['source']);
        self::assertSame('request_changes', $advice['suggested_choice']);
        self::assertSame('suggest', $advice['provider_disposition']);
        self::assertSame('system_one', $advice['provider']);
        self::assertSame(0.8, $advice['provider_probability']);
        self::assertTrue($advice['human_decision_required']);
        self::assertStringNotContainsString('SECRET_', $model->observedState);
        self::assertStringContainsString('scope_count', $model->observedState);
    }

    public function testShadowRecordsChoiceButNeverOverwritesHumanRecommendation(): void
    {
        $this->configure('shadow', 'public_metadata');
        $model = new FakeDecisionModel('approve');
        $advice = (new WorkflowDecisionAdvisor($this->root, $model))->advise(
            'contract_approval',
            ['scope' => ['src'], 'validation' => ['phpunit'], 'acceptance_criteria' => []],
        );

        self::assertSame('deterministic', $advice['source']);
        self::assertSame('request_changes', $advice['suggested_choice']);
        self::assertSame('approve', $advice['provider_observed_choice']);
        self::assertSame('suggest', $advice['provider_disposition']);
    }

    public function testProviderFailurePreservesDeterministicRecommendation(): void
    {
        $this->configure('assist', 'public_metadata');
        $model = new FakeDecisionModel(null, true);
        $advice = (new WorkflowDecisionAdvisor($this->root, $model))->advise(
            'risk_acceptance',
            ['blockers' => [['code' => 'RISK', 'message' => 'not sent']]],
        );

        self::assertSame('defer_risk_acceptance', $advice['suggested_choice']);
        self::assertSame('deterministic', $advice['source']);
        self::assertSame('provider_unavailable', $advice['provider_disposition']);
        self::assertSame('configuration_or_transport', $advice['provider_error']);
    }

    public function testRemoteModeWithoutPublicClassificationDoesNotEnableModel(): void
    {
        $this->configure('assist', 'unclassified');
        $advice = (new WorkflowDecisionAdvisor($this->root, new FakeDecisionModel('approve', true)))->advise(
            'contract_approval',
            [],
        );

        self::assertSame('off', $advice['provider_mode']);
        self::assertSame('not_enabled', $advice['provider_disposition']);
    }

    public function testCurrentContractProjectionActuallyIncludesRecommendation(): void
    {
        (new TaskContractStore($this->root))->create(
            taskId: 'DECISION-ADVICE',
            goal: 'A public test issue',
            scope: ['src/Example.php'],
            nonGoals: ['No new runtime dependencies'],
            validation: ['phpunit'],
            plannedBy: 'test',
            acceptanceCriteria: ['A new result is tested'],
        );

        $decision = (new WorkflowHumanDecisionProjector($this->root))->project(
            'DECISION-ADVICE',
            'agent-loop workflow approve DECISION-ADVICE --revision 1 --by <named-actor>',
            RunPolicyEvaluation::KIND_DECISION_REQUIRED,
        );

        self::assertIsArray($decision);
        self::assertSame('contract_approval', $decision['type']);
        self::assertSame('human_required', $decision['authority']);
        self::assertSame('advisory_only', $decision['recommendation']['authority']);
        self::assertTrue($decision['recommendation']['human_decision_required']);
        self::assertSame('abstain', $decision['recommendation']['disposition']);
    }

    private function configure(string $mode, string $classification): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'interaction' => [
                    'decision_advice' => [
                        'mode' => $mode,
                        'provider' => 'system_one',
                        'endpoint' => 'http://127.0.0.1:9999/v1/systemone',
                        'allow_loopback' => true,
                        'data_classification' => $classification,
                        'timeout_ms' => 300,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $child = $entry->getPathname();
            if ($entry->isDir()) {
                $this->deleteDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}

final class FakeDecisionModel implements DecisionModel
{
    public string $observedState = '';

    public function __construct(
        private ?string $choice,
        private bool $fail = false,
    ) {
    }

    /** @param non-empty-array<string, non-empty-string> $criteria */
    public function decide(string $publicState, array $criteria, ?string $model): DecisionChoiceResult
    {
        $this->observedState = $publicState;
        if ($this->fail) {
            throw new \RuntimeException('Private provider failure should not leak.');
        }
        if ($this->choice !== null && !isset($criteria[$this->choice])) {
            throw new \UnexpectedValueException('Unknown option.');
        }

        return new DecisionChoiceResult($this->choice, $this->choice === null ? null : 0.8);
    }
}
