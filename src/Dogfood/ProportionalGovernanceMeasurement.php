<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Derive Phase-G governance-cost observations from the existing installed-consumer report.
 *
 * The measurement layer consumes only already-recorded command/log evidence and public
 * workflow-status projections. It never influences lifecycle policy or authority.
 */
final readonly class ProportionalGovernanceMeasurement
{
    /** @var list<non-empty-string> */
    private const array ORDINARY_SCENARIOS = [
        'workflow.plan',
        'workflow.approve',
        'workflow.implement',
        'workflow.validate',
        'workflow.review',
        'workflow.learn',
        'workflow.close',
        'workflow.prune-replay',
    ];

    /** @var list<non-empty-string> */
    private const array PREPARATION_SCENARIOS = [
        'workflow.plan',
        'workflow.approve',
    ];

    /** @var list<non-empty-string> */
    private const array CAPABILITIES = [
        'map',
        'recall',
        'session',
        'execution_contract',
        'review',
        'learning',
        'verification',
    ];

    /** @var list<non-empty-string> */
    private const array INACTIVE_REFERENCE_STATES = [
        'missing',
        'unavailable',
        'not_configured',
        'not_required',
        'not-required',
        'none',
    ];

    public function __construct(private string $workspace)
    {
        if (!is_dir($this->workspace)) {
            throw new InvalidArgumentException('Measurement workspace is not a directory: ' . $this->workspace);
        }
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array{
     *     schema_version: '1.0',
     *     scope: 'ordinary_installed_consumer',
     *     lifecycle_commands: int<0, max>,
     *     specialist_commands: int<0, max>,
     *     manual_preparation_commands: int<0, max>,
     *     manual_repair_commands: int<0, max>,
     *     repeated_same_state_calls: int<0, max>,
     *     context_lines: int<0, max>,
     *     context_bytes: int<0, max>,
     *     workflow_output_bytes: int<0, max>,
     *     validation_output_bytes: int<0, max>,
     *     capability_activation: array{
     *         map: bool|null,
     *         recall: bool|null,
     *         session: bool|null,
     *         execution_contract: bool|null,
     *         review: bool|null,
     *         learning: bool|null,
     *         verification: bool|null
     *     }
     * }
     */
    public function measure(array $report): array
    {
        $commands = $this->ordinaryCommands($report);
        $frontDoor = $this->arrayField($report, 'front_door_journey');

        $lifecycleCommands = 0;
        $specialistCommands = 0;
        $manualPreparationCommands = 0;
        $manualRepairCommands = 0;
        $workflowOutputBytes = 0;
        $validationOutputBytes = 0;

        foreach ($commands as $entry) {
            $display = $this->display($entry['command']);
            $isLifecycle = $this->isLifecycleCommand($display);
            $isObservation = $this->isObservationCommand($display);
            $isSpecialist = $this->isAgentLoopCommand($display) && !$isLifecycle && !$isObservation;

            if ($isLifecycle) {
                ++$lifecycleCommands;
            }
            if ($isSpecialist) {
                ++$specialistCommands;
            }
            if (
                in_array($entry['scenario'], self::PREPARATION_SCENARIOS, true)
                && $this->isPreparationCommand($display)
            ) {
                ++$manualPreparationCommands;
            }
            if (str_starts_with($entry['scenario'], 'workflow.repair.') && !$isObservation) {
                ++$manualRepairCommands;
            }
            if ($isLifecycle || $isSpecialist) {
                $workflowOutputBytes += $this->commandOutputBytes($entry['command']);
            }
            if ($entry['scenario'] === 'workflow.validate' && !$this->isAgentLoopCommand($display)) {
                $validationOutputBytes += $this->commandOutputBytes($entry['command']);
            }
        }

        return [
            'schema_version' => '1.0',
            'scope' => 'ordinary_installed_consumer',
            'lifecycle_commands' => $lifecycleCommands,
            'specialist_commands' => $specialistCommands,
            'manual_preparation_commands' => $manualPreparationCommands,
            'manual_repair_commands' => $manualRepairCommands,
            'repeated_same_state_calls' => $this->nonNegativeIntField($frontDoor, 'repeated_same_state_commands'),
            'context_lines' => $this->nonNegativeIntField($frontDoor, 'context_lines'),
            'context_bytes' => $this->nonNegativeIntField($frontDoor, 'context_bytes'),
            'workflow_output_bytes' => $workflowOutputBytes,
            'validation_output_bytes' => $validationOutputBytes,
            'capability_activation' => $this->capabilityActivation($commands),
        ];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<array{scenario: non-empty-string, command: array<string, mixed>}>
     */
    private function ordinaryCommands(array $report): array
    {
        $rawScenarios = $report['scenarios'] ?? null;
        if (!is_array($rawScenarios)) {
            throw new InvalidArgumentException('Release-set report is missing scenarios.');
        }

        $commands = [];
        foreach ($rawScenarios as $rawScenario) {
            if (!is_array($rawScenario)) {
                throw new InvalidArgumentException('Release-set report contains a malformed scenario.');
            }
            $scenario = $rawScenario['id'] ?? null;
            if (!is_string($scenario) || $scenario === '' || !in_array($scenario, self::ORDINARY_SCENARIOS, true)) {
                continue;
            }
            $rawCommands = $rawScenario['commands'] ?? null;
            if (!is_array($rawCommands)) {
                throw new InvalidArgumentException('Scenario ' . $scenario . ' is missing its command list.');
            }
            foreach ($rawCommands as $rawCommand) {
                if (!is_array($rawCommand)) {
                    throw new InvalidArgumentException('Scenario ' . $scenario . ' contains a malformed command record.');
                }
                $commands[] = ['scenario' => $scenario, 'command' => $rawCommand];
            }
        }

        return $commands;
    }

    /**
     * @param list<array{scenario: non-empty-string, command: array<string, mixed>}> $commands
     *
     * @return array{
     *     map: bool|null,
     *     recall: bool|null,
     *     session: bool|null,
     *     execution_contract: bool|null,
     *     review: bool|null,
     *     learning: bool|null,
     *     verification: bool|null
     * }
     */
    private function capabilityActivation(array $commands): array
    {
        /** @var array<string, list<string>> $states */
        $states = [];
        foreach (self::CAPABILITIES as $capability) {
            $states[$capability] = [];
        }

        foreach ($commands as $entry) {
            $command = $entry['command'];
            if (!$this->isObservationCommand($this->display($command))) {
                continue;
            }
            $payload = $this->jsonLog($command, 'stdout_log');
            $manifest = $payload['manifest'] ?? null;
            $references = is_array($manifest) ? ($manifest['references'] ?? null) : null;
            if (!is_array($references)) {
                continue;
            }
            foreach (self::CAPABILITIES as $capability) {
                $reference = $references[$capability] ?? null;
                $state = is_array($reference) ? ($reference['state'] ?? null) : null;
                if (is_string($state) && $state !== '') {
                    $states[$capability][] = $state;
                }
            }
        }

        return [
            'map' => $this->activationFromStates($states['map']),
            'recall' => $this->activationFromStates($states['recall']),
            'session' => $this->activationFromStates($states['session']),
            'execution_contract' => $this->activationFromStates($states['execution_contract']),
            'review' => $this->activationFromStates($states['review']),
            'learning' => $this->activationFromStates($states['learning']),
            'verification' => $this->activationFromStates($states['verification']),
        ];
    }

    /** @param list<string> $states */
    private function activationFromStates(array $states): ?bool
    {
        if ($states === []) {
            return null;
        }
        foreach ($states as $state) {
            if (!in_array($state, self::INACTIVE_REFERENCE_STATES, true)) {
                return true;
            }
        }

        return false;
    }

    private function isLifecycleCommand(string $display): bool
    {
        return preg_match(
            '/^vendor\/bin\/agent-loop (?:(?:enter|finish)\b|workflow (?:plan|approve)\b)/',
            $display,
        ) === 1;
    }

    private function isObservationCommand(string $display): bool
    {
        return preg_match('/^vendor\/bin\/agent-loop workflow (?:status|report)\b/', $display) === 1;
    }

    private function isAgentLoopCommand(string $display): bool
    {
        return str_starts_with($display, 'vendor/bin/agent-loop ');
    }

    private function isPreparationCommand(string $display): bool
    {
        return preg_match('/^vendor\/bin\/agent-loop (?:map|recall)\b/', $display) === 1
            || preg_match('/^vendor\/bin\/agent-map (?:build|refresh|search-index)\b/', $display) === 1;
    }

    /** @param array<string, mixed> $command */
    private function display(array $command): string
    {
        $display = $command['display'] ?? null;
        if (!is_string($display) || $display === '') {
            throw new InvalidArgumentException('Release-set command record is missing display text.');
        }

        return $display;
    }

    /** @param array<string, mixed> $command */
    private function commandOutputBytes(array $command): int
    {
        return $this->logBytes($command, 'stdout_log') + $this->logBytes($command, 'stderr_log');
    }

    /** @param array<string, mixed> $command */
    private function logBytes(array $command, string $field): int
    {
        $path = $this->logPath($command, $field);
        $bytes = filesize($path);
        if ($bytes === false) {
            throw new RuntimeException('Unable to measure log bytes: ' . $path);
        }

        return $bytes;
    }

    /**
     * @param array<string, mixed> $command
     *
     * @return array<string, mixed>
     */
    private function jsonLog(array $command, string $field): array
    {
        $path = $this->logPath($command, $field);
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read measurement log: ' . $path);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON measurement log ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Measurement log does not contain a JSON object: ' . $path);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $command */
    private function logPath(array $command, string $field): string
    {
        $relative = $command[$field] ?? null;
        if (!is_string($relative) || $relative === '') {
            throw new InvalidArgumentException('Release-set command record is missing ' . $field . '.');
        }
        $normalized = str_replace('\\', '/', $relative);
        $segments = explode('/', $normalized);
        if (
            str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || in_array('..', $segments, true)
        ) {
            throw new InvalidArgumentException('Unsafe measurement log path: ' . $relative);
        }

        $path = rtrim($this->workspace, '/\\') . '/' . ltrim($normalized, '/');
        if (!is_file($path)) {
            throw new RuntimeException('Measurement log is missing: ' . $path);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function arrayField(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException('Release-set report is missing ' . $field . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function nonNegativeIntField(array $data, string $field): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Release-set report field ' . $field . ' must be a non-negative integer.');
        }

        return $value;
    }
}
