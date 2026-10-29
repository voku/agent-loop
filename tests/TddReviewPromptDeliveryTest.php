<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\PackageResources;
use voku\AgentRecallCompiler\Compilation\RecallCompilationService;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentRecallCompiler\Provider\OperatingPromptRecallProvider;
use voku\AgentRecallCompiler\Provider\TaskContextRecallProvider;
use voku\AgentRecallCompiler\RecallRootConfig;
use voku\AgentRecallCompiler\Rendering\OperatingPromptRenderer;
use voku\AgentRecallCompiler\TaskBrief;

/** @internal */
final class TddReviewPromptDeliveryTest extends TestCase
{
    public function testTddOperatingPromptComesFromRecallAndReachesTheRenderedL2Contract(): void
    {
        $recallManifest = dirname(__DIR__)
            . '/vendor/voku/agent-recall-compiler/resources/skills/agent-recall-consumer/operating-prompts.json';
        self::assertFileExists($recallManifest);

        $localManifest = json_decode(
            (string) file_get_contents(PackageResources::operatingPrompts()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($localManifest);
        self::assertArrayHasKey('prompts', $localManifest);
        self::assertNotContains(
            'test-driven-development',
            array_column($localManifest['prompts'], 'id'),
            'Loop must not fork the Recall-owned TDD recipe.',
        );

        $root = sys_get_temp_dir() . '/agent-loop-tdd-recall-delivery-' . bin2hex(random_bytes(6));
        mkdir($root . '/constraints', 0o775, true);
        mkdir($root . '/history', 0o775, true);

        try {
            $task = new TaskBrief(
                id: 'TDD-REVIEW-DELIVERY',
                description: 'Verify that a behavioral change uses Recall-owned TDD guidance.',
                files: ['src/Example.php', 'tests/ExampleTest.php'],
                operatingPrompts: [new OperatingPromptRequest('test-driven-development')],
            );
            $compilation = (new RecallCompilationService([
                new TaskContextRecallProvider(),
                new OperatingPromptRecallProvider([$recallManifest]),
            ]))->compile($task, new RecallRootConfig($root, $root . '/constraints'));

            $rendered = (new OperatingPromptRenderer())->render($compilation->facts);

            // These are transport/ownership contracts, NOT proof that a model
            // designed a good test or executed benign/behavioral probes.
            self::assertStringContainsString('## L2 Operational Prompt Construction', $rendered);
            self::assertStringContainsString('### test-driven-development (L2)', $rendered);
            self::assertStringContainsString('RED', $rendered);
            self::assertStringContainsString('GREEN', $rendered);
            self::assertStringContainsString('REFACTOR', $rendered);
        } finally {
            foreach (['constraints', 'history'] as $directory) {
                rmdir($root . '/' . $directory);
            }
            rmdir($root);
        }
    }
}
