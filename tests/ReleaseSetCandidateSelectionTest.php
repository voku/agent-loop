<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\ReleaseSetCandidateSelection;

final class ReleaseSetCandidateSelectionTest extends TestCase
{
    public function testNoCandidateIsSelectedByDefault(): void
    {
        $selection = new ReleaseSetCandidateSelection([]);

        self::assertSame([], $selection->packages());
        self::assertSame([], $selection->paths());
        self::assertFalse($selection->includes('voku/agent-recall-compiler'));
    }

    public function testOnlyExplicitCandidatesAreSelected(): void
    {
        $selection = new ReleaseSetCandidateSelection([
            'voku/agent-recall-compiler',
            'voku/agent-session',
            'voku/agent-recall-compiler',
        ]);

        self::assertSame([
            'voku/agent-recall-compiler',
            'voku/agent-session',
        ], $selection->packages());
        self::assertSame([
            'voku/agent-recall-compiler' => 'build/candidate-agent-recall-compiler',
            'voku/agent-session' => 'build/candidate-agent-session',
        ], $selection->paths());
        self::assertTrue($selection->includes('voku/agent-recall-compiler'));
        self::assertFalse($selection->includes('voku/agent-learning'));
    }

    public function testUnknownCandidateFailsInsteadOfBeingIgnored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported release-set candidate package');

        new ReleaseSetCandidateSelection(['example/unknown']);
    }
}
