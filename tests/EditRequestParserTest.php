<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Edit\EditRequestParser;

final class EditRequestParserTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-edit-parser-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->root);
    }

    public function testParsesDeterministicTargetAwareRequest(): void
    {
        $tokens = [
            'App\\Service\\UserService::save',
            '--map-paths=src,tests',
            '--map-exclude=~(^|/)fixtures(/|$)~',
            '--runner=command',
            '--runner-command=' . PHP_BINARY,
            '--runner-arg=-r',
            '--runner-arg=echo stream_get_contents(STDIN);',
            '--runner-timeout=30',
            '--',
            'Reject inactive users before persistence.',
        ];

        $first = (new EditRequestParser())->parse($this->root, $tokens);
        $second = (new EditRequestParser())->parse($this->root, $tokens);

        self::assertSame($first->taskId, $second->taskId);
        self::assertStringStartsWith('edit-app-service-userservice-save-', $first->taskId);
        self::assertSame('App\\Service\\UserService::save', $first->target);
        self::assertSame(['src', 'tests'], $first->mapPaths);
        self::assertSame(['~(^|/)fixtures(/|$)~'], $first->mapExcludes);
        self::assertSame('command', $first->runner);
        self::assertSame(PHP_BINARY, $first->runnerCommand);
        self::assertSame(['-r', 'echo stream_get_contents(STDIN);'], $first->runnerArguments);
        self::assertSame(30, $first->runnerTimeoutSeconds);
        self::assertSame($this->root . '/.agent-map/php-symbols.json', $first->mapIndex);
        self::assertStringStartsWith($this->root . '/.agent-loop/edit/', $first->outputDirectory);
    }

    public function testRejectsInstructionWithoutSeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires `--`');

        (new EditRequestParser())->parse($this->root, ['App\\Service\\UserService::save', 'Change it.']);
    }

    public function testRejectsMutuallyExclusiveMapPolicies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        (new EditRequestParser())->parse($this->root, [
            'App\\Service\\UserService::save',
            '--rebuild-map',
            '--no-rebuild-map',
            '--',
            'Change it.',
        ]);
    }
}
