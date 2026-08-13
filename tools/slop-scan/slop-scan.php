#!/usr/bin/env php
<?php

declare(strict_types=1);

$toolRoot = __DIR__;
$command = sprintf(
    'composer update voku/slop-scan --with-all-dependencies --working-dir=%s --no-interaction --prefer-dist --no-progress --no-ansi',
    escapeshellarg($toolRoot),
);
passthru($command, $updateExit);
if ($updateExit !== 0) {
    fwrite(\STDERR, "Failed to refresh isolated slop-scan lock.\n");
    exit($updateExit);
}

$lock = $toolRoot . '/composer.lock';
if (in_array('--json', $argv, true)) {
    $contents = file_get_contents($lock);
    if (!is_string($contents)) {
        fwrite(\STDERR, "Could not read generated slop-scan lock.\n");
        exit(1);
    }
    echo $contents;
    exit(0);
}

$autoload = $toolRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(\STDERR, "Run: composer install --working-dir=tools/slop-scan\n");
    exit(1);
}

require $autoload;

exit((new \SlopScan\Console\SlopScanApplication())->run());
