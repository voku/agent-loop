#!/usr/bin/env php
<?php

declare(strict_types=1);

const SLOP_SCAN_VERSION = '0.1.6';
const SLOP_SCAN_PHAR_SHA256 = 'fde0a1e5c1789a06db56f88ab3014aec4aba44617f0181ebae6e0d7ece86c314';
const SLOP_SCAN_PHAR_URL = 'https://github.com/voku/slop-scan/releases/download/0.1.6/slop-scan.phar';

$phar = sys_get_temp_dir() . '/voku-slop-scan-' . SLOP_SCAN_VERSION . '-' . SLOP_SCAN_PHAR_SHA256 . '.phar';
if (!is_file($phar) || hash_file('sha256', $phar) !== SLOP_SCAN_PHAR_SHA256) {
    $contents = file_get_contents(SLOP_SCAN_PHAR_URL);
    if (!is_string($contents)) {
        fwrite(\STDERR, "Could not download slop-scan " . SLOP_SCAN_VERSION . ".\n");
        exit(1);
    }
    if (hash('sha256', $contents) !== SLOP_SCAN_PHAR_SHA256) {
        fwrite(\STDERR, "Downloaded slop-scan PHAR failed SHA-256 verification.\n");
        exit(1);
    }
    if (file_put_contents($phar, $contents, LOCK_EX) !== strlen($contents)) {
        fwrite(\STDERR, "Could not persist verified slop-scan PHAR.\n");
        exit(1);
    }
}

$arguments = array_map('escapeshellarg', array_slice($argv, 1));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phar);
if ($arguments !== []) {
    $command .= ' ' . implode(' ', $arguments);
}
passthru($command, $exitCode);
exit($exitCode);
