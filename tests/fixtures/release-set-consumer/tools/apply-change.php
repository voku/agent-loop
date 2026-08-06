<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/src/RetryPolicy.php';
$content = file_get_contents($path);
if (!is_string($content)) {
    fwrite(STDERR, "Unable to read RetryPolicy.php.\n");

    exit(1);
}

$old = 'return 100 * $attempt;';
$new = 'return 200 * $attempt;';
if (substr_count($content, $old) !== 1) {
    fwrite(STDERR, "Expected the old retry delay expression exactly once.\n");

    exit(1);
}

$updated = str_replace($old, $new, $content);
if (file_put_contents($path, $updated) === false) {
    fwrite(STDERR, "Unable to write RetryPolicy.php.\n");

    exit(1);
}

echo "fixture edit applied\n";
