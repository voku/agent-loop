<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentSession\SessionStore;

final readonly class WorkflowLearningCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
            $contract = (new TaskContractStore($this->rootPath))->load($taskId->value);
            if ($contract->status !== TaskContract::APPROVED) {
                throw new RuntimeException('Learning requires the current durable Task Contract to be approved.');
            }
            $run = (new GovernedRunStore($this->rootPath))->find($taskId->value)
                ?? throw new InvalidArgumentException('No governed Run exists for task ' . $taskId->value . '.');
            if ($run->contractRevision !== $contract->revision) {
                throw new RuntimeException(sprintf(
                    'Learning requires the governed Run to bind current Contract revision %d; Run binds revision %d.',
                    $contract->revision,
                    $run->contractRevision,
                ));
            }

            $sessionRoot = (new ProjectLayout($this->rootPath))->sessionsRoot();
            $session = (new SessionStore())->load($sessionRoot, $run->sessionId);
            if ($session->taskId !== $taskId->value) {
                throw new RuntimeException('Run-bound Session belongs to another task.');
            }

            $boundary = PostExecutionEvidenceBoundary::inspect($this->rootPath, $contract, $session);
            $integrityFailures = $boundary->integrityFailures();
            if ($integrityFailures !== []) {
                throw new RuntimeException('Learning evidence is stale: ' . implode('; ', $integrityFailures));
            }
            $validationEvidenceSha256 = $boundary->validationEvidenceSha256();
            if ($validationEvidenceSha256 === null) {
                throw new RuntimeException('Learning requires current validation evidence for every Contract validation command.');
            }
            $reviewEvidenceSha256 = $boundary->reviewEvidenceSha256();
            if ($reviewEvidenceSha256 === null) {
                throw new RuntimeException('Learning requires a current blind-spot review bound to the current Contract revision and implementation snapshot.');
            }

            $learningRoot = WorkflowLearningRoot::forRun($this->rootPath, $run);
            $decision = (new RunLearningDecisionStore($learningRoot))->record(
                $run->runId,
                $options['status'],
                $options['by'],
                $options['reason'],
                $options['findingIds'],
                $options['followUp'],
                contractRevision: $contract->revision,
                implementationSnapshot: $boundary->implementation->digest,
                validationEvidenceSha256: $validationEvidenceSha256,
                reviewEvidenceSha256: $reviewEvidenceSha256,
            );
            echo "[OK] workflow learn: {$decision->decision->value} recorded for {$run->runId} at implementation {$boundary->implementation->digest}\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow learn: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{status: RunLearningDecisionStatus, by: string, reason: string, findingIds: list<string>, followUp: string|null}
     */
    private function parse(array $tokens): array
    {
        $status = null;
        $by = null;
        $reason = null;
        $followUp = null;
        $findingIds = [];

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!in_array($token, ['--status', '--by', '--reason', '--finding', '--follow-up'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            if ($token === '--status') {
                $status = RunLearningDecisionStatus::tryFrom($value)
                    ?? throw new InvalidArgumentException('--status must be findings_recorded, no_durable_learning, or follow_up_required.');
            } elseif ($token === '--by') {
                $by = $value;
            } elseif ($token === '--reason') {
                $reason = $value;
            } elseif ($token === '--finding') {
                $findingIds[] = $value;
            } elseif ($token === '--follow-up') {
                $followUp = $value;
            }
        }
        if (!$status instanceof RunLearningDecisionStatus || $by === null || $reason === null) {
            throw new InvalidArgumentException('--status, --by, and --reason are required.');
        }

        return [
            'status' => $status,
            'by' => $by,
            'reason' => $reason,
            'findingIds' => $findingIds,
            'followUp' => $followUp,
        ];
    }
}
