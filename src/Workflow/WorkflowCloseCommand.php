<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidence;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;
use voku\AgentSession\WorkBriefStatus;
use voku\AgentSession\WorkBriefStore;

final readonly class WorkflowCloseCommand
{
    /** @param callable(list<string>): int $verifyRunner */
    public function __construct(private string $rootPath, private mixed $verifyRunner)
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

            $failures = $this->runGates($taskId->value);
            $failed = $failures !== [];
            if ($failed && $options['acceptRisk'] === null) {
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
                if ($failed) {
                    echo "[WARN] workflow close: closing session despite failed gates\n";
                }
            } else {
                echo "[OK] workflow close: gates passed; closing session\n";
            }

            try {
                $session = $this->activeSession($taskId->value)
                    ?? throw new RuntimeException("Expected exactly one active session for {$taskId->value}.");
                (new SessionStore())->setStatus($session, SessionStatus::DONE);
            } catch (Throwable $exception) {
                if ($acceptedRisk) {
                    echo "[FAIL] workflow close: session close failed after accepted-risk bypass\n";
                }

                throw $exception;
            }

            return 0;
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, '[FAIL] workflow close: ' . $e->getMessage() . "\n");

            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, '[FAIL] workflow close: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * Runs every gate and returns what failed, rather than a single boolean.
     *
     * An override has to record which gates failed and which evidence was missing, so the reasons
     * have to survive the run instead of being reduced to false. Every gate still runs even after
     * one fails: a human deciding whether to override needs the whole picture, not the first
     * problem alphabetically.
     *
     * @return list<array{gate: string, detail: string}>
     */
    private function runGates(string $taskId): array
    {
        $failures = [];
        foreach ([
            'recall' => $this->checkRecallGate($taskId),
            'review' => $this->checkReviewGate($taskId),
            'work_brief' => $this->checkWorkBriefGate($taskId),
            'validation' => $this->checkValidationGate($taskId),
            'recall_outcomes' => $this->checkRecallOutcomeGate($taskId),
            'learning_decision' => $this->checkLearningDecisionGate($taskId),
            'edit_verification' => $this->checkEditVerificationGate($taskId),
            'verify' => $this->checkVerifyGate($taskId),
        ] as $gate => $detail) {
            if ($detail !== null) {
                $failures[] = ['gate' => $gate, 'detail' => $detail];
            }
        }

        return $failures;
    }

    /**
     * Requires a passing `verification-result.json` for every edit bundle of this task.
     *
     * A task closed without ever running `agent-loop edit` has no bundle to grade, and demanding
     * one would only push people to fake it; that case passes with an explicit note. A bundle that
     * exists but was never verified does not: an ungraded edit is exactly what this gate is for.
     */
    private function checkEditVerificationGate(string $taskId): ?string
    {
        $bundle = rtrim($this->rootPath, '/') . '/.agent-loop/edit/' . $taskId;
        if (!is_dir($bundle)) {
            echo "[OK] edit verification: no edit bundle for {$taskId}\n";

            return null;
        }

        $resultFile = $bundle . '/verification-result.json';
        if (!is_file($resultFile)) {
            echo "[FAIL] edit verification: missing verification-result.json in .agent-loop/edit/{$taskId}\n";
            echo "[ACTION REQUIRED] Run agent-loop edit verify --bundle=.agent-loop/edit/{$taskId}\n";

            return 'missing verification-result.json for edit bundle ' . $taskId;
        }

        try {
            $result = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            echo "[FAIL] edit verification: verification-result.json is not valid JSON\n";

            return 'unreadable verification-result.json for edit bundle ' . $taskId;
        }
        if (!is_array($result) || !is_string($result['status'] ?? null)) {
            echo "[FAIL] edit verification: verification-result.json has no status\n";

            return 'verification-result.json without a status for edit bundle ' . $taskId;
        }
        if ($result['status'] !== 'passed') {
            $gates = is_array($result['gates'] ?? null) ? $result['gates'] : [];
            $notPassed = array_keys(array_filter($gates, static fn (mixed $status): bool => $status !== 'passed'));
            echo '[FAIL] edit verification: status ' . $result['status'] . ($notPassed === [] ? '' : ' (gates: ' . implode(', ', $notPassed) . ')') . "\n";

            return 'edit verification status ' . $result['status'] . ($notPassed === [] ? '' : '; gates not passed: ' . implode(', ', $notPassed));
        }

        echo "[OK] edit verification: passed for .agent-loop/edit/{$taskId}\n";

        return null;
    }

    private function checkRecallGate(string $taskId): ?string
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        $relative = RecallOutputRoot::relativeTo($this->rootPath, $path);
        if (is_file($path)) {
            echo "[OK] recall: found {$relative}\n";

            return null;
        }

        echo "[FAIL] recall: missing {$relative}\n";

        return 'missing ' . $relative;
    }

    private function checkReviewGate(string $taskId): ?string
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $relative = $reader->relativePath($taskId);
        $report = $reader->read($taskId);

        if (!$report['exists']) {
            echo "[FAIL] review: missing {$relative}\n";
            echo "[ACTION REQUIRED] Run agent-loop review blindspots {$taskId} before workflow close.\n";

            return 'missing blind-spot report ' . $relative;
        }

        if ($report['invalid']) {
            echo "[FAIL] review: blindspot report JSON is invalid or missing status\n";

            return 'invalid blind-spot report ' . $relative;
        }

        if ($report['status'] === 'fail') {
            echo "[FAIL] review: blindspot report status is fail\n";

            return 'blind-spot report status is fail';
        }

        echo "[OK] review: found {$relative} with status {$report['status']}\n";

        return null;
    }

    /**
     * Scoped to this task so an unrelated task's stale recall draft or
     * broken task file can't block this close; package delegates, board,
     * and the learning root still verify repo-wide either way.
     */
    private function checkVerifyGate(string $taskId): ?string
    {
        if (($this->verifyRunner)(['--task-id=' . $taskId]) === 0) {
            echo "[OK] verify: agent-loop verify passed\n";

            return null;
        }

        echo "[FAIL] verify: agent-loop verify failed\n";

        return 'agent-loop verify failed';
    }

    private function checkWorkBriefGate(string $taskId): ?string
    {
        $sessionsRoot = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($sessionsRoot)) {
            echo "[FAIL] work brief: no active session found for task {$taskId}\n";

            return 'no active session for task ' . $taskId;
        }

        $sessions = array_values(array_filter(
            (new SessionStore())->all($sessionsRoot),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));
        if (count($sessions) !== 1) {
            echo "[FAIL] work brief: expected one active session for task {$taskId}, found " . count($sessions) . "\n";

            return 'expected one active session, found ' . count($sessions);
        }

        $briefs = new WorkBriefStore();
        $brief = $briefs->find($sessions[0]);
        if ($brief === null) {
            echo "[FAIL] work brief: missing for task {$taskId}\n";

            return 'missing work brief for task ' . $taskId;
        }

        $approval = $briefs->approval($sessions[0]);
        if ($brief->status !== WorkBriefStatus::APPROVED || $approval === null || $approval->workBriefRevision !== $brief->revision) {
            echo "[FAIL] work brief: revision {$brief->revision} is not approved for task {$taskId}\n";

            return 'work brief revision ' . $brief->revision . ' is not approved';
        }

        echo "[OK] work brief: revision {$brief->revision} approved by {$approval->approvedBy}\n";

        return null;
    }

    private function checkValidationGate(string $taskId): ?string
    {
        $session = $this->activeSession($taskId);
        if ($session === null) {
            echo "[FAIL] validation: no single active session found for task {$taskId}\n";

            return 'no single active session for task ' . $taskId;
        }
        $brief = (new WorkBriefStore())->find($session);
        if ($brief === null) {
            echo "[FAIL] validation: work brief is missing for task {$taskId}\n";

            return 'missing work brief for task ' . $taskId;
        }
        $evidence = (new ValidationEvidenceStore())->all($session);
        $missing = [];
        foreach ($brief->validation as $command) {
            $matching = array_values(array_filter(
                $evidence,
                static fn (ValidationEvidence $item): bool => $item->workBriefRevision === $brief->revision && $item->command === $command,
            ));
            $latest = $matching === [] ? null : $matching[count($matching) - 1];
            if ($latest?->status !== ValidationStatus::PASSED) {
                echo '[FAIL] validation: ' . ($latest === null ? 'missing' : $latest->status->value) . " evidence for {$command} (work brief revision {$brief->revision})\n";
                $missing[] = $command . ' (' . ($latest === null ? 'no evidence' : $latest->status->value) . ')';

                continue;
            }
            echo "[OK] validation: {$command} (exit {$latest->exitCode}, revision {$brief->revision})\n";
        }

        return $missing === [] ? null : 'validation evidence missing or not passed for: ' . implode(', ', $missing);
    }

    private function checkRecallOutcomeGate(string $taskId): ?string
    {
        $path = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/meta.json';
        if (!is_file($path)) {
            return 'missing recall metadata for task ' . $taskId;
        }
        try {
            $meta = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            echo "[FAIL] recall outcomes: invalid recall metadata\n";

            return 'invalid recall metadata for task ' . $taskId;
        }
        if (!is_array($meta)) {
            echo "[FAIL] recall outcomes: invalid recall metadata\n";

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
            echo "[FAIL] recall outcomes: selected guidance has no compilation id\n";

            return 'selected guidance without a compilation id';
        }
        $root = $this->learningRoot();
        $outcomesPath = $root === null ? null : $root . '/history/outcomes.jsonl';
        if ($outcomesPath === null || !is_file($outcomesPath)) {
            echo "[FAIL] recall outcomes: missing outcomes.jsonl for selected guidance\n";

            return 'missing history/outcomes.jsonl for selected guidance';
        }
        $recorded = [];
        foreach (file($outcomesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
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
        $missing = array_values(array_filter(
            array_unique($selected),
            static fn (string $id): bool => !isset($recorded[$id]),
        ));
        if ($missing !== []) {
            echo '[FAIL] recall outcomes: missing explicit outcome for ' . implode(', ', $missing) . "\n";

            return 'missing explicit recall outcome for: ' . implode(', ', $missing);
        }
        echo '[OK] recall outcomes: explicit outcomes recorded for ' . count($selected) . " selected guidance item(s)\n";

        return null;
    }

    private function checkLearningDecisionGate(string $taskId): ?string
    {
        $session = $this->activeSession($taskId);
        if ($session === null) {
            return 'no single active session for task ' . $taskId;
        }
        $decision = (new LearningDecisionStore())->find($session);
        if ($decision === null) {
            echo "[FAIL] learning decision: missing (record findings_recorded, no_durable_learning, or follow_up_required)\n";

            return 'missing learning decision';
        }
        echo "[OK] learning decision: {$decision->decision->value} by {$decision->decidedBy}\n";

        return null;
    }

    private function activeSession(string $taskId): ?Session
    {
        $root = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($root)) {
            return null;
        }
        $sessions = array_values(array_filter(
            (new SessionStore())->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));

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
            $value = $tokens[++$i];
            if ($token === '--status') {
                $status = $value;
            } elseif ($token === '--accept-risk') {
                $risk = $value;
            } else {
                $riskBy = $value;
            }
        }
        if ($status === null || trim($status) === '') {
            throw new InvalidArgumentException('--status done is required.');
        }
        if ($risk !== null && trim($risk) === '') {
            throw new InvalidArgumentException('--accept-risk requires a non-empty reason.');
        }
        if ($riskBy !== null && trim($riskBy) === '') {
            throw new InvalidArgumentException('--accept-risk-by requires a non-empty name.');
        }

        return ['status' => $status, 'acceptRisk' => $risk, 'acceptRiskBy' => $riskBy];
    }
}
