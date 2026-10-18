<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentSession\Session;
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
        $selection = $store->select(
            $session,
            $contract->revision,
            $snapshot->digest,
            $contract->validation,
        );

        /** @var array<string, true> $currentPasses */
        $currentPasses = [];
        foreach ($contract->validation as $command) {
            if ($selection->currentFor($command)?->status === ValidationStatus::PASSED) {
                $currentPasses[$command] = true;
            }
        }

        $diagStore = new ValidationDiagnosticStore($this->rootPath);

        foreach ($contract->validation as $command) {
            if ($currentPasses[$command] ?? false) {
                continue;
            }

            $started = hrtime(true);
            $execResult = (new DeclaredValidationShell())->executeDeclaredValidationShell($command, $this->rootPath);
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

            $store->record(
                $session,
                $contract->revision,
                $command,
                $exitCode === 0 ? ValidationStatus::PASSED : ValidationStatus::FAILED,
                $exitCode,
                $durationMs,
                self::RECORDED_BY,
                implementationSnapshot: $snapshot->digest,
            );

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

            $currentPasses[$command] = true;
        }

        $diagStore->clear($contract->taskId);
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
