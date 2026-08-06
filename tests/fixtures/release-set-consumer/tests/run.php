<?php

declare(strict_types=1);

use Fixture\RequestService;
use Fixture\RetryPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

$service = new RequestService(new RetryPolicy());
$actual = $service->retryDelayForTimeout(2);
if ($actual !== 400) {
    fwrite(STDERR, sprintf("Expected retry delay 400 after the fixture edit, got %d.\n", $actual));

    exit(1);
}

echo "fixture validation passed\n";
