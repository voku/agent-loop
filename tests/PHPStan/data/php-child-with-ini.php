<?php

declare(strict_types=1);

namespace voku\AgentLoop\Fixture;

function phpChildWithIni(): void
{
    $pipes = [];
    proc_open([PHP_BINARY, '-l', __FILE__], [], $pipes);
}
