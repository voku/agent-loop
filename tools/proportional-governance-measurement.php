<?php

declare(strict_types=1);

use voku\AgentLoop\Dogfood\ProportionalGovernanceMeasurement;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{report: non-empty-string, workspace: non-empty-string} */
function measurementOptions(): array
{
    $options = getopt('', ['report:', 'workspace:']);
    if (!is_array($options)) {
        throw new RuntimeException('Unable to parse measurement options.');
    }

    $report = $options['report'] ?? null;
    $workspace = $options['workspace'] ?? null;
    if (!is_string($report) || trim($report) === '') {
        throw new InvalidArgumentException('Usage: proportional-governance-measurement.php --report=<path> --workspace=<path>.');
    }
    if (!is_string($workspace) || trim($workspace) === '') {
        throw new InvalidArgumentException('Usage: proportional-governance-measurement.php --report=<path> --workspace=<path>.');
    }

    return ['report' => trim($report), 'workspace' => trim($workspace)];
}

/** @return array<string, mixed> */
function readMeasurementReport(string $path): array
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read release-set report: ' . $path);
    }
    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Release-set report is not a JSON object: ' . $path);
    }
    if (($decoded['schema_version'] ?? null) !== '2.0') {
        throw new RuntimeException('Unsupported release-set report schema for Phase-G measurement.');
    }

    return $decoded;
}

/** @param array<string, mixed> $report */
function writeMeasurementReport(string $path, array $report): void
{
    $json = json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Unable to write release-set report: ' . $path);
    }
}

try {
    $options = measurementOptions();
    $report = readMeasurementReport($options['report']);
    $report['governance_measurement'] = (new ProportionalGovernanceMeasurement($options['workspace']))->measure($report);
    writeMeasurementReport($options['report'], $report);

    echo "Proportional-governance measurement added to release-set report.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Proportional-governance measurement failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
