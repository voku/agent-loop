<?php

declare(strict_types=1);

/**
 * Deterministic host-facing dogfood for the minimal enter/finish journey.
 *
 * The tool does not simulate an LLM and does not repair workflow prerequisites.
 * It observes the front doors before and after explicit fixture setup of the
 * existing owner artifacts, then records the friction of those four host calls.
 */

use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

const TASK = 'HOST-FRONT-DOOR';
const ACTOR = 'host-front-door-dogfood';

$reportPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--report=')) {
        fwrite(STDERR, 'Unknown option: ' . $argument . "\n");
        exit(2);
    }
    $reportPath = substr($argument, strlen('--report='));
    if ($reportPath === '') {
        fwrite(STDERR, "--report requires a non-empty path.\n");
        exit(2);
    }
}

$fail = static function (string $message): never {
    throw new RuntimeException($message);
};

$workspace = sys_get_temp_dir() . '/agent-loop-host-front-door-dogfood-' . bin2hex(random_bytes(6));
if (!mkdir($workspace, 0o775, true) && !is_dir($workspace)) {
    fwrite(STDERR, 'Unable to create isolated dogfood workspace: ' . $workspace . "\n");
    exit(1);
}

$removeTree = static function (string $directory): void {
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            if (!rmdir($path)) {
                throw new RuntimeException('Unable to remove dogfood directory: ' . $path);
            }
        } elseif (!unlink($path)) {
            throw new RuntimeException('Unable to remove dogfood file: ' . $path);
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException('Unable to remove dogfood workspace: ' . $directory);
    }
};

/**
 * @param list<string> $arguments
 * @return array{exit: int, stdout: string, stderr: string}
 */
$frontDoor = static function (array $arguments) use ($root, $workspace): array {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $root . '/bin/agent-loop', ...$arguments],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workspace,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start agent-loop front door.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

/**
 * @param array{exit: int, stdout: string, stderr: string} $result
 * @return array<string, mixed>
 */
$jsonOutput = static function (array $result): array {
    if ($result['stdout'] === '') {
        throw new RuntimeException('Front door produced no JSON output: ' . $result['stderr']);
    }
    $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Front door JSON output must be an object.');
    }

    return $decoded;
};

$failure = null;
$encodedReport = null;

try {
    $sourceDirectory = $workspace . '/src';
    if (!mkdir($sourceDirectory, 0o775, true) && !is_dir($sourceDirectory)) {
        $fail('Unable to create fixture source directory.');
    }
    if (file_put_contents($sourceDirectory . '/Greeting.php', "<?php\nfinal class Greeting {}\n") === false) {
        $fail('Unable to create fixture source file.');
    }

    $commands = [];

    $initial = $frontDoor(['enter', TASK, '--format=json']);
    $initialPayload = $jsonOutput($initial);
    $commands[] = [
        'command' => 'enter',
        'phase' => 'unprepared',
        'exit' => $initial['exit'],
        'state' => $initialPayload['manifest']['state'] ?? null,
        'mutation_ready' => $initialPayload['mutation_ready'] ?? null,
        'next_action' => $initialPayload['next_action'] ?? null,
    ];
    if ($initial['exit'] !== 1 || ($initialPayload['mutation_ready'] ?? null) !== false) {
        $fail('Unprepared enter did not fail closed.');
    }
    $initialNext = $initialPayload['next_action'] ?? null;
    if (!is_string($initialNext) || !str_contains($initialNext, 'workflow plan ' . TASK)) {
        $fail('Unprepared enter did not return the canonical plan prerequisite.');
    }
    if (is_dir($workspace . '/.agent-loop')) {
        $fail('Unprepared enter wrote workflow state instead of remaining read-only.');
    }

    $contracts = new TaskContractStore($workspace);
    $contracts->create(
        TASK,
        'Exercise the host-facing enter/finish journey.',
        ['src/Greeting.php'],
        [],
        ['vendor/bin/phpunit'],
        ACTOR,
    );
    $contract = $contracts->approve(TASK, ACTOR . '-approver');
    $snapshot = ImplementationSnapshot::capture($workspace, $contract);

    $sessions = new SessionStore();
    $session = $sessions->create($workspace . '/.agent-loop/sessions', TASK, by: ACTOR);
    $run = (new GovernedRunStore($workspace))->prepare(
        $contract,
        $session,
        $workspace . '/.agent-loop/learning',
    );

    $recallDirectory = $workspace . '/.agent-loop/recall/' . TASK;
    if (!mkdir($recallDirectory, 0o775, true) && !is_dir($recallDirectory)) {
        $fail('Unable to create fixture Recall directory.');
    }
    if (file_put_contents(
        $recallDirectory . '/meta.json',
        json_encode([
            'schema_version' => '1.0',
            'task_id' => TASK,
            'compilation_id' => TASK . '-001',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR),
    ) === false) {
        $fail('Unable to create fixture Recall metadata.');
    }

    $ready = $frontDoor(['enter', TASK, '--format=json', '--max-lines=40', '--max-bytes=4096']);
    $readyPayload = $jsonOutput($ready);
    $contextLines = $readyPayload['context']['lines'] ?? null;
    if (!is_array($contextLines)) {
        $fail('Mutation-ready enter did not return bounded context lines.');
    }
    $contextBytes = strlen(implode("\n", array_map(static fn (mixed $line): string => (string) $line, $contextLines)));
    $commands[] = [
        'command' => 'enter',
        'phase' => 'governed_ready',
        'exit' => $ready['exit'],
        'state' => $readyPayload['manifest']['state'] ?? null,
        'mutation_ready' => $readyPayload['mutation_ready'] ?? null,
        'next_action' => $readyPayload['next_action'] ?? null,
    ];
    if ($ready['exit'] !== 0 || ($readyPayload['mutation_ready'] ?? null) !== true) {
        $fail('Prepared enter did not expose mutation readiness.');
    }
    if (count($contextLines) > 40 || $contextBytes > 4096) {
        $fail('Prepared enter exceeded the requested context budget.');
    }

    $reviewDirectory = $recallDirectory . '/reviews';
    if (!mkdir($reviewDirectory, 0o775, true) && !is_dir($reviewDirectory)) {
        $fail('Unable to create fixture review directory.');
    }
    if (file_put_contents(
        $reviewDirectory . '/' . TASK . '.blindspots.json',
        json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
    ) === false) {
        $fail('Unable to create fixture review evidence.');
    }
    (new RunLearningDecisionStore($workspace . '/.agent-loop/learning'))->record(
        $run->runId,
        RunLearningDecisionStatus::NO_DURABLE_LEARNING,
        ACTOR,
        'No durable learning from the deterministic front-door fixture.',
    );
    (new RunVerificationReceiptStore($workspace))->record(
        $run,
        $contract,
        $session,
        $snapshot->digest,
        'satisfied',
        [[
            'command' => 'vendor/bin/phpunit',
            'status' => 'passed',
            'exit_code' => 0,
            'executed_at' => '2026-08-17T10:00:00+00:00',
            'recorded_by' => ACTOR,
            'duration_ms' => 10,
        ]],
    );

    $premature = $frontDoor(['finish', TASK, '--format=json']);
    $prematurePayload = $jsonOutput($premature);
    $commands[] = [
        'command' => 'finish',
        'phase' => 'ready_to_close',
        'exit' => $premature['exit'],
        'state' => $prematurePayload['manifest']['state'] ?? null,
        'complete' => $prematurePayload['complete'] ?? null,
        'next_action' => $prematurePayload['next_action'] ?? null,
    ];
    if ($premature['exit'] !== 1 || ($prematurePayload['complete'] ?? null) !== false) {
        $fail('Premature finish did not reject the done claim.');
    }
    if (($prematurePayload['manifest']['state'] ?? null) !== 'ready_to_close') {
        $fail('Premature finish did not preserve ready_to_close state.');
    }

    $sessions->setStatus($session, SessionStatus::DONE);

    $final = $frontDoor(['finish', TASK, '--format=json']);
    $finalPayload = $jsonOutput($final);
    $commands[] = [
        'command' => 'finish',
        'phase' => 'complete',
        'exit' => $final['exit'],
        'state' => $finalPayload['manifest']['state'] ?? null,
        'complete' => $finalPayload['complete'] ?? null,
        'next_action' => $finalPayload['next_action'] ?? null,
    ];
    if ($final['exit'] !== 0 || ($finalPayload['complete'] ?? null) !== true) {
        $fail('Final finish did not accept the completed Run.');
    }

    $seenSignatures = [];
    $repeatedSameStateCommands = 0;
    foreach ($commands as $command) {
        $signature = json_encode([
            $command['command'],
            $command['state'],
            $command['mutation_ready'] ?? null,
            $command['complete'] ?? null,
            $command['next_action'] ?? null,
        ], JSON_THROW_ON_ERROR);
        if (isset($seenSignatures[$signature])) {
            ++$repeatedSameStateCommands;
            continue;
        }
        $seenSignatures[$signature] = true;
    }

    $report = [
        'schema_version' => '1.0',
        'task_id' => TASK,
        'journey' => 'enter -> host-native work -> finish',
        'front_door_commands' => count($commands),
        'failed_front_door_commands' => count(array_filter(
            $commands,
            static fn (array $command): bool => $command['exit'] !== 0,
        )),
        'repeated_same_state_commands' => $repeatedSameStateCommands,
        'manual_corrections' => 0,
        'hidden_prerequisite_repairs' => 0,
        'context_lines' => count($contextLines),
        'context_bytes' => $contextBytes,
        'mutation_ready_transition' => [false, true],
        'premature_done_caught' => true,
        'final_complete' => true,
        'commands' => $commands,
    ];

    $encodedReport = json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";

    if ($reportPath !== null) {
        $directory = dirname($reportPath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $fail('Unable to create report directory: ' . $directory);
        }
        if (file_put_contents($reportPath, $encodedReport) === false) {
            $fail('Unable to write dogfood report: ' . $reportPath);
        }
    }
} catch (Throwable $exception) {
    $failure = $exception;
}

try {
    $removeTree($workspace);
} catch (Throwable $cleanupFailure) {
    $failure ??= $cleanupFailure;
}

if ($failure !== null) {
    fwrite(STDERR, 'Host front-door dogfood: FAILED: ' . $failure->getMessage() . "\n");
    exit(1);
}
if (!is_string($encodedReport)) {
    fwrite(STDERR, "Host front-door dogfood: FAILED: report was not produced.\n");
    exit(1);
}

echo $encodedReport;
