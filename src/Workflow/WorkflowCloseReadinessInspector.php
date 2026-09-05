<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLearning\GuidanceOutcomeEventRepository;
use voku\AgentLearning\RecallSelectionEventRepository;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\AgentLoopVerifier;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentSession\Session;
use voku\AgentSession\ValidationStatus;

/**
 * Single read-only owner for the gates that decide whether normal workflow
 * close can succeed. Mutations remain in WorkflowCloseCommand.
 */
final readonly class WorkflowCloseReadinessInspector
{
    public function __construct(private string $rootPath)
    {
    }

    public function inspect(TaskContract $contract, GovernedRun $run, Session $session): WorkflowCloseReadiness
    {
        $nonWaivable = [];

        $bindingFailure = $this->runContractBindingFailure($run, $contract);
        if ($bindingFailure !== null) {
            $nonWaivable[] = ['gate' => 'contract_binding', 'detail' => $bindingFailure];

            return new WorkflowCloseReadiness(null, $nonWaivable, [], [], []);
        }

        $executionContract = (new ExecutionContractStore($this->rootPath))->inspect($contract->taskId);
        $executionContractState = is_string($executionContract['state'] ?? null) ? $executionContract['state'] : 'invalid';
        if (!in_array($executionContractState, ['ready', 'not_required'], true)) {
            $nonWaivable[] = [
                'gate' => 'execution_contract',
                'detail' => 'successful close requires a current execution contract when L2 policy is selected; current state is ' . $executionContractState,
            ];

            return new WorkflowCloseReadiness(null, $nonWaivable, [], [], []);
        }

        $learningRoot = WorkflowLearningRoot::forRun($this->rootPath, $run);
        try {
            $boundary = PostExecutionEvidenceBoundary::inspect($this->rootPath, $contract, $session);
        } catch (ImplementationSnapshotUnavailable $exception) {
            return new WorkflowCloseReadiness(
                null,
                [],
                [['gate' => 'implementation', 'detail' => $exception->getMessage()]],
                [],
                [],
            );
        }
        foreach ($boundary->integrityFailures() as $detail) {
            $nonWaivable[] = ['gate' => 'integrity', 'detail' => $detail];
        }
        $learningBindingFailure = $this->learningBindingFailure($run, $learningRoot, $boundary);
        if ($learningBindingFailure !== null) {
            $nonWaivable[] = ['gate' => 'integrity', 'detail' => $learningBindingFailure];
        }
        if ($nonWaivable !== []) {
            return new WorkflowCloseReadiness($boundary, $nonWaivable, [], [], []);
        }

        $validation = $this->validationSnapshot($contract, $boundary);
        $failures = [];
        $passedMessages = $validation['passed_messages'];

        foreach ([
            'recall' => $this->checkRecallGate($contract->taskId),
            'validation' => ['detail' => $validation['detail'], 'message' => null],
            'review' => $this->checkReviewGate($contract->taskId),
            'recall_outcomes' => $this->checkRecallOutcomeGate($contract, $learningRoot),
            'learning_decision' => $this->checkLearningDecisionGate($run, $learningRoot),
            'edit_verification' => $this->checkEditVerificationGate($contract->taskId),
            'verify' => $this->checkVerifyGate($contract->taskId),
        ] as $gate => $result) {
            if ($result['detail'] !== null) {
                $failures[] = ['gate' => $gate, 'detail' => $result['detail']];
                continue;
            }
            if ($result['message'] !== null) {
                $passedMessages[] = $result['message'];
            }
        }

        return new WorkflowCloseReadiness(
            $boundary,
            [],
            $failures,
            $validation['obligations'],
            $passedMessages,
        );
    }

    /**
     * @return array{
     *   detail: string|null,
     *   obligations: list<array{command: string, status: string, exit_code: int|null, executed_at: string|null, recorded_by: string|null, duration_ms: int|null}>,
     *   passed_messages: list<string>
     * }
     */
    private function validationSnapshot(TaskContract $contract, PostExecutionEvidenceBoundary $boundary): array
    {
        $missing = [];
        $obligations = [];
        $passedMessages = [];
        foreach ($contract->validation as $index => $command) {
            $latest = $boundary->validationEvidence[$index] ?? null;
            if ($latest?->status !== ValidationStatus::PASSED) {
                $missing[] = $command . ' (' . ($latest === null ? 'no current evidence' : $latest->status->value) . ')';
            } else {
                $passedMessages[] = sprintf(
                    '[OK] validation: %s (exit %d, Contract revision %d, implementation %s)',
                    $command,
                    $latest->exitCode,
                    $contract->revision,
                    $boundary->implementation->digest,
                );
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
            'detail' => $missing === [] ? null : 'validation evidence missing or not passed for current implementation: ' . implode(', ', $missing),
            'obligations' => $obligations,
            'passed_messages' => $passedMessages,
        ];
    }

    /** @return array{detail: string|null, message: string|null} */
    private function checkRecallGate(string $taskId): array
    {
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        $reader = new CompiledRecallOutputReader();
        $relative = RecallOutputRoot::relativeTo($this->rootPath, $reader->identityPath($directory));
        try {
            // This gate asks only whether compiled output exists. Metadata that
            // exists but cannot be parsed is still present, and is reported by
            // the outcome gate below rather than as an absence here.
            $found = $reader->read($directory) !== null;
        } catch (RuntimeException) {
            $found = true;
        }
        if ($found) {
            return ['detail' => null, 'message' => '[OK] recall: found ' . $relative];
        }

        return ['detail' => 'missing ' . $relative, 'message' => null];
    }

    /** @return array{detail: string|null, message: string|null} */
    private function checkReviewGate(string $taskId): array
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $relative = $reader->relativePath($taskId);
        $report = $reader->read($taskId);
        if (!$report['exists']) {
            return ['detail' => 'missing blind-spot report ' . $relative, 'message' => null];
        }
        if ($report['invalid']) {
            return ['detail' => 'invalid blind-spot report ' . $relative, 'message' => null];
        }
        if ($report['status'] === 'stale') {
            return ['detail' => 'blind-spot report is stale for the current implementation', 'message' => null];
        }
        if ($report['status'] === 'unacknowledged') {
            return ['detail' => 'blind-spot report has not been acknowledged by exact current report identity', 'message' => null];
        }
        if ($report['status'] === 'fail') {
            return ['detail' => 'blind-spot report status is fail', 'message' => null];
        }
        if (!in_array($report['status'], ['ok', 'warn'], true)) {
            return ['detail' => 'blind-spot report has unsupported lifecycle status', 'message' => null];
        }

        return ['detail' => null, 'message' => '[OK] review: found ' . $relative . ' with status ' . $report['status']];
    }

    /** @return array{detail: string|null, message: string|null} */
    private function checkLearningDecisionGate(GovernedRun $run, string $learningRoot): array
    {
        $decision = (new RunLearningDecisionStore($learningRoot))->find($run->runId);
        if ($decision === null) {
            return ['detail' => 'missing durable Run learning conclusion', 'message' => null];
        }

        return [
            'detail' => null,
            'message' => '[OK] learning decision: ' . $decision->decision->value . ' by ' . $decision->decidedBy,
        ];
    }

    private function learningBindingFailure(
        GovernedRun $run,
        string $learningRoot,
        PostExecutionEvidenceBoundary $boundary,
    ): ?string {
        $decision = (new RunLearningDecisionStore($learningRoot))->find($run->runId);
        if ($decision === null) {
            return null;
        }
        if (
            $decision->contractRevision !== $boundary->implementation->contractRevision
            || $decision->implementationSnapshot !== $boundary->implementation->digest
            || $decision->validationEvidenceSha256 !== $boundary->validationEvidenceSha256()
            || $decision->reviewEvidenceSha256 !== $boundary->reviewEvidenceSha256()
        ) {
            return sprintf(
                'stale Learning decision: current Contract revision %d / implementation %s no longer matches the decision evidence boundary',
                $boundary->implementation->contractRevision,
                $boundary->implementation->digest,
            );
        }

        return null;
    }

    /** @return array{detail: string|null, message: string|null} */
    private function checkRecallOutcomeGate(TaskContract $contract, string $learningRoot): array
    {
        if (in_array('fast_path', $contract->tags, true)) {
            return ['detail' => null, 'message' => '[OK] recall outcomes: waived for fast-path micro-task'];
        }

        $taskId = $contract->taskId;
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        try {
            $output = (new CompiledRecallOutputReader())->read($directory);
        } catch (RuntimeException) {
            return ['detail' => 'invalid recall metadata for task ' . $taskId, 'message' => null];
        }
        if ($output === null) {
            return ['detail' => 'missing recall metadata for task ' . $taskId, 'message' => null];
        }

        $selected = array_values(array_filter(
            [...$output->selectedGuidance(), ...$output->selectedConstraints()],
            static fn (string $id): bool => trim($id) !== '',
        ));
        if ($selected === []) {
            return ['detail' => null, 'message' => '[OK] recall outcomes: no selected guidance requires evaluation'];
        }

        // The owner treats only the empty string as absent. A whitespace-only
        // id would otherwise be compared against outcome records as if it
        // identified a compilation.
        $compilationId = $output->compilationId();
        if ($compilationId === null || trim($compilationId) === '') {
            return ['detail' => 'selected guidance without a compilation id', 'message' => null];
        }

        try {
            $outcomes = (new GuidanceOutcomeEventRepository())->load($learningRoot);
            $recorded = [];
            foreach ($outcomes as $outcome) {
                if ($outcome->taskId === $taskId && $outcome->compilationId === $compilationId) {
                    $recorded[$outcome->guidanceId] = true;
                }
            }

            $selected = array_values(array_unique($selected));
            $selectedSet = array_fill_keys($selected, true);
            $withheld = array_intersect_key(
                $this->declaredWithholdings($learningRoot, $taskId, $compilationId),
                $selectedSet,
            );
        } catch (RuntimeException $exception) {
            return ['detail' => 'invalid Learning recall history: ' . $exception->getMessage(), 'message' => null];
        }

        $missing = array_values(array_filter(
            $selected,
            static fn (string $id): bool => !isset($recorded[$id]) && !isset($withheld[$id]),
        ));
        if ($missing !== []) {
            return ['detail' => 'missing explicit recall outcome for: ' . implode(', ', $missing), 'message' => null];
        }

        return [
            'detail' => null,
            'message' => sprintf(
                '[OK] recall outcomes: %d judged, %d withheld with a stated reason, for %d selected guidance item(s)',
                count($selected) - count($withheld),
                count($withheld),
                count($selected),
            ),
        ];
    }

    /** @return array<string, true> */
    private function declaredWithholdings(string $learningRoot, string $taskId, string $compilationId): array
    {
        $withheld = [];
        foreach ((new RecallSelectionEventRepository())->load($learningRoot) as $event) {
            if (
                $event->taskId === $taskId
                && $event->compilationId === $compilationId
                && $event->selected
                && $event->outcomeWithheldReason !== null
                && trim($event->outcomeWithheldReason) !== ''
            ) {
                $withheld[$event->guidanceId] = true;
            }
        }

        return $withheld;
    }

    /** @return array{detail: string|null, message: string|null} */
    private function checkEditVerificationGate(string $taskId): array
    {
        $bundle = (new ProjectLayout($this->rootPath))->editBundle($taskId);
        if (!is_dir($bundle)) {
            return ['detail' => null, 'message' => '[OK] edit verification: no edit bundle for ' . $taskId];
        }
        $resultFile = $bundle . '/verification-result.json';
        if (!is_file($resultFile)) {
            return ['detail' => 'missing verification-result.json for edit bundle ' . $taskId, 'message' => null];
        }
        try {
            $result = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['detail' => 'unreadable verification-result.json for edit bundle ' . $taskId, 'message' => null];
        }
        if (!is_array($result) || !is_string($result['status'] ?? null)) {
            return ['detail' => 'verification-result.json without a status for edit bundle ' . $taskId, 'message' => null];
        }
        if ($result['status'] !== 'passed') {
            return ['detail' => 'edit verification status ' . $result['status'], 'message' => null];
        }

        return ['detail' => null, 'message' => '[OK] edit verification: passed for .agent-loop/edit/' . $taskId];
    }

    /** @return array{detail: string|null, message: string|null} */
    private function checkVerifyGate(string $taskId): array
    {
        ob_start();
        try {
            $exit = (new AgentLoopVerifier($this->rootPath))->run(['--task-id=' . $taskId]);
        } finally {
            ob_end_clean();
        }
        if ($exit === 0) {
            return ['detail' => null, 'message' => '[OK] verify: final consistency check passed'];
        }

        return ['detail' => 'agent-loop verify failed', 'message' => null];
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
}
