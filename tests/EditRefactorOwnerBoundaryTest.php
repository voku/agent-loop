<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** Loop keeps governance around `edit refactor`; plan decoding, apply and verify belong to voku/agent-edit. */
final class EditRefactorOwnerBoundaryTest extends TestCase
{
    public function testRefactorDirectoryHoldsOnlyTheTwoGovernanceFacades(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__) . '/src/Edit/Refactor/*.php') ?: []);
        sort($files);

        self::assertSame(['RefactorEditCommand.php', 'RefactorVerifyDispatchCommand.php'], $files);
    }

    public function testFacadesCarryNoPlanSemanticsOrMutationPrimitives(): void
    {
        foreach (['src/Edit/Refactor/RefactorEditCommand.php', 'src/Edit/Refactor/RefactorVerifyDispatchCommand.php', 'src/Edit/MethodRenameEditRunner.php'] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $path);

            self::assertDoesNotMatchRegularExpression("/'[a-z_]+_plan'/", $source, $path . ' must not list plan types; agent-edit capabilities own them.');
            foreach (['rename(', 'unlink(', 'file_put_contents(', 'proc_open(', 'hash_file('] as $primitive) {
                self::assertStringNotContainsString($primitive, $source, $path . ' must not mutate or hash source itself.');
            }
        }
    }

    public function testRefactorFacadesRouteThroughAgentEditPublicApi(): void
    {
        $edit = (string) file_get_contents(dirname(__DIR__) . '/src/Edit/Refactor/RefactorEditCommand.php');
        self::assertStringContainsString('voku\\AgentEdit\\Cli\\ApplyCommand', $edit);
        self::assertStringContainsString('voku\\AgentEdit\\EditEngine', $edit);
        self::assertStringContainsString('ExecutionContractStore', $edit);

        $verify = (string) file_get_contents(dirname(__DIR__) . '/src/Edit/Refactor/RefactorVerifyDispatchCommand.php');
        self::assertStringContainsString('voku\\AgentEdit\\Cli\\VerifyCommand', $verify);
    }

    public function testComposerRequiresAgentEditAndAgentEditDoesNotRequireLoop(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayHasKey('voku/agent-edit', $composer['require']);

        $installed = dirname(__DIR__) . '/vendor/voku/agent-edit/composer.json';
        if (is_file($installed)) {
            $edit = json_decode((string) file_get_contents($installed), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($edit);
            self::assertArrayNotHasKey('voku/agent-loop', $edit['require'] ?? []);
        }
    }

    public function testAgentEditIsRequiredAsAStableReleaseWithoutRepositoryOverrides(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        // Candidate wiring (dev constraints, commit-refs, path/package repositories) must not become the published
        // dependency boundary; agent-edit comes from its released version on Packagist.
        self::assertMatchesRegularExpression('/\A\^\d+\.\d+\.\d+\z/', (string) $composer['require']['voku/agent-edit']);
        self::assertArrayNotHasKey('repositories', $composer);
    }
}
