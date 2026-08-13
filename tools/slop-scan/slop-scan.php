#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Entry point for the isolated slop-scan tool project.
 *
 * `voku/slop-scan` 0.1.4 ships a bin that resolves its autoloader as
 * `__DIR__ . '/../vendor/autoload.php'`. That path only exists in a standalone
 * checkout of slop-scan: installed as a Composer dependency the file sits in
 * `vendor/voku/slop-scan/bin/`, and the bin exits with "Composer autoload file
 * not found" even though Composer's own proxy already published the correct
 * location in `$GLOBALS['_composer_autoload_path']`.
 *
 * The upstream README documents the PHAR, which is why the Composer install
 * path went unnoticed. Delete this runner once the bin honors that global.
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(\STDERR, "Run: composer install --working-dir=tools/slop-scan\n");

    exit(1);
}

require $autoload;

exit((new \SlopScan\Console\SlopScanApplication())->run());
