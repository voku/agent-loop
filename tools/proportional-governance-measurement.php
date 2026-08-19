<?php

declare(strict_types=1);

/**
 * Phase-G measurement post-processor for the existing installed release-set dogfood.
 *
 * It derives cost observations only from the runner's recorded command stream,
 * log files, and public workflow-status projections. Nothing here participates
 * in lifecycle policy, authority, or task-complexity classification.
 */

const ORDINARY_SCENARIOS = [
    'workflow.plan',
    'workflow.approve',
    'workflow.implement',
    'workflow.validate',
    'workflow.review',
    'workflow.learn',
    'workflow.close',
    'workflow.prune-replay',
];

const PREPARATION_SCENARIOS = ['workflow.plan', 'workflow.approve'];
const CAPABILITIES = ['map', 'recall', 'session', 'execution_contract', 'review', 'learning', 'verification'];
const INACTIVE_STATES = ['missing', 'unavailable', 'not_configured', 'not_required', 'not-required', 'none'];

/** @return array{report: non-empty-string, workspace: non-empty-string} */
function options(): array
{
    $raw = getopt('', ['report:', 'workspace:']);
    $report = is_array($raw) ? ($raw['report'] ?? null) : null;
    $workspace = is_array($raw) ? ($raw['workspace'] ?? null) : null;
    if (!is_string($report) || trim($report) === '' || !is_string($workspace) || trim($workspace) === '') {
        throw new InvalidArgumentException('Usage: proportional-governance-measurement.php --report=<path> --workspace=<path>.');
    }

    return ['report' => trim($report), 'workspace' => trim($workspace)];
}

/** @return array<string, mixed> */
function jsonFile(string $path): array
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Unable to read JSON file: ' . $path);
    }
    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON file does not contain an object: ' . $path);
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $report
 * @return list<array{scenario: non-empty-string, command: array<string, mixed>}>
 */
function ordinaryCommands(array $report): array
{
    $scenarios = $report['scenarios'] ?? null;
    if (!is_array($scenarios)) {
        throw new InvalidArgumentException('Release-set report is missing scenarios.');
    }

    $result = [];
    foreach ($scenarios as $scenario) {
        if (!is_array($scenario)) {
            throw new InvalidArgumentException('Release-set report contains a malformed scenario.');
        }
        $id = $scenario['id'] ?? null;
        if (!is_string($id) || $id === '' || !in_array($id, ORDINARY_SCENARIOS, true)) {
            continue;
        }
        $commands = $scenario['commands'] ?? null;
        if (!is_array($commands)) {
            throw new InvalidArgumentException('Scenario ' . $id . ' is missing commands.');
        }
        foreach ($commands as $command) {
            if (!is_array($command)) {
                throw new InvalidArgumentException('Scenario ' . $id . ' contains a malformed command.');
            }
            $result[] = ['scenario' => $id, 'command' => $command];
        }
    }

    return $result;
}

/** @param array<string, mixed> $command */
function display(array $command): string
{
    $display = $command['display'] ?? null;
    if (!is_string($display) || $display === '') {
        throw new InvalidArgumentException('Command record is missing display text.');
    }

    return $display;
}

function isLifecycle(string $display): bool
{
    return preg_match('/^vendor\/bin\/agent-loop (?:(?:enter|finish)\b|workflow (?:plan|approve)\b)/', $display) === 1;
}

function isObservation(string $display): bool
{
    return preg_match('/^vendor\/bin\/agent-loop workflow (?:status|report)\b/', $display) === 1;
}

function isSpecialist(string $display): bool
{
    return str_starts_with($display, 'vendor/bin/agent-') && !isLifecycle($display) && !isObservation($display);
}

function isPreparation(string $display): bool
{
    return preg_match('/^vendor\/bin\/(?:agent-loop (?:map|recall)|agent-map (?:build|refresh|search-index))\b/', $display) === 1;
}

/** @param array<string, mixed> $command */
function logPath(string $workspace, array $command, string $field): string
{
    $relative = $command[$field] ?? null;
    if (!is_string($relative) || $relative === '') {
        throw new InvalidArgumentException('Command record is missing ' . $field . '.');
    }
    $normalized = str_replace('\\', '/', $relative);
    if (
        str_starts_with($normalized, '/')
        || preg_match('/^[A-Za-z]:\//', $normalized) === 1
        || in_array('..', explode('/', $normalized), true)
    ) {
        throw new InvalidArgumentException('Unsafe measurement log path: ' . $relative);
    }

    $path = rtrim($workspace, '/\\') . '/' . ltrim($normalized, '/');
    if (!is_file($path)) {
        throw new RuntimeException('Measurement log is missing: ' . $path);
    }

    return $path;
}

/** @param array<string, mixed> $command */
function outputBytes(string $workspace, array $command): int
{
    $bytes = 0;
    foreach (['stdout_log', 'stderr_log'] as $field) {
        $size = filesize(logPath($workspace, $command, $field));
        if ($size === false) {
            throw new RuntimeException('Unable to measure ' . $field . '.');
        }
        $bytes += $size;
    }

    return $bytes;
}

/**
 * @param list<array{scenario: non-empty-string, command: array<string, mixed>}> $commands
 * @return array<string, bool|null>
 */
function capabilityActivation(string $workspace, array $commands): array
{
    /** @var array<string, list<string>> $states */
    $states = array_fill_keys(CAPABILITIES, []);
    foreach ($commands as $entry) {
        if (!isObservation(display($entry['command']))) {
            continue;
        }
        $payload = jsonFile(logPath($workspace, $entry['command'], 'stdout_log'));
        $manifest = $payload['manifest'] ?? null;
        $references = is_array($manifest) ? ($manifest['references'] ?? null) : null;
        if (!is_array($references)) {
            continue;
        }
        foreach (CAPABILITIES as $capability) {
            $reference = $references[$capability] ?? null;
            $state = is_array($reference) ? ($reference['state'] ?? null) : null;
            if (is_string($state) && $state !== '') {
                $states[$capability][] = $state;
            }
        }
    }

    $activation = [];
    foreach (CAPABILITIES as $capability) {
        $observed = $states[$capability];
        $activation[$capability] = $observed === []
            ? null
            : count(array_diff($observed, INACTIVE_STATES)) > 0;
    }

    return $activation;
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function measurement(string $workspace, array $report): array
{
    $frontDoor = $report['front_door_journey'] ?? null;
    if (!is_array($frontDoor)) {
        throw new InvalidArgumentException('Release-set report is missing front_door_journey.');
    }
    foreach (['repeated_same_state_commands', 'context_lines', 'context_bytes'] as $field) {
        if (!is_int($frontDoor[$field] ?? null) || $frontDoor[$field] < 0) {
            throw new InvalidArgumentException('front_door_journey.' . $field . ' must be a non-negative integer.');
        }
    }

    $commands = ordinaryCommands($report);
    $lifecycle = $specialist = $preparation = $repair = $workflowBytes = $validationBytes = 0;
    foreach ($commands as $entry) {
        $display = display($entry['command']);
        if (isLifecycle($display)) {
            ++$lifecycle;
        } elseif (isSpecialist($display)) {
            ++$specialist;
        }
        if (in_array($entry['scenario'], PREPARATION_SCENARIOS, true) && isPreparation($display)) {
            ++$preparation;
        }
        if (str_starts_with($entry['scenario'], 'workflow.repair.') && !isObservation($display)) {
            ++$repair;
        }
        if (str_starts_with($display, 'vendor/bin/agent-') && !isObservation($display)) {
            $workflowBytes += outputBytes($workspace, $entry['command']);
        }
        if ($entry['scenario'] === 'workflow.validate' && !str_starts_with($display, 'vendor/bin/agent-')) {
            $validationBytes += outputBytes($workspace, $entry['command']);
        }
    }

    return [
        'schema_version' => '1.0',
        'scope' => 'ordinary_installed_consumer',
        'lifecycle_commands' => $lifecycle,
        'specialist_commands' => $specialist,
        'manual_preparation_commands' => $preparation,
        'manual_repair_commands' => $repair,
        'repeated_same_state_calls' => $frontDoor['repeated_same_state_commands'],
        'context_lines' => $frontDoor['context_lines'],
        'context_bytes' => $frontDoor['context_bytes'],
        'workflow_output_bytes' => $workflowBytes,
        'validation_output_bytes' => $validationBytes,
        'capability_activation' => capabilityActivation($workspace, $commands),
    ];
}

try {
    $options = options();
    if (!is_dir($options['workspace'])) {
        throw new InvalidArgumentException('Measurement workspace is not a directory: ' . $options['workspace']);
    }
    $report = jsonFile($options['report']);
    if (($report['schema_version'] ?? null) !== '2.0') {
        throw new RuntimeException('Unsupported release-set report schema for Phase-G measurement.');
    }
    $report['governance_measurement'] = measurement($options['workspace'], $report);
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($options['report'], $encoded . "\n") === false) {
        throw new RuntimeException('Unable to write release-set report: ' . $options['report']);
    }
    echo "Proportional-governance measurement added to release-set report.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Proportional-governance measurement failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
