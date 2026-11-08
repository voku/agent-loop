<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\OperatingPromptCatalog;
use voku\AgentRecallCompiler\Selection\BoundedChoicePromptBuilder;
use voku\AgentRecallCompiler\Selection\BoundedChoiceValidator;

/**
 * Consumer contract for the released (not experimental) Recall 0.26 API.
 *
 * No agent-loop runtime routing or model call is enabled by this test.
 */
final class ReleasedBoundedChoiceCoreIntegrationTest extends TestCase
{
    public function testReleasedOwnerCatalogCanBuildAndValidateAnOptInReadOnlyChoice(): void
    {
        $recipe = OperatingPromptCatalog::bundled()->recipe('reproduce-before-fix');
        $candidates = [[
            'id' => $recipe->id,
            'title' => $recipe->title,
            'description' => $recipe->description,
            'purpose' => $recipe->purpose,
        ]];
        $builder = new BoundedChoicePromptBuilder();
        $validator = new BoundedChoiceValidator();

        $defaultPrompt = $builder->build('Reproduce this bug before fixing it.', $candidates);
        $optInPrompt = $builder->build(
            'Reproduce this bug before fixing it.',
            $candidates,
            enforceWorkflowApplicability: true,
        );

        self::assertStringContainsString('[cand_0] id: "reproduce-before-fix"', $defaultPrompt);
        self::assertStringNotContainsString('WORKFLOW_APPLICABILITY_EXPERIMENT', $defaultPrompt);
        self::assertStringContainsString('WORKFLOW_APPLICABILITY_EXPERIMENT', $optInPrompt);
        self::assertSame($recipe->id, $validator->validate('{"selected":"cand_0"}', [$recipe->id]));
        self::assertNull($validator->validate('{"selected":"none"}', [$recipe->id]));
        self::assertNull(
            $validator->validate('{"selected":"none","selected":"cand_0"}', [$recipe->id]),
        );
        self::assertNotEmpty($recipe->intents);
    }
}
