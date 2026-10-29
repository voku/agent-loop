<?php

declare(strict_types=1);

/**
 * Research-only exact-release consumer benchmark. Never executed by the product.
 *
 * Compare agent-loop's unchanged source tree using its Composer-locked
 * pre-0.22.4 dependencies and its released 0.22.5/graph-0.3.2 dependencies.
 * Separate Composer installs and map output roots give both arms independent
 * PHPStan caches, SQLite graphs and canonical artifacts.
 *
 * Usage: php tools/agent-map-real-consumer-ab.php <baseline> <candidate> <source-fixture>
 */

use PDO;
use RuntimeException;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;

if (count($argv) !== 4) {
    fwrite(STDERR, "Usage: agent-map-real-consumer-ab.php <baseline> <candidate> <source-fixture>\n");
    exit(2);
}

[, $baseline, $candidate, $fixture] = $argv;
require $candidate . '/vendor/autoload.php';

const ROUNDS = 3;

function runMap(string $install, string $fixture, string $index, string $action): array
{
    $cmd = [
        PHP_BINARY,
        $install . '/vendor/bin/agent-map',
        $action,
        '--root=' . $fixture,
        '--backend=phpstan',
        '--phpstan-memory-limit=1024M',
    ];
    if ($action === 'build') {
        $cmd[] = '--paths=src';
        $cmd[] = '--out=' . $index;
    } else {
        $cmd[] = '--index=' . $index;
        $cmd[] = '--out=' . $index;
    }

    $stdoutPath = dirname($index) . '/cli.stdout.txt';
    $stderrPath = dirname($index) . '/cli.stderr.txt';
    $started = hrtime(true);
    $proc = proc_open(
        $cmd,
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
        $pipes,
        $install,
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start exact-release agent-map CLI.');
    }
    $exitCode = proc_close($proc);
    $ms = round((hrtime(true) - $started) / 1_000_000, 3);
    $stdout = (string) file_get_contents($stdoutPath);
    $stderr = (string) file_get_contents($stderrPath);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            '%s %s failed after %.3f ms (exit %d): %s %s',
            basename($install), $action, $ms, $exitCode, trim($stdout), trim($stderr),
        ));
    }
    return ['elapsed_ms' => $ms, 'output' => trim($stdout . "\n" . $stderr)];
}

/** @return array{revision:string,count:int,map:string,relations:string,backend:string} */
function snapshot(string $path): array
{
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('Missing canonical map: ' . $path);
    }
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!str_ends_with((string) ($payload['backend'] ?? ''), '+phpstan')) {
        throw new RuntimeException('Benchmark did not run the required PHPStan backend.');
    }

    $graph = (new MapGraphIndex())->verifyCurrent($path);
    $revision = $graph->sourceRevision();
    if (!is_string($revision) || $revision === '') {
        throw new RuntimeException('Invalid or missing current graph revision.');
    }
    $count = $graph->relationCount();
    if ($count < 100) {
        throw new RuntimeException('Consumer graph unexpectedly small: ' . $count);
    }

    return [
        'revision' => $revision,
        'count' => $count,
        'map' => hash_file('sha256', $path),
        'relations' => hash_file('sha256', MapArtifactPaths::relationsFileFor($path)),
        'backend' => (string) $payload['backend'],
    ];
}

function parity(array $baseline, array $candidate): void
{
    if ($baseline !== $candidate) {
        throw new RuntimeException('Baseline/candidate map, relations or graph identity differ: '
            . json_encode(['baseline' => $baseline, 'candidate' => $candidate], JSON_THROW_ON_ERROR));
    }
}

function median(array $samples): float
{
    if ($samples === []) {
        throw new RuntimeException('No paired samples were recorded.');
    }
    sort($samples, SORT_NUMERIC);
    return round($samples[intdiv(count($samples), 2)], 3);
}

$root = rtrim((string) (getenv('RUNNER_TEMP') ?: sys_get_temp_dir()), '/')
    . '/agent-loop-map-consumer-ab-' . bin2hex(random_bytes(5));
$paths = [
    'baseline' => $root . '/baseline/php-symbols.json',
    'candidate' => $root . '/candidate/php-symbols.json',
];
foreach ($paths as $path) {
    if (!mkdir(dirname($path), 0o775, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Could not create separate map artifact/cache roots.');
    }
}
$installs = ['baseline' => $baseline, 'candidate' => $candidate];
$fixtureFile = $fixture . '/src/Cli/CommandOwner.php';
$source = file_get_contents($fixtureFile);
if (!is_string($source)) {
    throw new RuntimeException('Missing real agent-loop source fixture.');
}

$report = [
    'php' => PHP_VERSION,
    'baseline_dependencies' => ['agent-map' => '0.22.3', 'agent-graph' => '0.3.1'],
    'candidate_dependencies' => ['agent-map' => '0.22.5', 'agent-graph' => '0.3.2'],
    'fixture' => 'actual unchanged voku/agent-loop src; one absolute root; independent Composer installs and PHPStan caches',
    'samples' => ROUNDS,
    'cold_build' => [],
    'no_change_refresh' => [],
    'changed_source_refresh' => [],
    'relation_change' => [],
];

try {
    // Start each package with its own clean metadata, real CLI and PHPStan cache.
    foreach (['baseline', 'candidate'] as $arm) {
        $report['cold_build'][$arm] = runMap($installs[$arm], $fixture, $paths[$arm], 'build');
    }
    $initial = snapshot($paths['baseline']);
    parity($initial, snapshot($paths['candidate']));
    $report['graph_relation_count'] = $initial['count'];

    // An unchanged refresh should not rewrite canonical artifacts.
    foreach (['candidate', 'baseline'] as $arm) {
        $report['no_change_refresh'][$arm] = runMap($installs[$arm], $fixture, $paths[$arm], 'refresh');
        if (snapshot($paths[$arm]) !== $initial) {
            throw new RuntimeException('No-change refresh unexpectedly mutated map/graph.');
        }
    }

    // Verify candidate cannot silently fall back to a full relation replacement
    // when only comments change; the baseline pre-0.22.4 version has no CAS path.
    $candidateDb = MapArtifactPaths::graphDatabaseFor($paths['candidate']);
    $pdo = new PDO('sqlite:' . $candidateDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TRIGGER forbid_relation_delete BEFORE DELETE ON graph_relations "
        . "BEGIN SELECT RAISE(ABORT, 'candidate unnecessarily rebuilt relations'); END");

    $times = ['baseline' => [], 'candidate' => []];
    $oldRevision = $initial['revision'];
    for ($round = 0; $round < ROUNDS; ++$round) {
        // Only this real PHP source changes. The edit is comment-only.
        $changed = file_put_contents($fixtureFile, $source . "\n// Map consumer A/B comment round: $round\n");
        if ($changed === false) {
            throw new RuntimeException('Could not edit temporary source fixture.');
        }
        clearstatcache(true, $fixtureFile);

        $order = $round % 2 === 0 ? ['baseline', 'candidate'] : ['candidate', 'baseline'];
        $observed = [];
        foreach ($order as $arm) {
            $observed[$arm] = runMap($installs[$arm], $fixture, $paths[$arm], 'refresh');
            $times[$arm][] = $observed[$arm]['elapsed_ms'];
        }

        $after = snapshot($paths['baseline']);
        parity($after, snapshot($paths['candidate']));
        if ($after['revision'] === $oldRevision || $after['count'] !== $initial['count']
            || $after['relations'] !== $initial['relations']) {
            throw new RuntimeException('Comment-only round changed relations or failed to advance canonical provenance.');
        }
        $oldRevision = $after['revision'];
        $report['changed_source_refresh'][] = [
            'round' => $round + 1,
            'baseline_ms' => $observed['baseline']['elapsed_ms'],
            'candidate_ms' => $observed['candidate']['elapsed_ms'],
            'map_and_relation_byte_parity' => true,
            'same_verified_graph_revision' => true,
        ];
    }

    // A real graph-changing input must still take the full replacement path.
    // The trigger is benchmark instrumentation, never part of product behavior.
    $pdo->exec('DROP TRIGGER forbid_relation_delete');
    $canary = $fixture . '/src/AgentLoopConsumerPerfCanary.php';
    $canaryCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace voku\AgentLoop;

final class AgentLoopConsumerPerfCanary
{
    public function countSymbols(string $input): int
    {
        return strlen($input);
    }
}
PHP;
    if (file_put_contents($canary, $canaryCode . "\n") === false) {
        throw new RuntimeException('Unable to create temporary relation-changing source.');
    }
    $negative = [];
    foreach (['candidate', 'baseline'] as $arm) {
        $negative[$arm] = runMap($installs[$arm], $fixture, $paths[$arm], 'refresh');
    }
    $changed = snapshot($paths['baseline']);
    parity($changed, snapshot($paths['candidate']));
    if ($changed['count'] <= $initial['count']) {
        throw new RuntimeException('Graph-changing source did not increase graph relation count.');
    }
    $report['relation_change'] = [
        'baseline_ms' => $negative['baseline']['elapsed_ms'],
        'candidate_ms' => $negative['candidate']['elapsed_ms'],
        'after_count' => $changed['count'],
        'before_count' => $initial['count'],
        'full_fallback_and_exact_parity' => true,
    ];

    $beforeMs = median($times['baseline']);
    $afterMs = median($times['candidate']);
    $report['changed_source_median'] = [
        'baseline_ms' => $beforeMs,
        'candidate_ms' => $afterMs,
        'absolute_saved_ms' => round($beforeMs - $afterMs, 3),
        'ratio' => round($beforeMs / max(0.001, $afterMs), 3),
    ];
    $report['invariants'] = [
        'same_absolute_source_root' => true,
        'separate_warmed_phpstan_caches_and_graphs' => true,
        'backend_was_real_phpstan' => true,
        'byte_identical_canonical_and_split_relations' => true,
        'matched_graph_revision_and_integrity' => true,
        'comment_only_changed_source' => true,
        'candidate_delete_block_trigger_survived_all_comment_changes' => true,
        'graph_changing_negative_rebuilt_correctly' => true,
    ];
} finally {
    // Never leak a modified fixture into subsequent CI jobs.
    file_put_contents($fixtureFile, $source);
    @unlink($fixture . '/src/AgentLoopConsumerPerfCanary.php');
}

file_put_contents($root . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    $m = $report['changed_source_median'];
    file_put_contents($summary, sprintf(
        "### Real agent-loop installed-versions A/B (PHP %s)\n\n"
        . "Actual source, %d graph relations, %d paired changed-source rounds; alternating order.\n\n"
        . "| Old locked packages | New locked packages |\n| ---: | ---: |\n"
        . "| %.3f ms median | %.3f ms median |\n\n"
        . "Measured median saving: **%.3f ms** (%.3fx).\n\n"
        . "Canonical/relations byte identity, current graph provenance, no-op and changed-relation fallback: **PASS**.\n\n"
        . "Caveat: source-change refresh, one hosted runner per PHP version; cold is a single sample; "
        . "no evidence of a universal speedup.\n",
        PHP_VERSION,
        $initial['count'],
        ROUNDS,
        $m['baseline_ms'],
        $m['candidate_ms'],
        $m['absolute_saved_ms'],
        $m['ratio'],
    ), FILE_APPEND);
}