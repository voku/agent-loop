<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Run\RunPolicyEvaluator;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationStatus;

/**
 * Host-facing lifecycle front door for governed coding work.
 *
 * `enter` reconciles deterministic post-approval preparation. `finish` collects
 * deterministic close-out evidence and accepts only explicit authority-bearing
 * review / Learning inputs. Neither command invents approval, judgment, or risk.
 */
final readonly class HostFrontDoorCommand
{
    private const string STDOUT_DISCARD_FILTER = 'agent-loop.stdout-discard';

    private const string STDERR_CAPTURE_FILTER = 'agent-loop.stderr-capture';

    private string $rootPath;

    private ?Closure $recallRunner;

    /** @param null|callable(list<string>): int $recallRunner */
    public function __construct(string $rootPath, ?callable $recallRunner = null)
    {
        $this->rootPath = $rootPath;
        $this->recallRunner = $recallRunner === null ? null : Closure::fromCallable($recallRunner);
    }

    /** @param list<string> $args */
    public function run(string $command, array $args): int
    {
        try {
            return match ($command) {
                'enter' => $this->enter($args),
                'finish' => $this->finish($args),
                default => throw new InvalidArgumentException('Unknown host front-door command: ' . $command),
            };
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, '[FAIL] ' . $command . ': ' . $exception->getMessage() . "\n");

            return 1;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] ' . $command . ': ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $args */
    private function enter(array $args): int
    {
        if ($this->helpRequested($args)) {
            echo "Usage: agent-loop enter <task-id> [--format text|json] [--max-lines N] [--max-bytes N]\n";
            echo "Prepare deterministic post-approval workflow state and project bounded context before host mutation.\n";

            return 0;
        }

        $taskId = new WorkflowTaskId($args[0] ?? '');
        $tokens = array_slice($args, 1);
        $this->validateEnterTokens($tokens);
        $format = $this->format($tokens);
        $maxLines = $this->positiveOption($tokens, 'max-lines', 120);
        $maxBytes = $this->positiveOption($tokens, 'max-bytes', 12000);
        if ($maxLines < 12 || $maxBytes < 512) {
            throw new InvalidArgumentException('Context budgets require at least --max-lines=12 and --max-bytes=512.');
        }

        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
        $preparationFailure = null;
        $preparationWarning = null;
        if ($this->needsPreparation($manifest)) {
            try {
                $preparationWarning = $this->prepareApprovedRun($taskId->value);
            } catch (Throwable $exception) {
                if ($format !== 'json') {
                    throw $exception;
                }
                $preparationFailure = $exception;
            }
            $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
        }

        if ($preparationFailure !== null) {
            // Preparation refused deterministically. Re-running `enter` cannot
            // clear it, so the refusal is carried as a disagreement and routed
            // by the same canonical rule every other owner-reported breakage
            // uses, rather than letting the manifest name `enter` again.
            $manifest = $this->withPreparationDisagreement($manifest, $preparationFailure);
        }

        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
        $context = (new WorkflowContextCommand($this->rootPath))->build($taskId->value, $maxLines, $maxBytes);
        $warnings = $preparationWarning === null ? [] : [[
            'code' => 'enter.ranked_search_unavailable',
            'owner' => 'agent-map',
            'message' => $preparationWarning,
        ]];

        $payload = [
            'schema_version' => '1.0',
            'command' => 'enter',
            'task_id' => $taskId->value,
            'mutation_ready' => $preparationFailure === null && $policy->mutationAllowed,
            'next_action' => $policy->nextAction,
            'next_action_kind' => $policy->nextActionKind,
            'warnings' => $warnings,
            'manifest' => $manifest->toArray(),
            'context' => $context,
        ];
        if ($preparationFailure !== null) {
            $payload['blockers'] = [[
                'code' => 'enter.preparation_failed',
                'owner' => 'agent-loop',
                'message' => $preparationFailure->getMessage(),
            ]];
        }

        if ($format === 'json') {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo 'agent-loop enter ' . $taskId->value . "\n";
            echo 'Run: ' . $manifest->runId . "\n";
            echo 'Mode: ' . $manifest->mode . "\n";
            echo 'State: ' . $policy->state . "\n";
            echo 'Mutation: ' . ($policy->mutationAllowed ? 'ready' : 'not_ready') . "\n";
            echo ($policy->nextActionKind === RunPolicyEvaluation::KIND_HOST_WORK ? 'Next (your change): ' : 'Next: ')
                . $policy->nextAction . "\n";
            foreach ($warnings as $warning) {
                echo '[WARN] ' . $warning['message'] . "\n";
            }
            if ($manifest->disagreements !== []) {
                echo 'Disagreements: ' . count($manifest->disagreements) . "\n";
            }
            echo "\nContext:\n";
            echo implode("\n", $context['lines']) . "\n";
        }

        if ($preparationFailure !== null) {
            return 1;
        }
        if ($policy->state === 'blocked') {
            return 2;
        }
        if (in_array($policy->state, ['ready_to_close', 'complete'], true)) {
            return 0;
        }

        return $policy->mutationAllowed ? 0 : 1;
    }

    /** @param list<string> $args */
    private function finish(array $args): int
    {
        if ($this->helpRequested($args)) {
            echo "Usage: agent-loop finish <task-id> [--format text|json] [--reviewed-report-sha256 SHA --by ACTOR] [--learning STATUS --learning-reason TEXT --by ACTOR] [--finding-observation TEXT --finding-hypothesis TEXT --finding-conclusion TEXT --finding-confidence LEVEL --finding-sensitivity VALUE] [--follow-up-ref REF]\n";
            echo "Reconcile deterministic validation/review evidence, bind explicit judgments, create Learning Findings through their owner, and close when canonical policy permits.\n";

            return 0;
        }

        $taskId = new WorkflowTaskId($args[0] ?? '');
        $tokens = array_slice($args, 1);
        $this->validateFinishTokens($tokens);
        $format = $this->format($tokens);
        $options = $this->finishOptions($tokens);
        $closeoutFailure = null;

        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);

        if ($policy->state !== 'complete') {
            try {
                if ($policy->mutationAllowed) {
                    [$contract, $run, $session] = $this->currentRunContext($taskId->value);
                    (new WorkflowValidationRunner($this->rootPath))->run($contract, $run, $session);
                    $boundary = PostExecutionEvidenceBoundary::inspect($this->rootPath, $contract, $session);
                    if ($this->validationComplete($boundary)) {
                        (new WorkflowReviewPreparer($this->rootPath))->prepare($contract);
                    }
                    $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
                    $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
                } elseif (($manifest->references['review']['state'] ?? null) === 'invalid') {
                    // An unreadable persisted report blocks the Run, but reconciling
                    // the review report is finish's own job. Let the review preparer
                    // refuse to replace it so the failure names the report instead of
                    // dead-ending in a generic blocked state nothing can act on.
                    [$contract] = $this->currentRunContext($taskId->value);
                    (new WorkflowReviewPreparer($this->rootPath))->prepare($contract);
                }

                if ($this->hasCurrentFinishBoundary($manifest)) {
                    [$contract, $run, $session] = $this->currentRunContext($taskId->value);
                    $boundary = PostExecutionEvidenceBoundary::inspect($this->rootPath, $contract, $session);
                    $reviewSha256 = $boundary->reviewEvidenceSha256();
                    $review = (new WorkflowReviewReportReader($this->rootPath))->read($taskId->value);

                    if ($options['reviewedReportSha256'] !== null) {
                        if (
                            $reviewSha256 === null
                            || $review['invalid']
                            || !in_array($review['report_status'], ['ok', 'warn'], true)
                        ) {
                            throw new RuntimeException('Review acknowledgement requires a current non-failing blind-spot report.');
                        }
                        if (!hash_equals($reviewSha256, $options['reviewedReportSha256'])) {
                            throw new RuntimeException('Provided review report identity does not match the exact current blind-spot report.');
                        }
                        if ($options['by'] === null) {
                            throw new RuntimeException('--reviewed-report-sha256 requires --by <actor>.');
                        }
                        (new ReviewAcknowledgementStore($this->rootPath))->record(
                            $run,
                            $contract,
                            $boundary->implementation,
                            $reviewSha256,
                            $options['by'],
                        );
                        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
                        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
                    }

                    if ($options['learning'] !== null) {
                        if ($options['by'] === null || $options['learningReason'] === null) {
                            throw new RuntimeException('--learning requires --by <actor> and --learning-reason <text>.');
                        }
                        if (!in_array($manifest->references['review']['state'] ?? null, ['ok', 'warn'], true)) {
                            throw new RuntimeException('Learning disposition requires acknowledgement of the exact current review report first.');
                        }
                        (new WorkflowLearningRecorder($this->rootPath))->record(
                            $run,
                            $contract,
                            $session,
                            $options['learning'],
                            $options['by'],
                            $options['learningReason'],
                            $options['findingInputs'],
                            $options['followUpRef'],
                        );
                        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
                        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
                    }
                }

                if ($policy->ordinaryCloseAllowed && $policy->state !== 'complete') {
                    $this->closeOrdinaryRun($taskId->value);
                    $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
                    $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
                }
            } catch (Throwable $exception) {
                if ($format !== 'json') {
                    throw $exception;
                }
                $closeoutFailure = $exception;
                $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
                $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
            }
        }

        $complete = $closeoutFailure === null && $policy->state === 'complete';
        $payload = [
            'schema_version' => '1.0',
            'command' => 'finish',
            'task_id' => $taskId->value,
            'complete' => $complete,
            'next_action' => $policy->nextAction,
            'next_action_kind' => $policy->nextActionKind,
            'manifest' => $manifest->toArray(),
        ];
        if ($closeoutFailure !== null) {
            $payload['blockers'] = [[
                'code' => 'finish.closeout_failed',
                'owner' => 'agent-loop',
                'message' => $closeoutFailure->getMessage(),
            ]];
        } elseif ($policy->blockers !== []) {
            $payload['blockers'] = $policy->blockers;
        }

        if ($format === 'json') {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo 'agent-loop finish ' . $taskId->value . "\n";
            echo 'Run: ' . $manifest->runId . "\n";
            echo 'State: ' . $policy->state . "\n";
            echo 'Complete: ' . ($complete ? 'yes' : 'no') . "\n";
            echo ($policy->nextActionKind === RunPolicyEvaluation::KIND_HOST_WORK ? 'Next (your change): ' : 'Next: ')
                . $policy->nextAction . "\n";
        }

        if ($closeoutFailure !== null) {
            return 1;
        }
        if ($complete) {
            return 0;
        }

        return $policy->state === 'blocked' ? 2 : 1;
    }

    private function needsPreparation(RunManifest $manifest): bool
    {
        if (in_array($manifest->state, ['blocked', 'experiment', 'ready_to_close', 'complete'], true)) {
            return false;
        }
        if (($manifest->references['contract']['state'] ?? null) !== TaskContract::APPROVED) {
            return false;
        }
        if (($manifest->references['approval']['state'] ?? null) !== 'current') {
            return false;
        }

        $contractRevision = $manifest->references['contract']['revision'] ?? null;
        $runRevision = $manifest->references['contract']['run_revision'] ?? null;
        $runBindingStale = is_int($contractRevision)
            && is_int($runRevision)
            && $contractRevision !== $runRevision;

        return $manifest->mode !== 'governed'
            || $runBindingStale
            || ($manifest->references['session']['state'] ?? null) !== 'active'
            || ($manifest->references['recall']['state'] ?? null) !== 'compiled';
    }

    private function prepareApprovedRun(string $taskId): ?string
    {
        if ($this->recallRunner === null) {
            throw new RuntimeException('agent-loop enter requires a Recall runner for deterministic governed preparation.');
        }

        $contract = (new TaskContractStore($this->rootPath))->load($taskId);
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('agent-loop enter cannot prepare a Contract that is not approved.');
        }

        $preparer = new WorkflowRunPreparer($this->rootPath);
        $mapReadiness = $preparer->discoveryReadiness($contract);
        $result = $preparer->prepare($contract, $mapReadiness, $this->runRecallQuietly(...));
        if (!$result->recallCompiled()) {
            throw new RuntimeException(
                'Governed Run preparation persisted resumable state, but Recall compilation failed with exit code '
                . $result->recallExitCode . '.' . $this->ownerFailureDetail(),
            );
        }

        return $result->searchWarning;
    }

    private function withPreparationDisagreement(RunManifest $manifest, Throwable $failure): RunManifest
    {
        $disagreements = $manifest->disagreements;
        $disagreements[] = [
            'code' => 'enter.preparation_failed',
            'owner' => 'agent-loop',
            'message' => $failure->getMessage(),
        ];
        $policy = (new RunPolicyEvaluator())->evaluate(
            $manifest->taskId,
            $manifest->mode,
            $manifest->references,
            $disagreements,
        );

        return new RunManifest(
            $manifest->taskId,
            $manifest->runId,
            $manifest->mode,
            $policy->state,
            $manifest->references,
            $disagreements,
            $policy->nextAction,
            $policy->nextActionKind,
        );
    }

    /**
     * Relay what the owning package said about its own refusal. agent-loop does
     * not interpret or re-derive the cause; it only stops discarding it.
     */
    private function ownerFailureDetail(): string
    {
        $captured = trim(NestedStderrCaptureFilter::captured());
        if ($captured === '') {
            return '';
        }

        $lines = [];
        foreach (explode("\n", $captured) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? '' : ' Owner reported: ' . implode(' ', $lines);
    }

    /** @return array{0: TaskContract, 1: GovernedRun, 2: Session} */
    private function currentRunContext(string $taskId): array
    {
        $contract = (new TaskContractStore($this->rootPath))->load($taskId);
        $run = (new GovernedRunStore($this->rootPath))->find($taskId)
            ?? throw new RuntimeException('agent-loop finish requires a governed Run.');
        $session = $this->sessionForRun($run)
            ?? throw new RuntimeException('agent-loop finish requires the active Session bound to the governed Run.');

        if ($contract->status !== TaskContract::APPROVED || $contract->revision !== $run->contractRevision) {
            throw new RuntimeException('agent-loop finish requires the governed Run to match the current approved Contract.');
        }

        return [$contract, $run, $session];
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

    private function validationComplete(PostExecutionEvidenceBoundary $boundary): bool
    {
        foreach ($boundary->validationEvidence as $evidence) {
            if ($evidence === null || $evidence->status !== ValidationStatus::PASSED) {
                return false;
            }
        }

        return true;
    }

    private function hasCurrentFinishBoundary(RunManifest $manifest): bool
    {
        if ($manifest->mode !== 'governed') {
            return false;
        }
        if (($manifest->references['contract']['state'] ?? null) !== TaskContract::APPROVED) {
            return false;
        }
        if (($manifest->references['approval']['state'] ?? null) !== 'current') {
            return false;
        }
        $contractRevision = $manifest->references['contract']['revision'] ?? null;
        $runRevision = $manifest->references['contract']['run_revision'] ?? null;
        if (is_int($contractRevision) && is_int($runRevision) && $contractRevision !== $runRevision) {
            return false;
        }

        return ($manifest->references['session']['state'] ?? null) === 'active'
            && ($manifest->references['recall']['state'] ?? null) === 'compiled'
            && in_array($manifest->references['execution_contract']['state'] ?? null, ['ready', 'not_required'], true);
    }

    private function closeOrdinaryRun(string $taskId): void
    {
        $level = ob_get_level();
        ob_start(static fn (string $buffer): string => '');
        try {
            $exitCode = (new WorkflowCloseCommand($this->rootPath))->run([$taskId, '--status', 'done']);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        if ($exitCode !== 0) {
            throw new RuntimeException('Canonical ordinary close failed after lifecycle policy reported ready_to_close.');
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{
     *   reviewedReportSha256: string|null,
     *   by: string|null,
     *   learning: string|null,
     *   learningReason: string|null,
     *   findingInputs: list<FinishFindingInput>,
     *   followUpRef: string|null
     * }
     */
    private function finishOptions(array $tokens): array
    {
        $reviewedReportSha256 = OptionTokens::value($tokens, 'reviewed-report-sha256');
        if ($reviewedReportSha256 !== null && preg_match('/^sha256:[a-f0-9]{64}$/', $reviewedReportSha256) !== 1) {
            throw new InvalidArgumentException('--reviewed-report-sha256 must be a sha256:<64 lowercase hex> digest.');
        }

        $learning = OptionTokens::value($tokens, 'learning');
        if ($learning !== null && !in_array($learning, ['no_durable_learning', 'findings_recorded', 'follow_up_required'], true)) {
            throw new InvalidArgumentException('--learning must be no_durable_learning, findings_recorded, or follow_up_required.');
        }

        return [
            'reviewedReportSha256' => $reviewedReportSha256,
            'by' => OptionTokens::value($tokens, 'by'),
            'learning' => $learning,
            'learningReason' => OptionTokens::value($tokens, 'learning-reason'),
            'findingInputs' => $this->findingInputs($tokens),
            'followUpRef' => OptionTokens::value($tokens, 'follow-up-ref'),
        ];
    }

    /**
     * @param list<string> $tokens
     * @return list<FinishFindingInput>
     */
    private function findingInputs(array $tokens): array
    {
        $observations = $this->optionValues($tokens, 'finding-observation');
        $hypotheses = $this->optionValues($tokens, 'finding-hypothesis');
        $conclusions = $this->optionValues($tokens, 'finding-conclusion');
        $confidences = $this->optionValues($tokens, 'finding-confidence');
        $sensitivities = $this->optionValues($tokens, 'finding-sensitivity');
        $counts = array_unique([
            count($observations),
            count($hypotheses),
            count($conclusions),
            count($confidences),
            count($sensitivities),
        ]);
        if ($counts === [0]) {
            return [];
        }
        if (count($counts) !== 1 || $observations === []) {
            throw new InvalidArgumentException(
                'Each finish Finding requires one aligned --finding-observation, --finding-hypothesis, --finding-conclusion, --finding-confidence, and --finding-sensitivity.',
            );
        }

        $inputs = [];
        foreach ($observations as $index => $observation) {
            $inputs[] = new FinishFindingInput(
                $observation,
                $hypotheses[$index],
                $conclusions[$index],
                $confidences[$index],
                $sensitivities[$index],
            );
        }

        return $inputs;
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function optionValues(array $tokens, string $name): array
    {
        $values = [];
        $prefix = '--' . $name . '=';
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--' . $name) {
                $value = $tokens[$index + 1] ?? null;
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
                ++$index;
                continue;
            }
            if (str_starts_with($token, $prefix)) {
                $values[] = substr($token, strlen($prefix));
            }
        }

        return $values;
    }

    /** @param list<string> $args */
    private function runRecallQuietly(array $args): int
    {
        if ($this->recallRunner === null) {
            throw new RuntimeException('agent-loop enter requires a Recall runner for deterministic governed preparation.');
        }

        if (!in_array(self::STDOUT_DISCARD_FILTER, stream_get_filters(), true)) {
            if (!class_exists(StdoutDiscardFilter::class)) {
                throw new RuntimeException('Unable to load the front-door STDOUT discard filter.');
            }
            if (!stream_filter_register(self::STDOUT_DISCARD_FILTER, StdoutDiscardFilter::class)) {
                throw new RuntimeException('Unable to register the front-door STDOUT discard filter.');
            }
        }
        if (!in_array(self::STDERR_CAPTURE_FILTER, stream_get_filters(), true)) {
            if (!class_exists(NestedStderrCaptureFilter::class)) {
                throw new RuntimeException('Unable to load the front-door STDERR capture filter.');
            }
            if (!stream_filter_register(self::STDERR_CAPTURE_FILTER, NestedStderrCaptureFilter::class)) {
                throw new RuntimeException('Unable to register the front-door STDERR capture filter.');
            }
        }

        $filter = stream_filter_append(STDOUT, self::STDOUT_DISCARD_FILTER, STREAM_FILTER_WRITE);
        if ($filter === false) {
            throw new RuntimeException('Unable to silence nested Recall STDOUT for the front-door response.');
        }

        // The owning package reports why compilation refused on STDERR. Capture
        // it so a failure can relay the owner's own words instead of discarding
        // the one fact that says what to repair.
        NestedStderrCaptureFilter::reset();
        $errorFilter = stream_filter_append(STDERR, self::STDERR_CAPTURE_FILTER, STREAM_FILTER_WRITE);
        if ($errorFilter === false) {
            throw new RuntimeException('Unable to capture nested Recall STDERR for the front-door response.');
        }

        $level = ob_get_level();
        ob_start(static fn (string $buffer): string => '');
        try {
            return ($this->recallRunner)($args);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            if (!stream_filter_remove($errorFilter)) {
                throw new RuntimeException('Unable to restore STDERR after nested Recall compilation.');
            }
            if (!stream_filter_remove($filter)) {
                throw new RuntimeException('Unable to restore STDOUT after nested Recall compilation.');
            }
        }
    }

    /** @param list<string> $tokens */
    private function format(array $tokens): string
    {
        $format = OptionTokens::value($tokens, 'format') ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }

        return $format;
    }

    /** @param list<string> $tokens */
    private function positiveOption(array $tokens, string $name, int $default): int
    {
        $value = OptionTokens::value($tokens, $name);
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('--' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /** @param list<string> $args */
    private function helpRequested(array $args): bool
    {
        return count($args) === 1 && in_array($args[0], ['help', '--help', '-h'], true);
    }

    /** @param list<string> $tokens */
    private function validateEnterTokens(array $tokens): void
    {
        $this->validateTokens($tokens, ['format', 'max-lines', 'max-bytes']);
    }

    /** @param list<string> $tokens */
    private function validateFinishTokens(array $tokens): void
    {
        $this->validateTokens($tokens, [
            'format',
            'reviewed-report-sha256',
            'by',
            'learning',
            'learning-reason',
            'finding-observation',
            'finding-hypothesis',
            'finding-conclusion',
            'finding-confidence',
            'finding-sensitivity',
            'follow-up-ref',
        ]);
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $valueOptions
     */
    private function validateTokens(array $tokens, array $valueOptions): void
    {
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                throw new InvalidArgumentException('Unknown argument: ' . $token);
            }

            $name = strtok(substr($token, 2), '=');
            if (!is_string($name) || !in_array($name, $valueOptions, true)) {
                throw new InvalidArgumentException('Unknown option: --' . (is_string($name) ? $name : ''));
            }

            if (str_contains($token, '=')) {
                if (substr($token, strlen('--' . $name . '=')) === '') {
                    throw new InvalidArgumentException('--' . $name . ' requires a non-empty value.');
                }
                continue;
            }

            $value = $tokens[$index + 1] ?? null;
            if (!is_string($value) || str_starts_with($value, '--')) {
                throw new InvalidArgumentException('--' . $name . ' requires a value.');
            }
            ++$index;
        }
    }
}
