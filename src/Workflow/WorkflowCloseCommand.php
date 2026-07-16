<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentSession\SessionStore;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\ValidationEvidence;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;
use voku\AgentSession\WorkBriefStatus;
use voku\AgentSession\WorkBriefStore;

final readonly class WorkflowCloseCommand
{
    /** @param callable(list<string>): int $sessionRunner @param callable(list<string>): int $verifyRunner */
    public function __construct(private string $rootPath, private mixed $sessionRunner, private mixed $verifyRunner)
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

            $failed = !$this->runGates($taskId->value);
            if ($failed && $options['acceptRisk'] === null) {
                echo "[FAIL] workflow close: gates failed; session was not closed.\n";
                return 1;
            }

            $acceptedRisk = $options['acceptRisk'] !== null;
            if ($acceptedRisk) {
                $path = (new AcceptedRiskWriter($this->rootPath))->write($taskId->value, $options['acceptRisk']);
                echo "[WARN] workflow close: accepted risk recorded at {$path}\n";
                if ($failed) {
                    echo "[WARN] workflow close: delegating to session close despite failed gates\n";
                }
            } else {
                echo "[OK] workflow close: gates passed; delegating to session close\n";
            }

            $exit = ($this->sessionRunner)(['close', $taskId->value, '--status', 'done']);
            if ($exit !== 0 && $acceptedRisk) {
                echo "[FAIL] workflow close: session close failed after accepted-risk bypass\n";
            }

            return $exit;
        } catch (InvalidArgumentException $e) {
            fwrite(\STDERR, '[FAIL] workflow close: ' . $e->getMessage() . "\n");
            return 1;
        } catch (Throwable $e) {
            fwrite(\STDERR, '[FAIL] workflow close: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function runGates(string $taskId): bool
    {
        $recallPassed = $this->checkRecallGate($taskId);
        $reviewPassed = $this->checkReviewGate($taskId);
        $workBriefPassed = $this->checkWorkBriefGate($taskId);
        $validationPassed = $this->checkValidationGate($taskId);
        $outcomesPassed = $this->checkRecallOutcomeGate($taskId);
        $learningPassed = $this->checkLearningDecisionGate($taskId);
        $verifyPassed = $this->checkVerifyGate($taskId);

        return $recallPassed && $reviewPassed && $workBriefPassed && $validationPassed && $outcomesPassed && $learningPassed && $verifyPassed;
    }

    private function checkRecallGate(string $taskId): bool
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        $relative = RecallOutputRoot::relativeTo($this->rootPath, $path);
        if (is_file($path)) {
            echo "[OK] recall: found {$relative}\n";
            return true;
        }

        echo "[FAIL] recall: missing {$relative}\n";
        return false;
    }

    private function checkReviewGate(string $taskId): bool
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $relative = $reader->relativePath($taskId);
        $report = $reader->read($taskId);

        if (!$report['exists']) {
            echo "[FAIL] review: missing {$relative}\n";
            echo "[ACTION REQUIRED] Run agent-loop review blindspots {$taskId} before workflow close.\n";
            return false;
        }

        if ($report['invalid']) {
            echo "[FAIL] review: blindspot report JSON is invalid or missing status\n";
            return false;
        }

        if ($report['status'] === 'fail') {
            echo "[FAIL] review: blindspot report status is fail\n";
            return false;
        }

        echo "[OK] review: found {$relative} with status {$report['status']}\n";
        return true;
    }

    /**
     * Scoped to this task so an unrelated task's stale recall draft or
     * broken task file can't block this close; package delegates, board,
     * and the learning root still verify repo-wide either way.
     */
    private function checkVerifyGate(string $taskId): bool
    {
        if (($this->verifyRunner)(['--task-id=' . $taskId]) === 0) {
            echo "[OK] verify: agent-loop verify passed\n";
            return true;
        }

        echo "[FAIL] verify: agent-loop verify failed\n";
        return false;
    }

    private function checkWorkBriefGate(string $taskId): bool
    {
        $sessionsRoot = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($sessionsRoot)) {
            echo "[FAIL] work brief: no active session found for task {$taskId}\n";

            return false;
        }

        $sessions = array_values(array_filter(
            (new SessionStore())->all($sessionsRoot),
            static fn ($session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));
        if (count($sessions) !== 1) {
            echo "[FAIL] work brief: expected one active session for task {$taskId}, found " . count($sessions) . "\n";

            return false;
        }

        $briefs = new WorkBriefStore();
        $brief = $briefs->find($sessions[0]);
        if ($brief === null) {
            echo "[FAIL] work brief: missing for task {$taskId}\n";

            return false;
        }

        $approval = $briefs->approval($sessions[0]);
        if ($brief->status !== WorkBriefStatus::APPROVED || $approval === null || $approval->workBriefRevision !== $brief->revision) {
            echo "[FAIL] work brief: revision {$brief->revision} is not approved for task {$taskId}\n";

            return false;
        }

        echo "[OK] work brief: revision {$brief->revision} approved by {$approval->approvedBy}\n";

        return true;
    }

    private function checkValidationGate(string $taskId): bool
    {
        $session = $this->activeSession($taskId);
        if ($session === null) {
            echo "[FAIL] validation: no single active session found for task {$taskId}\n";

            return false;
        }
        $brief = (new WorkBriefStore())->find($session);
        if ($brief === null) {
            echo "[FAIL] validation: work brief is missing for task {$taskId}\n";

            return false;
        }
        $evidence = (new ValidationEvidenceStore())->all($session);
        $passed = true;
        foreach ($brief->validation as $command) {
            $matching = array_values(array_filter($evidence, static fn (ValidationEvidence $item): bool => $item->workBriefRevision === $brief->revision && $item->command === $command));
            $latest = $matching === [] ? null : $matching[count($matching) - 1];
            if ($latest?->status !== ValidationStatus::PASSED) {
                echo '[FAIL] validation: ' . ($latest === null ? 'missing' : $latest->status->value) . " evidence for {$command} (work brief revision {$brief->revision})\n";
                $passed = false;
                continue;
            }
            echo "[OK] validation: {$command} (exit {$latest->exitCode}, revision {$brief->revision})\n";
        }

        return $passed;
    }

    private function checkRecallOutcomeGate(string $taskId): bool
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        if (!is_file($path)) {
            return false;
        }
        try {
            $meta = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            echo "[FAIL] recall outcomes: invalid recall metadata\n";

            return false;
        }
        if (!is_array($meta)) {
            echo "[FAIL] recall outcomes: invalid recall metadata\n";

            return false;
        }
        $selected = array_values(array_filter($meta['selected_guidance'] ?? [], static fn (mixed $id): bool => is_string($id) && trim($id) !== ''));
        foreach ($meta['selected_constraints'] ?? [] as $constraint) {
            if (is_array($constraint) && is_string($constraint['id'] ?? null) && trim($constraint['id']) !== '') {
                $selected[] = $constraint['id'];
            }
        }
        if ($selected === []) {
            echo "[OK] recall outcomes: no selected guidance requires evaluation\n";

            return true;
        }
        $compilationId = $meta['compilation_id'] ?? null;
        if (!is_string($compilationId) || trim($compilationId) === '') {
            echo "[FAIL] recall outcomes: selected guidance has no compilation id\n";

            return false;
        }
        $root = $this->learningRoot();
        $outcomesPath = $root === null ? null : $root . '/history/outcomes.jsonl';
        if ($outcomesPath === null || !is_file($outcomesPath)) {
            echo "[FAIL] recall outcomes: missing outcomes.jsonl for selected guidance\n";

            return false;
        }
        $recorded = [];
        foreach (file($outcomesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $outcome = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($outcome) && ($outcome['task_id'] ?? null) === $taskId && ($outcome['compilation_id'] ?? null) === $compilationId && is_string($outcome['guidance_id'] ?? null)) {
                $recorded[$outcome['guidance_id']] = true;
            }
        }
        $missing = array_values(array_filter(array_unique($selected), static fn (string $id): bool => !isset($recorded[$id])));
        if ($missing !== []) {
            echo '[FAIL] recall outcomes: missing explicit outcome for ' . implode(', ', $missing) . "\n";

            return false;
        }
        echo '[OK] recall outcomes: explicit outcomes recorded for ' . count($selected) . " selected guidance item(s)\n";

        return true;
    }

    private function checkLearningDecisionGate(string $taskId): bool
    {
        $session = $this->activeSession($taskId);
        if ($session === null) {
            return false;
        }
        $decision = (new LearningDecisionStore())->find($session);
        if ($decision === null) {
            echo "[FAIL] learning decision: missing (record findings_recorded, no_durable_learning, or follow_up_required)\n";

            return false;
        }
        echo "[OK] learning decision: {$decision->decision->value} by {$decision->decidedBy}\n";

        return true;
    }

    private function activeSession(string $taskId): ?\voku\AgentSession\Session
    {
        $root = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($root)) {
            return null;
        }
        $sessions = array_values(array_filter((new SessionStore())->all($root), static fn ($session): bool => $session->taskId === $taskId && !$session->status->isClosed()));

        return count($sessions) === 1 ? $sessions[0] : null;
    }

    private function learningRoot(): ?string
    {
        foreach (['infra/doc/agent-learning', 'learning-root'] as $relative) {
            $candidate = rtrim($this->rootPath, '/') . '/' . $relative;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{status: string, acceptRisk: string|null}
     */
    private function parse(array $tokens): array
    {
        $status = null;
        $risk = null;
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--status', '--accept-risk'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = $tokens[++$i];
            if ($token === '--status') {
                $status = $value;
            } else {
                $risk = $value;
            }
        }
        if ($status === null || trim($status) === '') {
            throw new InvalidArgumentException('--status done is required.');
        }
        if ($risk !== null && trim($risk) === '') {
            throw new InvalidArgumentException('--accept-risk requires a non-empty reason.');
        }

        return ['status' => $status, 'acceptRisk' => $risk];
    }
}
