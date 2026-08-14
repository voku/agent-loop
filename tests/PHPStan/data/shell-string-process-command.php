<?php

declare(strict_types=1);

namespace voku\AgentLoop\Fixture;

function shellStringProcessCommand(): void
{
    $pipes = [];
    proc_open('php -v', [], $pipes);
}
