<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class RecallEditBriefingCompilerOwnerBoundaryTest extends TestCase
{
    public function testEditCompilationUsesTypedRecallOwnerApiWithoutPrivateOutputParsing(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Edit/RecallEditBriefingCompiler.php');
        self::assertIsString($source);

        self::assertStringContainsString('RecallCompiler', $source);
        self::assertStringContainsString('CompileRequest', $source);
        self::assertStringContainsString('InlineCompileTask', $source);
        self::assertStringContainsString('systemPath()', $source);
        self::assertStringContainsString('validationPlanPath()', $source);
        self::assertStringNotContainsString('Command\\CompileCommand', $source);
        self::assertStringNotContainsString("'--root'", $source);
        self::assertStringNotContainsString("'/meta.json'", $source);
        self::assertStringNotContainsString('output_hashes', $source);
    }

    public function testComposerRequiresReleasedRecallOwnerApiBoundary(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($composer);
        self::assertSame('^0.17.0', $composer['require']['voku/agent-recall-compiler'] ?? null);
    }
}
