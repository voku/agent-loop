<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Edit\EditRequest;

final class EditRequestTest extends TestCase
{
    #[DataProvider('invalidTargetProvider')]
    public function testRejectsMalformedProgrammaticTargets(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Edit target must use Class::method syntax');

        new EditRequest(
            taskId: 'TASK-1',
            target: $target,
            instruction: 'Change it.',
            projectRoot: '/tmp/project',
            recallRoot: '/tmp/project',
            mapIndex: '/tmp/project/map.json',
            mapRoot: '/tmp/project',
            outputDirectory: '/tmp/project/edit',
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidTargetProvider(): iterable
    {
        yield 'missing separator' => ['Demo\\Service'];
        yield 'missing class' => ['::run'];
        yield 'missing method' => ['Demo\\Service::'];
        yield 'multiple separators' => ['Demo::Service::run'];
        yield 'whitespace' => ['Demo\\Service::run now'];
    }

    public function testMechanicalRunnerRequiresBothReplacementLiterals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be supplied together');

        new EditRequest(
            taskId: 'TASK-1',
            target: 'Demo\\Service::run',
            instruction: 'Change it.',
            projectRoot: '/tmp/project',
            recallRoot: '/tmp/project',
            mapIndex: '/tmp/project/map.json',
            mapRoot: '/tmp/project',
            outputDirectory: '/tmp/project/edit',
            runner: 'mechanical',
            replacementOld: 'old',
        );
    }

    public function testAutoRunnerRequiresReplacementLiteralsTogether(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be supplied together');

        new EditRequest(
            taskId: 'TASK-1',
            target: 'Demo\\Service::run',
            instruction: 'Change it.',
            projectRoot: '/tmp/project',
            recallRoot: '/tmp/project',
            mapIndex: '/tmp/project/map.json',
            mapRoot: '/tmp/project',
            outputDirectory: '/tmp/project/edit',
            runner: 'auto',
            replacementNew: 'new',
        );
    }

    public function testRenameMethodOptionRequiresMethodRenameRunner(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--rename-method requires --runner=method-rename');

        new EditRequest(
            taskId: 'TASK-1',
            target: 'Demo\\Service::run',
            instruction: 'Change it.',
            projectRoot: '/tmp/project',
            recallRoot: '/tmp/project',
            mapIndex: '/tmp/project/map.json',
            mapRoot: '/tmp/project',
            outputDirectory: '/tmp/project/edit',
            replacementMethod: 'persist',
        );
    }

    public function testMethodRenameReplacementIsRecordedInRequestEvidence(): void
    {
        $request = new EditRequest(
            taskId: 'TASK-1',
            target: 'Demo\\Service::run',
            instruction: 'Rename it.',
            projectRoot: '/tmp/project',
            recallRoot: '/tmp/project',
            mapIndex: '/tmp/project/map.json',
            mapRoot: '/tmp/project',
            outputDirectory: '/tmp/project/edit',
            runner: 'method-rename',
            replacementMethod: 'persist',
        );

        $data = $request->toArray();
        self::assertIsArray($data['runner']);
        self::assertSame('persist', $data['runner']['replacement_method']);
    }
}
