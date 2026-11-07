<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\WorkflowRecipeChoiceService;

final class WorkflowRecipeChoiceServiceTest extends TestCase
{
    public function testDefaultDisabledNeverCallsModelOrReadsCandidateCatalog(): void
    {
        $calls = 0;
        $service = new WorkflowRecipeChoiceService(static function (string $prompt) use (&$calls): string {
            ++$calls;

            throw new RuntimeException('Must never run by default');
        });

        // Even invalid, untrusted inputs never get inspected until opt-in.
        $result = $service->evaluate('', ['unregistered-recipe']);

        self::assertSame('disabled', $result['status']);
        self::assertSame('context_only', $result['authority']);
        self::assertNull($result['selected_id']);
        self::assertSame([], $result['candidate_ids']);
        self::assertNull($result['prompt_sha256']);
        self::assertSame(0, $calls);
    }

    public function testOptInUsesReleasedOwnerCatalogAndReturnsAuditableReadOnlyChoice(): void
    {
        $calls = 0;
        $service = new WorkflowRecipeChoiceService(static function (string $prompt) use (&$calls): string {
            ++$calls;
            self::assertStringContainsString('[cand_0] id: "reproduce-before-fix"', $prompt);
            self::assertStringContainsString('[cand_1] id: "adversarial-review"', $prompt);
            self::assertStringContainsString('WORKFLOW_APPLICABILITY_EXPERIMENT', $prompt);

            return '{"selected":"cand_1"}';
        });

        $result = $service->evaluate(
            'Review the implementation for failure modes.',
            ['reproduce-before-fix', 'adversarial-review'],
            enabled: true,
        );

        self::assertSame(1, $calls);
        self::assertSame('selected', $result['status']);
        self::assertSame('adversarial-review', $result['selected_id']);
        self::assertSame('context_only', $result['authority']);
        self::assertSame(['reproduce-before-fix', 'adversarial-review'], $result['candidate_ids']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', (string) $result['prompt_sha256']);
        self::assertSame(hash('sha256', '{"selected":"cand_1"}'), $result['response_sha256']);
        self::assertNull($result['error_type']);
        self::assertArrayNotHasKey('mutation_allowed', $result);
        self::assertArrayNotHasKey('raw_model_response', $result);
    }

    public function testMalformedAndExplicitNoneRemainUnverifiedWithoutExecutionAuthority(): void
    {
        foreach ([
            '{"selected":"none"}',
            '{"selected":"none","selected":"cand_0"}',
            '{"sele\u0063ted":"cand_0"}',
            '{"selected":"cand_999"}',
            'Here is the result: {"selected":"cand_0"}',
            '',
        ] as $raw) {
            $service = new WorkflowRecipeChoiceService(static fn (string $prompt): string => $raw);
            $result = $service->evaluate('Fix a bug after reproducing it.', ['reproduce-before-fix'], enabled: true);
            self::assertSame('no_verified_selection', $result['status']);
            self::assertNull($result['selected_id']);
            self::assertSame('context_only', $result['authority']);
            self::assertSame(hash('sha256', $raw), $result['response_sha256']);
        }
    }

    public function testModelFailureIsExplicitAndDoesNotBecomeAnAbstention(): void
    {
        $service = new WorkflowRecipeChoiceService(
            static function (string $prompt): string {
                throw new RuntimeException('Provider connection failed');
            },
        );
        $result = $service->evaluate('Test before fixing.', ['reproduce-before-fix'], enabled: true);

        self::assertSame('model_error', $result['status']);
        self::assertNull($result['selected_id']);
        self::assertSame(RuntimeException::class, $result['error_type']);
        self::assertNull($result['response_sha256']);
    }

    public function testUnexpectedModelTypeIsReportedWithoutCoercion(): void
    {
        $service = new WorkflowRecipeChoiceService(static fn (string $prompt): int => 1);
        $result = $service->evaluate('Test before fixing.', ['reproduce-before-fix'], enabled: true);

        self::assertSame('invalid_model_output', $result['status']);
        self::assertSame('int', $result['error_type']);
        self::assertNull($result['response_sha256']);
    }

    public function testEmptyCandidatesDoNotInvokeModel(): void
    {
        $service = new WorkflowRecipeChoiceService(static function (string $prompt): string {
            throw new RuntimeException('Empty candidates must not invoke a model');
        });
        $result = $service->evaluate('Test before fixing.', [], enabled: true);
        self::assertSame('no_candidates', $result['status']);
        self::assertSame([], $result['candidate_ids']);
    }

    public function testEnablingWithoutProviderFailsLoudly(): void
    {
        $this->expectException(LogicException::class);
        (new WorkflowRecipeChoiceService())->evaluate('Test a bug.', ['reproduce-before-fix'], enabled: true);
    }

    public function testHostCannotOfferUnknownOrDuplicateOwnerRecipes(): void
    {
        foreach ([
            ['reproduce-before-fix', 'reproduce-before-fix'],
            ['made-up-recipe'],
            ['bad"candidate'],
            array_fill(0, 6, 'reproduce-before-fix'),
            [1 => 'reproduce-before-fix'],
        ] as $ids) {
            $service = new WorkflowRecipeChoiceService(static function (string $prompt): string {
                throw new RuntimeException('Malformed candidates must not invoke model');
            });
            try {
                $service->evaluate('Test first.', $ids, enabled: true); // @phpstan-ignore argument.type (intentional sparse list)
                self::fail('Invalid source candidate IDs were accepted');
            } catch (InvalidArgumentException) {
                // Expected: refuse malformed or unverified candidates before calling the model.
            }
        }
    }

    public function testOversizedOrEmptyIntentIsRejectedBeforeModelCall(): void
    {
        foreach (['   ', str_repeat('x', 4097)] as $intent) {
            $service = new WorkflowRecipeChoiceService(static function (string $prompt): string {
                throw new RuntimeException('Invalid intent must not invoke model');
            });
            try {
                $service->evaluate($intent, ['reproduce-before-fix'], enabled: true);
                self::fail('Invalid task intent was accepted');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
    }
}
