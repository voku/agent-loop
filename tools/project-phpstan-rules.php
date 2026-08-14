<?php

declare(strict_types=1);

/**
 * Project PHPStan rule fixture gate.
 *
 * Was Bash. PHP because the assertion is string matching over a child
 * process's output, which is what this language does without quoting rules
 * that differ per platform - and because a PHP runner can be unit tested.
 */

use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Dogfood\ProjectRuleFixtureCheck;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$result = (new ProjectRuleFixtureCheck(new ProcessRunner($root)))->run();

echo rtrim($result['output']), "\n";

foreach ($result['problems'] as $problem) {
    fwrite(STDERR, $problem . "\n");
}

if (!$result['passed']) {
    exit(1);
}

echo "Project PHPStan rule dogfood: PASSED\n";
