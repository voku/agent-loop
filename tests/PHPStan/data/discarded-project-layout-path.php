<?php

declare(strict_types=1);

namespace voku\AgentLoop\Fixture;

use voku\AgentLoop\ProjectLayout;

function discardedProjectLayoutPath(): void
{
    (new ProjectLayout(__DIR__))->learningRoot();
}
