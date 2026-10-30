<?php

declare(strict_types=1);

/**
 * Exact-candidate, bounded tests-of-tests experiment.
 *
 * Run only in a disposable isolated checkout/worktree after composer install.
 * This verifies suite discrimination for two real behaviors, NOT whether an
 * LLM understands or follows the new TDD instructions.
 */

$root = dirname(__DIR__);
$reportPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--report=')) {
        $reportPath = substr($argument, strlen('--report='));
    } else {
        fwrite(STDERR, "Usage: php tools/tdd-quality-dogfood.php [--report=/absolute/path/report.json]\n");
        exit(2);
    }
}

if (!is_file($root . '/vendor/bin/phpunit')) {
    fwrite(STDERR, "Missing PHPUnit. Install repository dependencies first.\n");
    exit(2);
}

/**
 * @return array{exit_code:int, output:string}
 */
function runCommand(array $command, string $root): array
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'output' => (string) $output . (string) $errors,
    ];
}

/**
 * @return array{exit_code:int, assertion_failure:bool, output_excerpt:string}
 */
function testResult(string $root, string $testFile): array
{
    $run = runCommand([PHP_BINARY, 'vendor/bin/phpunit', '--no-progress', $testFile], $root);

    return [
        'exit_code' => $run['exit_code'],
        'assertion_failure' => str_contains($run['output'], 'Failed asserting'),
        'output_excerpt' => substr(trim($run['output']), -1400),
    ];
}

/**
 * @param array{path:string,test:string,benign_from:string,benign_to:string,fault_from:string,fault_to:string,contract:string} $probe
 * @return array<string,mixed>
 */
function runProbe(string $root, array $probe): array
{
    $file = $root . '/' . $probe['path'];
    $original = file_get_contents($file);
    if (!is_string($original)) {
        throw new RuntimeException('Cannot read real source: ' . $probe['path']);
    }
    $result = [
        'source' => $probe['path'],
        'test' => $probe['test'],
        'contract' => $probe['contract'],
        'original_sha256' => hash('sha256', $original),
    ];

    try {
        $result['baseline'] = testResult($root, $probe['test']);
        if ($result['baseline']['exit_code'] !== 0) {
            throw new RuntimeException('Baseline test did not pass: ' . $probe['test']);
        }

        foreach (['benign', 'fault'] as $kind) {
            $needle = $probe[$kind . '_from'];
            $replacement = $probe[$kind . '_to'];
            if (substr_count($original, $needle) !== 1) {
                throw new RuntimeException($kind . ' probe anchor must match exactly once in ' . $probe['path']);
            }

            $modified = str_replace($needle, $replacement, $original);
            if (file_put_contents($file, $modified) === false) {
                throw new RuntimeException('Cannot apply ' . $kind . ' probe');
            }
            $lint = runCommand([PHP_BINARY, '-l', $file], $root);
            if ($lint['exit_code'] !== 0) {
                throw new RuntimeException($kind . ' probe produced invalid PHP: ' . $lint['output']);
            }

            $result[$kind] = testResult($root, $probe['test']);
            if (file_put_contents($file, $original) === false) {
                throw new RuntimeException('Cannot restore ' . $probe['path']);
            }

            if ($kind === 'benign' && $result[$kind]['exit_code'] !== 0) {
                throw new RuntimeException('Benign change broke the behavioral test: ' . $probe['test']);
            }
            if ($kind === 'fault' && ($result[$kind]['exit_code'] === 0 || !$result[$kind]['assertion_failure'])) {
                throw new RuntimeException('Behavioral fault was not detected by a PHPUnit assertion: ' . $probe['test']);
            }
        }
    } finally {
        if (file_put_contents($file, $original) === false) {
            throw new RuntimeException('Source restoration failed for ' . $probe['path']);
        }
        $result['restored'] = hash_file('sha256', $file) === hash('sha256', $original);
    }

    $result['restored_baseline'] = testResult($root, $probe['test']);
    $result['passed'] = $result['restored']
        && $result['restored_baseline']['exit_code'] === 0
        && $result['baseline']['exit_code'] === 0
        && $result['benign']['exit_code'] === 0
        && $result['fault']['exit_code'] !== 0
        && $result['fault']['assertion_failure'];

    return $result;
}

$probes = [
    [
        'path' => 'src/Workflow/HumanReviewHtmlRenderer.php',
        'test' => 'tests/HumanReviewHtmlRendererTest.php',
        'contract' => 'Untrusted review findings are HTML escaped, independently of a noncontractual button label.',
        'benign_from' => 'data-severity-filter="warn" aria-pressed="false">Warn</button>',
        'benign_to' => 'data-severity-filter="warn" aria-pressed="false">Warnings</button>',
        'fault_from' => "self::escape(\$finding['message'])",
        'fault_to' => "\$finding['message']",
    ],
    [
        'path' => 'src/Execution/ExecutionProfile.php',
        'test' => 'tests/ExecutionContextIsolationTest.php',
        'contract' => 'Independent correctness review requires a fresh context even after private-variable refactoring.',
        'benign_from' => '[$investigator, $builder, $correctness, $blindspots],',
        'benign_to' => '[ $investigator, $builder, $correctness, $blindspots ],',
        'fault_from' => "self::agent('correctness-review', 'correctness-review', false, ['build'], [\n                        StageOutcome::PASS->value => 'blindspot-review',\n                        StageOutcome::CHANGES_REQUIRED->value => 'build',\n                    ], ExecutionContextPolicy::FRESH_REQUIRED),",
        'fault_to' => "self::agent('correctness-review', 'correctness-review', false, ['build'], [\n                        StageOutcome::PASS->value => 'blindspot-review',\n                        StageOutcome::CHANGES_REQUIRED->value => 'build',\n                    ], ExecutionContextPolicy::REUSE_ALLOWED),",
    ],
];

$report = [
    'schema_version' => '1.0',
    'kind' => 'tdd_suite_discrimination_dogfood',
    'repository' => 'voku/agent-loop',
    'head_commit' => trim(runCommand(['git', 'rev-parse', 'HEAD'], $root)['output']),
    'php_version' => PHP_VERSION,
    'claims' => [
        'suite_discrimination' => 'EXECUTABLE',
        'model_follows_tdd_guidance' => 'NOT_PROVEN',
        'independent_agent_review' => 'NOT_RUN',
    ],
    'scenarios' => [],
];

$errors = [];
foreach ($probes as $probe) {
    try {
        // The scenario is a real project code path, not a synthetic fixture.
        $report['scenarios'][] = runProbe($root, $probe);
    } catch (Throwable $exception) {
        $errors[] = $probe['path'] . ': ' . $exception->getMessage();
        $report['scenarios'][] = ['source' => $probe['path'], 'passed' => false, 'error' => $exception->getMessage()];
    }
}

$report['passed'] = $errors === [] && count($report['scenarios']) === count($probes)
    && array_reduce($report['scenarios'], static fn (bool $carry, array $item): bool => $carry && $item['passed'] === true, true);
$report['errors'] = $errors;

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if ($reportPath !== null) {
    $parent = dirname($reportPath);
    if (!is_dir($parent) && !mkdir($parent, 0o775, true) && !is_dir($parent)) {
        throw new RuntimeException('Cannot create report directory: ' . $parent);
    }
    if (file_put_contents($reportPath, $json . "\n") === false) {
        throw new RuntimeException('Cannot write report: ' . $reportPath);
    }
}
fwrite(STDOUT, $json . "\n");

exit($report['passed'] ? 0 : 1);
