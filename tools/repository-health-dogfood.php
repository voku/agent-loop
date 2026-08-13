<?php

declare(strict_types=1);

use voku\AgentLoop\RepositoryHealth;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? dirname(__DIR__) . '/tests/Fixtures/repository-health-2026-08-13.json';
$document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
$repositories = is_array($document['repositories'] ?? null) ? $document['repositories'] : [];
$statuses = [];

foreach ($repositories as $evidence) {
    if (!is_array($evidence)) {
        throw new RuntimeException('Repository evidence must be an object.');
    }
    $result = (new RepositoryHealth())->classify($evidence);
    $statuses[] = $result['status'];
    echo $result['repository'] . ' [' . strtoupper($result['status']) . ']';
    if ($result['findings'] !== []) {
        echo ' ' . implode(', ', $result['findings']);
    }
    echo "\n";
}

exit(in_array('suspicious', $statuses, true) ? 1 : 0);
