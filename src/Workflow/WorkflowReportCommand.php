<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLearning\LearningRepositoryValidator;
use voku\AgentLearning\OutcomeRepository;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\Transparency\ApprovedScope;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidence;
use voku\AgentSession\ValidationEvidenceStore;

/**
 * Read-only projection of the artifacts that make a governed task auditable.
 *
 * Durable Contract, Run, Verification and Learning state remain readable when
 * Session working memory has been pruned. Changed files are supplied by the
 * caller so this command never silently promotes a worktree to authority.
 */
final readonly class WorkflowReportCommand
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
            $report = $this->buildReport($taskId->value, $options['changedFiles']);
            if ($options['format'] === 'json') {
                echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            $this->printText($report);

            return 0;
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, '[FAIL] workflow report: ' . $exception->getMessage() . "\n");

            return 1;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow report: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{format: 'text'|'json', changedFiles: list<string>}
     */
    private function parse(array $tokens): array
    {
        $format = 'text';
        $changedFiles = [];

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!in_array($token, ['--format', '--changed-file'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }

            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }

            match ($token) {
                '--format' => $format = $value,
                '--changed-file' => $changedFiles[] = $value,
            };
        }

        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }

        /** @var 'text'|'json' $format */
        return [
            'format' => $format,
            'changedFiles' => array_values(array_unique($changedFiles)),
        ];
    }

    /**
     * @param list<string> $changedFiles
     * @return array<string, mixed>
     */
    public function buildReport(string $taskId, array $changedFiles = []): array
    {
        $sessions = $this->sessionsFor($taskId);
        $activeSessions = array_values(array_filter($sessions, static fn (Session $session): bool => !$session->status->isClosed()));
        $session = $this->reportSession($sessions, $activeSessions);
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);

        return [
            'schema_version' => '2.0',
            'task_id' => $taskId,
            'run_id' => (new GovernedRunStore($this->rootPath))->find($taskId)?->runId,
            'session' => [
                'count' => count($sessions),
                'active_count' => count($activeSessions),
                'status' => $session['status'],
                'id' => $session['session']?->id,
                'path' => $session['session'] === null ? null : $this->relativePath($session['session']->path),
            ],
            'contract' => $this->contractReport($contract),
            'scope' => $this->scopeReport($contract, $changedFiles),
            'validation' => $this->validationReport($taskId, $contract, $session['session']),
            'verification' => $this->verificationReport($taskId),
            'recall' => $this->recallReport($taskId),
            'review' => $this->reviewReport($taskId),
            'learning' => $this->learningReport($taskId),
            'accepted_risk' => $this->acceptedRiskReport($taskId),
        ];
    }

    /** @return list<Session> */
    private function sessionsFor(string $taskId): array
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return [];
        }

        return array_values(array_filter(
            (new SessionStore())->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId,
        ));
    }

    /**
     * @param list<Session> $sessions
     * @param list<Session> $activeSessions
     * @return array{status: string, session: Session|null}
     */
    private function reportSession(array $sessions, array $activeSessions): array
    {
        if (count($activeSessions) === 1) {
            return ['status' => 'active', 'session' => $activeSessions[0]];
        }
        if (count($activeSessions) > 1) {
            return ['status' => 'ambiguous', 'session' => null];
        }
        if ($sessions === []) {
            return ['status' => 'missing', 'session' => null];
        }

        usort($sessions, static fn (Session $left, Session $right): int => [$right->updatedAt, $right->id] <=> [$left->updatedAt, $left->id]);

        return ['status' => 'closed', 'session' => $sessions[0]];
    }

    /**
     * @return array{status: string, revision: int|null, goal: string|null, acceptance_criteria: list<string>, behavior_anchors: list<string>, scope: list<string>, non_goals: list<string>, path: string|null, approval: array{revision: int|null, by: string|null, at: string|null}}
     */
    private function contractReport(?TaskContract $contract): array
    {
        if ($contract === null) {
            return [
                'status' => 'missing',
                'revision' => null,
                'goal' => null,
                'acceptance_criteria' => [],
                'behavior_anchors' => [],
                'scope' => [],
                'non_goals' => [],
                'path' => null,
                'approval' => ['revision' => null, 'by' => null, 'at' => null],
            ];
        }

        return [
            'status' => $contract->status,
            'revision' => $contract->revision,
            'goal' => $contract->goal,
            'acceptance_criteria' => $contract->acceptanceCriteria,
            'behavior_anchors' => $contract->behaviorAnchors,
            'scope' => $contract->scope,
            'non_goals' => $contract->nonGoals,
            'path' => $this->relativePath($contract->path),
            'approval' => [
                'revision' => $contract->status === TaskContract::APPROVED ? $contract->revision : null,
                'by' => $contract->approvedBy,
                'at' => $contract->approvedAt,
            ],
        ];
    }

    /**
     * @param list<string> $changedFiles
     * @return array{changed_files_supplied: bool, changed_files: list<string>, outside_approved_scope: list<string>}
     */
    private function scopeReport(?TaskContract $contract, array $changedFiles): array
    {
        $partition = ApprovedScope::fromContract($contract)->partition($changedFiles);

        return [
            'changed_files_supplied' => $changedFiles !== [],
            'changed_files' => $changedFiles,
            'outside_approved_scope' => $partition['outside'],
        ];
    }

    /**
     * @return list<array{command: string, contract_revision: int, status: 'passed'|'failed'|'stale'|'missing', exit_code: int|null, duration_ms: int|null, executed_at: string|null, source: 'session'|'verification_receipt'}>
     */
    private function validationReport(string $taskId, ?TaskContract $contract, ?Session $session): array
    {
        if ($contract === null) {
            return [];
        }

        $evidence = $session === null ? [] : (new ValidationEvidenceStore())->all($session);
        $receipt = (new RunVerificationReceiptStore($this->rootPath))->find($taskId);
        $result = [];
        foreach ($contract->validation as $command) {
            $exact = array_values(array_filter(
                $evidence,
                static fn (ValidationEvidence $item): bool => $item->contractRevision === $contract->revision && $item->command === $command,
            ));
            $historical = array_values(array_filter(
                $evidence,
                static fn (ValidationEvidence $item): bool => $item->command === $command,
            ));
            $latest = $exact === [] ? null : $exact[count($exact) - 1];
            if ($latest !== null) {
                $result[] = [
                    'command' => $command,
                    'contract_revision' => $contract->revision,
                    'status' => $latest->status->value,
                    'exit_code' => $latest->exitCode,
                    'duration_ms' => $latest->durationMs,
                    'executed_at' => $latest->executedAt,
                    'source' => 'session',
                ];
                continue;
            }

            $receiptObligation = null;
            if ($receipt !== null && $receipt->contractRevision === $contract->revision) {
                foreach ($receipt->obligations as $obligation) {
                    if ($obligation['command'] === $command) {
                        $receiptObligation = $obligation;
                        break;
                    }
                }
            }
            if ($receiptObligation !== null) {
                $status = $receiptObligation['status'] === 'passed' ? 'passed' : 'failed';
                $result[] = [
                    'command' => $command,
                    'contract_revision' => $contract->revision,
                    'status' => $status,
                    'exit_code' => $receiptObligation['exit_code'],
                    'duration_ms' => $receiptObligation['duration_ms'],
                    'executed_at' => $receiptObligation['executed_at'],
                    'source' => 'verification_receipt',
                ];
                continue;
            }

            $result[] = [
                'command' => $command,
                'contract_revision' => $contract->revision,
                'status' => $historical === [] ? 'missing' : 'stale',
                'exit_code' => null,
                'duration_ms' => null,
                'executed_at' => null,
                'source' => 'session',
            ];
        }

        return $result;
    }

    /** @return array{status: string, path: string, run_id: string|null, contract_revision: int|null} */
    private function verificationReport(string $taskId): array
    {
        $store = new RunVerificationReceiptStore($this->rootPath);
        $receipt = $store->find($taskId);
        if ($receipt === null) {
            return [
                'status' => 'missing',
                'path' => $this->relativePath($store->path($taskId)),
                'run_id' => null,
                'contract_revision' => null,
            ];
        }

        return [
            'status' => $receipt->verdict,
            'path' => $this->relativePath($receipt->path),
            'run_id' => $receipt->runId,
            'contract_revision' => $receipt->contractRevision,
        ];
    }

    /** @return array{status: string, meta_path: string, task_files: list<string>, outcome_draft: bool, logged_outcomes: int} */
    private function recallReport(string $taskId): array
    {
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        $reader = new CompiledRecallOutputReader();
        $relative = $this->relativePath($reader->identityPath($directory));
        $taskFiles = [];
        $status = 'missing';
        $outcomeDraft = false;
        try {
            $output = $reader->read($directory);
            if ($output !== null) {
                $outcomeDraft = $output->hasOutcomeDraft();
                if (!$output->describesTask($taskId)) {
                    $status = 'invalid';
                } else {
                    $status = 'present';
                    // The owner drops empty entries; whitespace-only ones would
                    // still be reported as task files.
                    $taskFiles = array_values(array_filter(
                        $output->taskFiles(),
                        static fn (string $file): bool => trim($file) !== '',
                    ));
                }
            }
        } catch (RuntimeException) {
            $status = 'invalid';
        }

        return [
            'status' => $status,
            'meta_path' => $relative,
            'task_files' => $taskFiles,
            'outcome_draft' => $outcomeDraft,
            'logged_outcomes' => $this->loggedOutcomeCount($taskId, $this->resolveLearningRoot($taskId)),
        ];
    }

    /** @return array{exists: bool, status: string|null, invalid: bool, path: string} */
    private function reviewReport(string $taskId): array
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);

        return [...$reader->read($taskId), 'path' => $reader->relativePath($taskId)];
    }

    /** @return array{status: string, root: string|null, findings: int, proposals: int, outcomes: int, decision: string|null, run_id: string|null} */
    private function learningReport(string $taskId): array
    {
        $root = $this->resolveLearningRoot($taskId);
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        $decision = $root === null || $run === null ? null : (new RunLearningDecisionStore($root))->find($run->runId);
        if ($root === null) {
            return [
                'status' => 'unavailable',
                'root' => null,
                'findings' => 0,
                'proposals' => 0,
                'outcomes' => 0,
                'decision' => null,
                'run_id' => $run?->runId,
            ];
        }

        try {
            $validated = (new LearningRepositoryValidator())->validate($root);
            $findings = array_filter($validated->findingsById, static fn (object $finding): bool => $finding->taskId === $taskId);
            $findingIds = array_keys($findings);
            $proposals = array_filter(
                $validated->proposalsById,
                static fn (object $proposal): bool => array_intersect($proposal->sourceFindings, $findingIds) !== [],
            );
            $outcomes = array_filter(
                $validated->outcomes,
                static fn (array $outcome): bool => ($outcome['task_id'] ?? null) === $taskId,
            );

            return [
                'status' => 'valid',
                'root' => $this->relativePath($root),
                'findings' => count($findings),
                'proposals' => count($proposals),
                'outcomes' => count($outcomes),
                'decision' => $decision?->decision->value,
                'run_id' => $run?->runId,
            ];
        } catch (RuntimeException) {
            return [
                'status' => 'invalid',
                'root' => $this->relativePath($root),
                'findings' => 0,
                'proposals' => 0,
                'outcomes' => 0,
                'decision' => $decision?->decision->value,
                'run_id' => $run?->runId,
            ];
        }
    }

    /** @return array{recorded: bool, path: string} */
    private function acceptedRiskReport(string $taskId): array
    {
        $writer = new AcceptedRiskWriter($this->rootPath);
        $relative = $writer->relativePath($taskId);

        return ['recorded' => is_file(rtrim($this->rootPath, '/') . '/' . $relative), 'path' => $relative];
    }

    /**
     * The report never invents a Learning location, and never attributes another
     * repository's decision to this Run: a governed Run reads from the root it is
     * bound to. A root that does not exist stays null so the report
     * says "unavailable" instead of "invalid".
     */
    private function resolveLearningRoot(string $taskId): ?string
    {
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        $candidate = $run === null
            ? (new ProjectLayout($this->rootPath))->learningRoot()
            : WorkflowLearningRoot::forRun($this->rootPath, $run);

        return is_dir($candidate) ? rtrim($candidate, '/') : null;
    }

    private function loggedOutcomeCount(string $taskId, ?string $learningRoot): int
    {
        if ($learningRoot === null) {
            return 0;
        }

        $count = 0;
        foreach ((new OutcomeRepository())->loadAll($learningRoot) as $record) {
            if (($record['task_id'] ?? null) === $taskId) {
                ++$count;
            }
        }

        return $count;
    }

    private function relativePath(string $path): string
    {
        return RecallOutputRoot::relativeTo($this->rootPath, $path);
    }

    /** @param array<string, mixed> $report */
    private function printText(array $report): void
    {
        echo 'Workflow report: ' . $report['task_id'] . "\n\n";
        echo 'Run: ' . ($report['run_id'] ?? 'missing') . "\n";

        $session = $report['session'];
        echo sprintf("Session: %s (%d total, %d active)\n", $session['status'], $session['count'], $session['active_count']);

        $contract = $report['contract'];
        if ($contract['status'] === 'missing') {
            echo "Contract: missing\n";
        } else {
            $approval = $contract['approval']['by'] === null ? 'not approved' : 'approved by ' . $contract['approval']['by'];
            echo sprintf("Contract: %s revision %d (%s)\n", $contract['status'], $contract['revision'], $approval);
            if ($contract['acceptance_criteria'] !== []) {
                echo 'Acceptance criteria (required, not proof): ' . implode('; ', $contract['acceptance_criteria']) . "\n";
            }
            if ($contract['behavior_anchors'] !== []) {
                echo 'Behavior anchors: ' . implode('; ', $contract['behavior_anchors']) . "\n";
            }
            echo 'Contract scope: ' . implode(', ', $contract['scope']) . "\n";
        }

        $scope = $report['scope'];
        if (!$scope['changed_files_supplied']) {
            echo "Changed files: not supplied (pass --changed-file; workflow report does not run git)\n";
        } elseif ($scope['outside_approved_scope'] === []) {
            echo "Changed files: all supplied paths are within Contract scope\n";
        } else {
            echo 'Changed files outside Contract scope: ' . implode(', ', $scope['outside_approved_scope']) . "\n";
        }

        echo "Validation evidence:\n";
        if ($report['validation'] === []) {
            echo "  - no Contract validation commands\n";
        }
        foreach ($report['validation'] as $validation) {
            echo '  - [' . $validation['status'] . '] ' . $validation['command'] . ' via ' . $validation['source'];
            if ($validation['executed_at'] !== null) {
                echo ' (exit ' . $validation['exit_code'] . ', executed ' . $validation['executed_at'] . ')';
            }
            echo "\n";
        }

        $verification = $report['verification'];
        echo 'Verification receipt: ' . $verification['status'] . "\n";
        $recall = $report['recall'];
        echo sprintf("Recall: %s, outcome draft %s, %d logged outcome(s)\n", $recall['status'], $recall['outcome_draft'] ? 'present' : 'missing', $recall['logged_outcomes']);
        $review = $report['review'];
        echo 'Review: ' . ($review['exists'] ? ($review['invalid'] ? 'invalid' : $review['status']) : 'missing') . "\n";
        $learning = $report['learning'];
        echo sprintf("Learning: %s, %d finding(s), %d proposal(s), %d outcome(s), Run decision %s\n", $learning['status'], $learning['findings'], $learning['proposals'], $learning['outcomes'], $learning['decision'] ?? 'missing');
        $risk = $report['accepted_risk'];
        echo 'Accepted risk: ' . ($risk['recorded'] ? 'recorded at ' . $risk['path'] : 'none') . "\n";
    }
}
