<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\PathResolver;
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
                if ($options['format'] === 'json') {
                    echo json_encode([
                        'schema_version' => '1.0',
                        'command' => 'workflow close',
                        'task_id' => $taskId->value,
                        'status' => 'error',
                        'error' => 'workflow close currently gates only --status done. Use agent-loop session close directly for other statuses.',
                        'next_action_kind' => 'host_work',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                    return 1;
                }

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
                    if ($options['format'] === 'json') {
                        echo json_encode([
                            'schema_version' => '1.0',
                            'command' => 'workflow close',
                            'task_id' => $taskId->value,
                            'status' => 'already_closed',
                            'receipt_path' => PathResolver::relativeTo($this->rootPath, $receipt->path),
                            'manifest_path' => PathResolver::relativeTo($this->rootPath, $manifestPath),
                            'next_action' => 'none',
                            'next_action_kind' => 'none',
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                        return 0;
                    }

                    echo "[OK] workflow close: Session already pruned; durable close evidence remains at {$manifestPath}\n";

                    return 0;
                }
                throw new RuntimeException('Governed Run Session is missing before final verification evidence was persisted.');
            }

            $readiness = (new WorkflowCloseReadinessInspector($this->rootPath))->inspect($contract, $run, $session);
            if ($options['format'] !== 'json') {
                foreach ($readiness->passedMessages as $message) {
                    echo $message . "\n";
                }
                foreach ($readiness->nonWaivableFailures as $failure) {
                    echo "[FAIL] {$failure['gate']}: {$failure['detail']}\n";
                }
            }
            if ($readiness->nonWaivableFailures !== []) {
                $gate = $readiness->nonWaivableFailures[0]['gate'];
                $msg = match ($gate) {
                    'contract_binding' => 'accepted risk cannot waive Contract binding; session was not closed.',
                    'execution_contract' => "Run agent-loop workflow status {$taskId->value} --format=json and satisfy or revise the execution contract. Accepted risk does not bypass this contract gate.",
                    default => 'stale post-execution evidence cannot be accepted as risk; session was not closed.',
                };
                $nextAction = $gate === 'execution_contract'
                    ? "agent-loop workflow status {$taskId->value} --format=json"
                    : 'none';

                if ($options['format'] === 'json') {
                    echo json_encode([
                        'schema_version' => '1.0',
                        'command' => 'workflow close',
                        'task_id' => $taskId->value,
                        'status' => 'error',
                        'error' => $msg,
                        'non_waivable_failures' => $readiness->nonWaivableFailures,
                        'next_action' => $nextAction,
                        'next_action_kind' => 'host_work',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                    return 1;
                }

                if ($gate === 'contract_binding') {
                    echo "[FAIL] workflow close: accepted risk cannot waive Contract binding; session was not closed.\n";
                } elseif ($gate === 'execution_contract') {
                    echo "[ACTION REQUIRED] Run agent-loop workflow status {$taskId->value} --format=json and satisfy or revise the execution contract. Accepted risk does not bypass this contract gate.\n";
                } else {
                    echo "[FAIL] workflow close: stale post-execution evidence cannot be accepted as risk; session was not closed.\n";
                }

                return 1;
            }

            if ($options['format'] !== 'json') {
                foreach ($readiness->gateFailures as $failure) {
                    echo "[FAIL] {$failure['gate']}: {$failure['detail']}\n";
                }
            }

            $acceptedRisk = $options['acceptRisk'] !== null;
            if (!$acceptedRisk) {
                $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
                $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
                if (!$policy->ordinaryCloseAllowed) {
                    if ($options['format'] === 'json') {
                        echo json_encode([
                            'schema_version' => '1.0',
                            'command' => 'workflow close',
                            'task_id' => $taskId->value,
                            'status' => 'error',
                            'error' => "lifecycle state is {$policy->state}; session was not closed.",
                            'gate_failures' => $readiness->gateFailures,
                            'next_action' => $policy->nextAction,
                            'next_action_kind' => $policy->nextActionKind,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                        return 1;
                    }

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
                if ($options['format'] === 'json') {
                    echo json_encode([
                        'schema_version' => '1.0',
                        'command' => 'workflow close',
                        'task_id' => $taskId->value,
                        'status' => 'error',
                        'error' => 'implementation changed after post-execution evidence was evaluated; session was not closed.',
                        'next_action_kind' => 'host_work',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                    return 1;
                }

                echo "[FAIL] integrity: implementation changed after post-execution evidence was evaluated.\n";
                echo "[FAIL] workflow close: session was not closed.\n";

                return 1;
            }

            if ($acceptedRisk) {
                if ($options['acceptRiskBy'] === null) {
                    if ($options['format'] === 'json') {
                        echo json_encode([
                            'schema_version' => '1.0',
                            'command' => 'workflow close',
                            'task_id' => $taskId->value,
                            'status' => 'error',
                            'error' => '--accept-risk also requires --accept-risk-by <name>.',
                            'next_action_kind' => 'host_work',
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                        return 1;
                    }

                    echo "[FAIL] workflow close: --accept-risk also requires --accept-risk-by <name>.\n";

                    return 1;
                }
                $path = (new AcceptedRiskWriter($this->rootPath))->write(
                    $taskId->value,
                    $options['acceptRisk'],
                    $options['acceptRiskBy'],
                    $readiness->gateFailures,
                );
                if ($options['format'] !== 'json') {
                    echo "[WARN] workflow close: accepted risk recorded at {$path}\n";
                }
            } else {
                if ($options['format'] !== 'json') {
                    echo "[OK] workflow close: gates passed\n";
                }
            }

            $receipt = (new RunVerificationReceiptStore($this->rootPath))->record(
                $run,
                $contract,
                $session,
                $boundary->implementation->digest,
                $readiness->gateFailures === [] ? 'satisfied' : 'accepted_risk',
                $readiness->validationObligations,
            );

            if (!$session->status->isClosed()) {
                (new SessionStore())->setStatus($session, SessionStatus::DONE);
            }

            $manifestPath = $this->refreshCompleteManifest($taskId->value);

            if ($options['format'] === 'json') {
                echo json_encode([
                    'schema_version' => '1.0',
                    'command' => 'workflow close',
                    'task_id' => $taskId->value,
                    'status' => 'closed',
                    'accepted_risk' => $acceptedRisk,
                    'receipt_path' => PathResolver::relativeTo($this->rootPath, $receipt->path),
                    'manifest_path' => PathResolver::relativeTo($this->rootPath, $manifestPath),
                    'next_action' => 'none',
                    'next_action_kind' => 'none',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            echo "[OK] workflow close: durable verification receipt persisted at {$receipt->path}\n";
            echo "[OK] workflow close: disposable Session marked done\n";
            echo "[OK] workflow close: final Run projection is complete at {$manifestPath}\n";

            return 0;
        } catch (Throwable $exception) {
            if ($this->isJsonRequested($args)) {
                $candidate = $args[0] ?? null;
                $taskIdVal = is_string($candidate) && !str_starts_with($candidate, '-') ? $candidate : null;
                echo json_encode([
                    'schema_version' => '1.0',
                    'command' => 'workflow close',
                    'task_id' => $taskIdVal,
                    'status' => 'error',
                    'error' => $exception->getMessage(),
                    'next_action_kind' => 'host_work',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                return 1;
            }

            fwrite(STDERR, '[FAIL] workflow close: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    private function refreshCompleteManifest(string $taskId): string
    {
        $path = (new RunManifestTransitionWriter($this->rootPath))->writeRecoveryProjection($taskId);
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
     * @return array{status: string, acceptRisk: string|null, acceptRiskBy: string|null, format: string}
     */
    private function parse(array $tokens): array
    {
        $status = null;
        $risk = null;
        $riskBy = null;
        $format = 'text';
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--json') {
                $format = 'json';
                continue;
            }
            if ($token === '--format=json' || $token === '--format=text') {
                $format = substr($token, 9);
                continue;
            }
            if ($token === '--format') {
                if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                    throw new InvalidArgumentException('--format requires a value.');
                }
                $formatVal = trim($tokens[++$i]);
                if (!in_array($formatVal, ['text', 'json'], true)) {
                    throw new InvalidArgumentException('--format must be "text" or "json".');
                }
                $format = $formatVal;
                continue;
            }
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
            'format' => $format,
        ];
    }

    /** @param list<string> $args */
    private function isJsonRequested(array $args): bool
    {
        foreach ($args as $index => $arg) {
            if ($arg === '--format=json' || $arg === '--json') {
                return true;
            }
            if ($arg === '--format' && ($args[$index + 1] ?? null) === 'json') {
                return true;
            }
        }

        return false;
    }
}
