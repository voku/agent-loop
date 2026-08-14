<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use ItpContext\Attribute\Rule;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\AgentLoopVerifier;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidence;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

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

            // Checked before the bypassable gates: accepted risk may waive missing
            // evidence, but it may never close a Run against a Contract revision
            // the Run was not approved for.
            $binding = $this->runContractBindingFailure($run, $contract);
            if ($binding !== null) {
                echo "[FAIL] workflow close: {$binding}\n";
                echo "[FAIL] workflow close: accepted risk cannot waive Contract binding; session was not closed.\n";

                return 1;
            }

            $executionContract = (new ExecutionContractStore($this->rootPath))->inspect($taskId->value);
            $executionContractState = is_string($executionContract['state'] ?? null) ? $executionContract['state'] : 'invalid';
            if (!in_array($executionContractState, ['ready', 'not_required'], true)) {
                fwrite(
                    STDERR,
                    '[FAIL] workflow close: successful close requires a current execution contract when L2 policy is selected; current state is '
                    . $executionContractState
                    . ".\n[ACTION REQUIRED] Run agent-loop workflow status {$taskId->value} --format=json and satisfy or revise the execution contract. Accepted risk does not bypass this contract gate.\n",
                );

                return 1;
            }

            $session = $this->sessionForRun($run);
            if ($session === null) {
                $receipt = (new RunVerificationReceiptStore($this->rootPath))->find($taskId->value);
                if ($receipt !== null) {
                    $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
                    echo "[OK] workflow close: Session already pruned; durable close evidence remains at {$manifestPath}\n";

                    return 0;
                }
                throw new RuntimeException('Governed Run Session is missing before final verification evidence was persisted.');
            }

            $learningRoot = WorkflowLearningRoot::forRun($this->rootPath, $run);
            $validation = $this->validationSnapshot($contract, $session);
            $failures = $this->runGates($contract, $run, $session, $learningRoot, $validation['detail']);
            foreach ($failures as $failure) {
                echo "[FAIL] {$failure['gate']}: {$failure['detail']}\n";
            }
            if ($failures !== [] && $options['acceptRisk'] === null) {
                echo "[FAIL] workflow close: gates failed; session was not closed.\n";

                return 1;
            }

            $acceptedRisk = $options['acceptRisk'] !== null;
            if ($acceptedRisk) {
                if ($options['acceptRiskBy'] === null) {
                    echo "[FAIL] workflow close: --accept-risk also requires --accept-risk-by <name>.\n";

                    return 1;
                }
                $path = (new AcceptedRiskWriter($this->rootPath))->write(
                    $taskId->value,
                    $options['acceptRisk'],
                    $options['acceptRiskBy'],
                    $failures,
                );
                echo "[WARN] workflow close: accepted risk recorded at {$path}\n";
            } else {
                echo "[OK] workflow close: gates passed\n";
            }

            $receipt = (new RunVerificationReceiptStore($this->rootPath))->record(
                $run,
                $contract,
                $session,
                $validation['detail'] === null ? 'satisfied' : 'unsatisfied',
                $validation['obligations'],
            );
            echo "[OK] workflow close: durable verification receipt persisted at {$receipt->path}\n";

            if (!$session->status->isClosed()) {
                (new SessionStore())->setStatus($session, SessionStatus::DONE);
                echo "[OK] workflow close: disposable Session marked done\n";
            }

            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
            echo "[OK] workflow close: final Run projection refreshed at {$manifestPath}\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow close: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param string|null $validationDetail why Contract validation is unsatisfied, or null when every obligation passed
     * @return list<array{gate: string, detail: string}>
     */
    private function runGates(
        TaskContract $contract,
        GovernedRun $run,
        Session $session,
        string $learningRoot,
        ?string $validationDetail,
    ): array {
        $failures = [];
        foreach ([
            'recall' => $this->checkRecallGate($contract->taskId),
            'review' => $this->checkReviewGate($contract->taskId),
            'validation' => $validationDetail,
            'recall_outcomes' => $this->checkRecallOutcomeGate($contract->taskId, $learningRoot),
            'learning_decision' => $this->checkLearningDecisionGate($run, $learningRoot),
            'edit_verification' => $this->checkEditVerificationGate($contract->taskId),
            'verify' => $this->checkVerifyGate($contract->taskId),
        ] as $gate => $detail) {
            if ($detail !== null) {
                $failures[] = ['gate' => $gate, 'detail' => $detail];
            }
        }

        return $failures;
    }

    /**
     * @return array{detail: string|null, obligations: list<array{command: string, status: string, exit_code: int|null, executed_at: string|null, recorded_by: string|null, duration_ms: int|null}>}
     */
    private function validationSnapshot(TaskContract $contract, Session $session): array
    {
        $evidence = (new ValidationEvidenceStore())->all($session);
        $missing = [];
        $obligations = [];
        foreach ($contract->validation as $command) {
            $matching = array_values(array_filter(
                $evidence,
                static fn (ValidationEvidence $item): bool => $item->contractRevision === $contract->revision && $item->command === $command,
            ));
            $latest = $matching === [] ? null : $matching[count($matching) - 1];
            if ($latest?->status !== ValidationStatus::PASSED) {
                $missing[] = $command . ' (' . ($latest === null ? 'no evidence' : $latest->status->value) . ')';
            } else {
                echo "[OK] validation: {$command} (exit {$latest->exitCode}, Contract revision {$contract->revision})\n";
            }
            $obligations[] = [
                'command' => $command,
                'status' => $latest === null ? 'missing' : $latest->status->value,
                'exit_code' => $latest?->exitCode,
                'executed_at' => $latest?->executedAt,
                'recorded_by' => $latest?->recordedBy,
                'duration_ms' => $latest?->durationMs,
            ];
        }

        return [
            'detail' => $missing === [] ? null : 'validation evidence missing or not passed for: ' . implode(', ', $missing),
            'obligations' => $obligations,
        ];
    }

    private function checkRecallGate(string $taskId): ?string
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        $relative = RecallOutputRoot::relativeTo($this->rootPath, $path);
        if (is_file($path)) {
            echo "[OK] recall: found {$relative}\n";

            return null;
        }

        return 'missing ' . $relative;
    }

    private function checkReviewGate(string $taskId): ?string
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $relative = $reader->relativePath($taskId);
        $report = $reader->read($taskId);
        if (!$report['exists']) {
            return 'missing blind-spot report ' . $relative;
        }
        if ($report['invalid']) {
            return 'invalid blind-spot report ' . $relative;
        }
        if ($report['status'] === 'fail') {
            return 'blind-spot report status is fail';
        }
        echo "[OK] review: found {$relative} with status {$report['status']}\n";

        return null;
    }

    private function checkLearningDecisionGate(GovernedRun $run, string $learningRoot): ?string
    {
        $decision = (new RunLearningDecisionStore($learningRoot))->find($run->runId);
        if ($decision === null) {
            return 'missing durable Run learning conclusion';
        }
        echo "[OK] learning decision: {$decision->decision->value} by {$decision->decidedBy}\n";

        return null;
    }

    private function checkRecallOutcomeGate(string $taskId, string $learningRoot): ?string
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        if (!is_file($path)) {
            return 'missing recall metadata for task ' . $taskId;
        }
        try {
            $meta = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'invalid recall metadata for task ' . $taskId;
        }
        if (!is_array($meta)) {
            return 'invalid recall metadata for task ' . $taskId;
        }

        $selected = array_values(array_filter(
            $meta['selected_guidance'] ?? [],
            static fn (mixed $id): bool => is_string($id) && trim($id) !== '',
        ));
        foreach ($meta['selected_constraints'] ?? [] as $constraint) {
            if (is_array($constraint) && is_string($constraint['id'] ?? null) && trim($constraint['id']) !== '') {
                $selected[] = $constraint['id'];
            }
        }
        if ($selected === []) {
            echo "[OK] recall outcomes: no selected guidance requires evaluation\n";

            return null;
        }

        $compilationId = $meta['compilation_id'] ?? null;
        if (!is_string($compilationId) || trim($compilationId) === '') {
            return 'selected guidance without a compilation id';
        }
        // An absent outcomes file is no longer a failure in itself: a Run that
        // withheld every judgement writes no outcome at all. Whether each
        // selection is accounted for is decided below, where the message can
        // name the guidance rather than the file.
        $outcomesPath = rtrim($learningRoot, '/') . '/history/outcomes.jsonl';
        $outcomeLines = [];
        if (is_file($outcomesPath)) {
            $outcomeLines = file($outcomesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($outcomeLines === false) {
                return 'cannot read history/outcomes.jsonl for selected guidance';
            }
        }
        $recorded = [];
        foreach ($outcomeLines as $line) {
            try {
                $outcome = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (
                is_array($outcome)
                && ($outcome['task_id'] ?? null) === $taskId
                && ($outcome['compilation_id'] ?? null) === $compilationId
                && is_string($outcome['guidance_id'] ?? null)
            ) {
                $recorded[$outcome['guidance_id']] = true;
            }
        }
        $selected = array_values(array_unique($selected));
        $selectedSet = array_fill_keys($selected, true);
        $withheld = array_intersect_key(
            $this->declaredWithholdings($learningRoot, $taskId, $compilationId),
            $selectedSet,
        );
        $missing = array_values(array_filter(
            $selected,
            static fn (string $id): bool => !isset($recorded[$id]) && !isset($withheld[$id]),
        ));
        if ($missing !== []) {
            return 'missing explicit recall outcome for: ' . implode(', ', $missing);
        }
        // A judgement is not the only honest close-out. A Run that compiled
        // guidance it never consulted has nothing truthful to say about it, and
        // every available bucket moves a promotion or retirement gate. What this
        // gate owns is that the silence was deliberate: a declared withholding
        // passes, a guidance that simply never appears does not.
        echo '[OK] recall outcomes: ' . (count($selected) - count($withheld)) . ' judged, '
            . count($withheld) . " withheld with a stated reason, for " . count($selected)
            . " selected guidance item(s)\n";

        return null;
    }

    /**
     * Selected guidance whose absent outcome was declared at log time.
     *
     * @return array<string, true>
     */
    private function declaredWithholdings(string $learningRoot, string $taskId, string $compilationId): array
    {
        $path = rtrim($learningRoot, '/') . '/history/recall-selections.jsonl';
        if (!is_file($path)) {
            return [];
        }

        $withheld = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            // An unreadable line yields no withholding, so the selection it
            // described stays unaccounted for and the gate refuses the close.
            // That is the safe direction: a corrupt record must not be able to
            // excuse a missing judgement.
            $event = json_decode($line, true);
            if (
                is_array($event)
                && ($event['task_id'] ?? null) === $taskId
                && ($event['compilation_id'] ?? null) === $compilationId
                && ($event['selected'] ?? null) === true
                && is_string($event['guidance_id'] ?? null)
                && is_string($event['outcome_withheld_reason'] ?? null)
                && trim($event['outcome_withheld_reason']) !== ''
            ) {
                $withheld[$event['guidance_id']] = true;
            }
        }

        return $withheld;
    }

    private function checkEditVerificationGate(string $taskId): ?string
    {
        $bundle = (new ProjectLayout($this->rootPath))->editBundle($taskId);
        if (!is_dir($bundle)) {
            echo "[OK] edit verification: no edit bundle for {$taskId}\n";

            return null;
        }
        $resultFile = $bundle . '/verification-result.json';
        if (!is_file($resultFile)) {
            return 'missing verification-result.json for edit bundle ' . $taskId;
        }
        try {
            $result = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'unreadable verification-result.json for edit bundle ' . $taskId;
        }
        if (!is_array($result) || !is_string($result['status'] ?? null)) {
            return 'verification-result.json without a status for edit bundle ' . $taskId;
        }
        if ($result['status'] !== 'passed') {
            return 'edit verification status ' . $result['status'];
        }
        echo "[OK] edit verification: passed for .agent-loop/edit/{$taskId}\n";

        return null;
    }

    private function checkVerifyGate(string $taskId): ?string
    {
        if ((new AgentLoopVerifier($this->rootPath))->run(['--task-id=' . $taskId]) === 0) {
            echo "[OK] verify: agent-loop verify passed\n";

            return null;
        }

        return 'agent-loop verify failed';
    }

    private function sessionForRun(GovernedRun $run): ?Session
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return null;
        }
        try {
            $session = (new SessionStore())->load($root, $run->sessionId);
        } catch (Throwable) {
            return null;
        }
        if ($session->taskId !== $run->taskId) {
            throw new RuntimeException('Governed Run Session belongs to another task.');
        }

        return $session;
    }

    private function runContractBindingFailure(GovernedRun $run, TaskContract $contract): ?string
    {
        if ($run->taskId !== $contract->taskId || $run->contractRevision !== $contract->revision) {
            return 'Governed Run is not bound to the current Task Contract revision.';
        }
        $hash = hash_file('sha256', $contract->path);
        if ($hash === false || !hash_equals($run->contractSource['sha256'], 'sha256:' . $hash)) {
            return 'Governed Run Contract digest is stale.';
        }

        return null;
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
