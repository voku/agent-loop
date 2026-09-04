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

final class OperatingPromptPrimitivesTest extends TestCase
{
    public function testBundledCheckpointAutonomyAndMomentumCompileAsDirectL1Controls(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-prompt-primitives-' . bin2hex(random_bytes(6));
        mkdir($root . '/constraints', 0777, true);
        mkdir($root . '/history', 0777, true);

        try {
            $task = new TaskBrief(
                id: 'PROMPT-PRIMITIVES',
                description: 'Exercise the built-in workflow controls.',
                files: ['src/Workflow/WorkflowCli.php'],
                operatingPrompts: [
                    new OperatingPromptRequest('checkpoint-autonomy', [
                        'anchor_point' => 'each independently verifiable repository step',
                    ]),
                    new OperatingPromptRequest('momentum'),
                ],
            );
            $manifest = PackageResources::operatingPrompts();
            $compilation = (new RecallCompilationService([
                new TaskContextRecallProvider(),
                new OperatingPromptRecallProvider([$manifest]),
            ]))->compile($task, new RecallRootConfig($root, $root . '/constraints'));

            $rendered = (new OperatingPromptRenderer())->render($compilation->facts);

            self::assertStringContainsString('## L1 Operating Contract', $rendered);
            self::assertStringContainsString('### checkpoint-autonomy (L1)', $rendered);
            self::assertStringContainsString('each independently verifiable repository step', $rendered);
            self::assertStringContainsString('### momentum (L1)', $rendered);
            self::assertStringContainsString('Do not restart discovery from scratch', $rendered);
            self::assertStringNotContainsString('## L2 Operational Prompt Construction', $rendered);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
