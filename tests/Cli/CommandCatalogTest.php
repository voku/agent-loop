<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Cli;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Cli\CommandCatalog;
use voku\AgentLoop\Cli\CommandGroup;
use voku\AgentLoop\Cli\CommandId;
use voku\AgentLoop\Cli\CommandOwner;
use voku\AgentLoop\Cli\CommandsCommand;
use voku\AgentLoop\Dispatcher;

final class CommandCatalogTest extends TestCase
{
    public function testEveryCommandIdHasExactlyOneCatalogDescriptor(): void
    {
        $all = CommandCatalog::all();
        $cases = CommandId::cases();

        self::assertCount(count($cases), $all);

        foreach ($cases as $case) {
            self::assertArrayHasKey($case->value, $all);
            $descriptor = $all[$case->value];
            self::assertSame($case, $descriptor->id);
            self::assertNotEmpty($descriptor->summary);
            self::assertNotEmpty($descriptor->usage);
        }
    }

    public function testCatalogHasNoDuplicateIds(): void
    {
        $all = CommandCatalog::all();
        $ids = array_keys($all);

        self::assertSame(array_unique($ids), $ids);
    }

    public function testGetThrowsOnNonExistentCommand(): void
    {
        $descriptor = CommandCatalog::get(CommandId::Enter);
        self::assertSame(CommandId::Enter, $descriptor->id);
        self::assertSame(CommandGroup::Workflow, $descriptor->group);
        self::assertSame(CommandOwner::Loop, $descriptor->owner);
    }

    public function testEveryGroupHasAtLeastOneCommand(): void
    {
        foreach (CommandGroup::cases() as $group) {
            $commands = CommandCatalog::byGroup($group);
            self::assertNotEmpty($commands, "CommandGroup {$group->value} must have registered commands.");
            foreach ($commands as $cmd) {
                self::assertSame($group, $cmd->group);
            }
        }
    }

    public function testUsageHelpRendersAllCommandIdsPreventingDrift(): void
    {
        $usage = CommandCatalog::renderUsage();

        foreach (CommandId::cases() as $case) {
            self::assertStringContainsString(
                $case->value,
                $usage,
                "Command '{$case->value}' must appear in the top-level help text to prevent Dispatcher/Catalog drift.",
            );
        }
    }

    public function testCommandsCommandRendersJson(): void
    {
        $cmd = new CommandsCommand();
        ob_start();
        $exit = $cmd->run(['--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);

        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(1, $data['schema_version']);
        self::assertIsArray($data['commands']);
        self::assertCount(count(CommandId::cases()), $data['commands']);

        $ids = array_column($data['commands'], 'id');
        foreach (CommandId::cases() as $case) {
            self::assertContains($case->value, $ids);
        }
    }

    public function testCommandsCommandRendersToon(): void
    {
        $cmd = new CommandsCommand();
        ob_start();
        $exit = $cmd->run(['--format=toon']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('schema_version: 1', $output);
        self::assertStringContainsString('commands[', $output);

        foreach (CommandId::cases() as $case) {
            self::assertStringContainsString($case->value, $output);
        }
    }

    public function testCommandsCommandRendersTextByDefault(): void
    {
        $cmd = new CommandsCommand();
        ob_start();
        $exit = $cmd->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop commands:', $output);
        self::assertStringContainsString('Workflow & Lifecycle:', $output);

        foreach (CommandId::cases() as $case) {
            self::assertStringContainsString($case->value, $output);
        }
    }

    public function testCommandsCommandFailsOnUnknownFormat(): void
    {
        $cmd = new CommandsCommand();
        ob_start();
        $exit = $cmd->run(['--format=xml']);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit);
        self::assertSame('', $output);
    }

    public function testDispatcherRoutesCommandsThroughCommandCatalog(): void
    {
        $dispatcher = new Dispatcher('.');
        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'commands', '--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(1, $data['schema_version']);
    }
}
