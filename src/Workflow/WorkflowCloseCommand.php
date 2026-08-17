<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use ItpContext\Attribute\Rule;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentLoop\Run\RunPolicyEvaluator;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

#[Rule(ArchitectureRules::EvidenceIsNotAuthority)]
final readonly class WorkflowCloseCommand
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
            if ($options['status'] !== 'done') {
                echo "[FAIL] workflow close currently gates only --status done. Use agent-loop session close directly for other statuses.\n";

                return 1;
            }

            $contract = (new TaskContractStore($this->rootPath))->load($taskId->value);
            if ($contract->status !== TaskContract::APPROVED) {
                throw new RuntimeException('Successful close requires the current durable Task Contract to be approved.');
            }
            $run = (new GovernedRunStore($this->rootPath))->find($taskId->value)
                ?? throw new RuntimeException('Successful close requires a governed Run.');
            $session = $this->sessionForRun($run);
            if ($session === null) {
                $receipt = (new RunVerificationReceiptStore($this->rootPath))->find($taskId->value);
                if ($receipt !== null) {
                    $manifestPath = $this->refreshCompleteManifest($taskId->value);
                    echo "[OK] workflow close: Session already pruned; durable close evidence remains at {$manifestPath}\n";

                    return 0;
                }
                throw new RuntimeException('Governed Run Session is missing before final verification evidence was persisted.');
            }

            $readiness = (new WorkflowCloseReadinessInspector($this->rootPath))->inspect($contract, $run, $session);
            foreach ($readiness->passedMessages as $message) {
                echo $message . "\n";
            }
            foreach ($readiness->nonWaivableFailures as $failure) {
                echo "[FAIL] {$failure['gate']}: {$failure['detail']}\n";
            }
            if ($readiness->nonWaivableFailures !== []) {
                $gate = $readiness->nonWaivableFailures[0]['gate'];
                if ($gate === 'contract_binding') {
                    echo "[FAIL] workflow close: accepted risk cannot waive Contract binding; session was not closed.\n";
                } elseif ($gate === 'execution_contract') {
                    echo "[ACTION REQUIRED] Run agent-loop workflow status {$taskId->value} --format=json and satisfy or revise the execution contract. Accepted risk does not bypass this contract gate.\n";
                } else {
                    echo "[FAIL] workflow close: stale post-execution evidence cannot be accepted as risk; session was not closed.\n";
                }

                return 1;
            }

            foreach ($readiness->gateFailures as $failure) {
                echo "[FAIL] {$failure['gate']}: {$failure['detail']}\n";
            }

            $acceptedRisk = $options['acceptRisk'] !== null;
            if (!$acceptedRisk) {
                $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
                $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
                if (!$policy->ordinaryCloseAllowed) {
                    echo "[FAIL] workflow close: lifecycle state is {$policy->state}; session was not closed.\n";
                    if ($policy->nextAction !== 'none') {
                        echo "[ACTION REQUIRED] {$policy->nextAction}\n";
                    }

                    return 1;
                }
                if ($readiness->gateFailures !== []) {
                    throw new RuntimeException(
                        'Canonical lifecycle policy authorized ordinary close while close readiness still reported gate failures.',
                    );
                }
            }

            $boundary = $readiness->boundary
                ?? throw new RuntimeException('Close readiness did not preserve the post-execution evidence boundary.');
            $currentSnapshot = ImplementationSnapshot::capture($this->rootPath, $contract);
            if (!hash_equals($boundary->implementation->digest, $currentSnapshot->digest)) {
                echo "[FAIL] integrity: implementation changed after post-execution evidence was evaluated.\n";
                echo "[FAIL] workflow close: session was not closed.\n";

                return 1;
            }

            if ($acceptedRisk) {
                if ($options['acceptRiskBy'] === null) {
                    echo "[FAIL] workflow close: --accept-risk also requires --accept-risk-by <name>.\n";

                    return 1;
                }
                $path = (new AcceptedRiskWriter($this->rootPath))->write(
                    $taskId->value,
                    $options['acceptRisk'],
                    $options['acceptRiskBy'],
                    $readiness->gateFailures,
                );
                echo "[WARN] workflow close: accepted risk recorded at {$path}\n";
            } else {
                echo "[OK] workflow close: gates passed\n";
            }

            $receipt = (new RunVerificationReceiptStore($this->rootPath))->record(
                $run,
                $contract,
                $session,
                $boundary->implementation->digest,
                $readiness->gateFailures === [] ? 'satisfied' : 'accepted_risk',
                $readiness->validationObligations,
            );
            echo "[OK] workflow close: durable verification receipt persisted at {$receipt->path}\n";

            if (!$session->status->isClosed()) {
                (new SessionStore())->setStatus($session, SessionStatus::DONE);
                echo "[OK] workflow close: disposable Session marked done\n";
            }

            $manifestPath = $this->refreshCompleteManifest($taskId->value);
            echo "[OK] workflow close: final Run projection is complete at {$manifestPath}\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow close: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    private function refreshCompleteManifest(string $taskId): string
    {
        $path = (new RunManifestTransitionWriter($this->rootPath))->write($taskId);
        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId);
        if ($manifest->state !== 'complete') {
            throw new RuntimeException(
                'Final Run projection is not complete after close: ' . $manifest->state . '. Refusing successful close.',
            );
        }

        return $path;
    }

    private function sessionForRun(GovernedRun $run): ?Session
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return null;
        }
        $store = new SessionStore();
        if (!$store->exists($root, $run->sessionId)) {
            return null;
        }
        $session = $store->load($root, $run->sessionId);
        if ($session->taskId !== $run->taskId) {
            throw new RuntimeException('Governed Run Session belongs to another task.');
        }

        return $session;
    }

    /**
     * @param list<string> $tokens
     * @return array{status: string, acceptRisk: string|null, acceptRiskBy: string|null}
     */
    private function parse(array $tokens): array
    {
        $status = null;
        $risk = null;
        $riskBy = null;
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--status', '--accept-risk', '--accept-risk-by'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = trim($tokens[++$i]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            match ($token) {
                '--status' => $status = $value,
                '--accept-risk' => $risk = $value,
                '--accept-risk-by' => $riskBy = $value,
            };
        }
        if ($status === null) {
            throw new InvalidArgumentException('--status done is required.');
        }

        return [
            'status' => $status,
            'acceptRisk' => $risk,
            'acceptRiskBy' => $riskBy,
        ];
    }
}
