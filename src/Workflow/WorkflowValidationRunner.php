<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentSession\Session;
use voku\AgentSession\ValidationEvidence;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

/**
 * Executes only the validation obligations already authorized by the approved Contract.
 *
 * The runner observes exit codes and binds them to the exact implementation snapshot.
 * It never upgrades a failed command, invents a command, or accepts risk.
 */
final readonly class WorkflowValidationRunner
{
    private const string RECORDED_BY = 'agent-loop.finish';

    public function __construct(private string $rootPath)
    {
    }

    public function run(TaskContract $contract, GovernedRun $run, Session $session): void
    {
        $this->assertBinding($contract, $run, $session);
        $snapshot = ImplementationSnapshot::capture($this->rootPath, $contract);
        $store = new ValidationEvidenceStore();
        $existing = $store->all($session);

        $diagStore = new ValidationDiagnosticStore($this->rootPath);

        foreach ($contract->validation as $command) {
            if ($this->hasCurrentPass($existing, $contract->revision, $command, $snapshot->digest)) {
                continue;
            }

            $started = hrtime(true);
            $execResult = $this->executeDeclaredValidationShell($command);
            $exitCode = $execResult['exitCode'];
            $output = $execResult['output'];
            $durationMs = max(0, (int) ((hrtime(true) - $started) / 1_000_000));

            $after = ImplementationSnapshot::capture($this->rootPath, $contract);
            if (!hash_equals($snapshot->digest, $after->digest)) {
                throw new RuntimeException(sprintf(
                    "Validation command '%s' changed approved implementation scope while it was being observed; refusing stale validation evidence.",
                    $command,
                ));
            }

            $evidence = $store->record(
                $session,
                $contract->revision,
                $command,
                $exitCode === 0 ? ValidationStatus::PASSED : ValidationStatus::FAILED,
                $exitCode,
                $durationMs,
                self::RECORDED_BY,
                implementationSnapshot: $snapshot->digest,
            );
            $existing[] = $evidence;

            if ($exitCode !== 0) {
                $diag = ValidationDiagnostic::fromExecution(
                    $contract->taskId,
                    $contract->revision,
                    $command,
                    $exitCode,
                    $output,
                );
                $diagStore->record($diag);

                return;
            }
        }

        $diagStore->clear($contract->taskId);
    }

    /** @param list<ValidationEvidence> $evidence */
    private function hasCurrentPass(
        array $evidence,
        int $contractRevision,
        string $command,
        string $implementationSnapshot,
    ): bool {
        foreach (array_reverse($evidence) as $item) {
            if (
                $item->contractRevision === $contractRevision
                && $item->command === $command
                && $item->implementationSnapshot === $implementationSnapshot
            ) {
                return $item->status === ValidationStatus::PASSED;
            }
        }

        return false;
    }

    /** @return array{exitCode: int, output: string} */
    private function executeDeclaredValidationShell(string $command): array
    {
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->rootPath,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute declared validation command: ' . $command);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode < 0) {
            throw new RuntimeException('Declared validation command terminated without an observable exit code: ' . $command);
        }

        $output = trim((is_string($stdout) ? $stdout : '') . "\n" . (is_string($stderr) ? $stderr : ''));

        return [
            'exitCode' => $exitCode,
            'output' => $output,
        ];
    }

    private function assertBinding(TaskContract $contract, GovernedRun $run, Session $session): void
    {
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('Finish-owned validation requires an approved Contract.');
        }
        if ($contract->taskId !== $run->taskId || $session->taskId !== $run->taskId) {
            throw new RuntimeException('Finish-owned validation task lineage does not match the governed Run.');
        }
        if ($contract->revision !== $run->contractRevision) {
            throw new RuntimeException('Finish-owned validation Contract revision does not match the governed Run.');
        }
        if ($session->id !== $run->sessionId || $session->status->isClosed()) {
            throw new RuntimeException('Finish-owned validation requires the active Session bound to the governed Run.');
        }
    }
}
