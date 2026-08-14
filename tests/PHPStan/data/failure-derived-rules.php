<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\ProjectLayout;

final class FailureDerivedRulesFixture
{
    public function workflowOption(): string
    {
        return '--learning-root';
    }

    /** @param list<string> $values @return list<string> */
    public function collapsedPhpDoc(array $values): array
    {
        return $values;
    }
}

function retiredStatePath(): string
{
    return 'infra/doc/agent-learning/findings';
}

function shellStringProcessCommand(): void
{
    $pipes = [];
    proc_open('php -v', [], $pipes);
}

function phpChildWithIni(): void
{
    $pipes = [];
    proc_open([PHP_BINARY, '-l', __FILE__], [], $pipes);
}

function discardedProjectLayoutPath(): void
{
    (new ProjectLayout(__DIR__))->learningRoot();
}
