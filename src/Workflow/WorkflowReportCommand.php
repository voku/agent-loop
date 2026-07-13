<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentLearning\LearningRepositoryValidator;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBrief;
use voku\AgentSession\WorkBriefStore;

/**
 * Read-only projection of the artifacts that make a governed task auditable.
 *
 * Changed files are deliberately supplied by the caller. The command does not
 * invoke git: a report remains deterministic for a supplied artifact root and
 * does not silently make a working tree the source of truth.
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
            $report = $this->report($taskId->value, $options['learningRoot'], $options['changedFiles']);
            if ($options['format'] === 'json') {
                echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            $this->printText($report);

            return 0;
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, '[FAIL] workflow report: ' . $e->getMessage() . "\n");

            return 1;
        } catch (Throwable $e) {
            fwrite(STDERR, '[FAIL] workflow report: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{format: 'text'|'json', learningRoot: string|null, changedFiles: list<string>}
     */
    private function parse(array $tokens): array
    {
        $format = 'text';
        $learningRoot = null;
        $changedFiles = [];

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--format', '--learning-root', '--changed-file'], true)) {
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
                '--format' => $format = $value,
                '--learning-root' => $learningRoot = $value,
                '--changed-file' => $changedFiles[] = $value,
            };
        }

        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }

        /** @var 'text'|'json' $format */
        return [
            'format' => $format,
            'learningRoot' => $learningRoot,
            'changedFiles' => array_values(array_unique($changedFiles)),
        ];
    }

    /**
     * @param list<string> $changedFiles
     *
     * @return array<string, mixed>
     */
    private function report(string $taskId, ?string $learningRoot, array $changedFiles): array
    {
        $sessions = $this->sessionsFor($taskId);
        $activeSessions = array_values(array_filter($sessions, static fn (Session $session): bool => !$session->status->isClosed()));
        $session = $this->reportSession($sessions, $activeSessions);
        $brief = $session['session'] instanceof Session ? (new WorkBriefStore())->find($session['session']) : null;
        $approval = $session['session'] instanceof Session ? (new WorkBriefStore())->approval($session['session']) : null;

        return [
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'session' => [
                'count' => count($sessions),
                'active_count' => count($activeSessions),
                'status' => $session['status'],
                'id' => $session['session']?->id,
                'path' => $session['session'] === null ? null : $this->relativePath($session['session']->path),
            ],
            'work_brief' => $this->workBriefReport($brief, $approval?->workBriefRevision, $approval?->approvedBy, $approval?->approvedAt),
            'scope' => $this->scopeReport($brief, $changedFiles),
            'validation' => $this->validationReport($brief, $session['session']),
            'recall' => $this->recallReport($taskId, $learningRoot),
            'review' => $this->reviewReport($taskId),
            'learning' => $this->learningReport($taskId, $learningRoot),
            'accepted_risk' => $this->acceptedRiskReport($taskId),
        ];
    }

    /** @return list<Session> */
    private function sessionsFor(string $taskId): array
    {
        $root = rtrim($this->rootPath, '/') . '/session_plan';
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
     *
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
     * @return array{status: string, revision: int|null, goal: string|null, scope: list<string>, non_goals: list<string>, approval: array{revision: int|null, by: string|null, at: string|null}}
     */
    private function workBriefReport(?WorkBrief $brief, ?int $approvedRevision, ?string $approvedBy, ?string $approvedAt): array
    {
        if ($brief === null) {
            return [
                'status' => 'missing',
                'revision' => null,
                'goal' => null,
                'scope' => [],
                'non_goals' => [],
                'approval' => ['revision' => null, 'by' => null, 'at' => null],
            ];
        }

        return [
            'status' => $brief->status->value,
            'revision' => $brief->revision,
            'goal' => $brief->goal,
            'scope' => $brief->scope,
            'non_goals' => $brief->nonGoals,
            'approval' => ['revision' => $approvedRevision, 'by' => $approvedBy, 'at' => $approvedAt],
        ];
    }

    /**
     * @param list<string> $changedFiles
     *
     * @return array{changed_files_supplied: bool, changed_files: list<string>, outside_approved_scope: list<string>}
     */
    private function scopeReport(?WorkBrief $brief, array $changedFiles): array
    {
        $scope = $brief === null ? [] : $brief->scope;
        $outside = array_values(array_filter(
            $changedFiles,
            static fn (string $file): bool => !self::inScope($file, $scope),
        ));

        return [
            'changed_files_supplied' => $changedFiles !== [],
            'changed_files' => $changedFiles,
            'outside_approved_scope' => $outside,
        ];
    }

    /** @param list<string> $scope */
    private static function inScope(string $file, array $scope): bool
    {
        $file = trim($file, '/');
        foreach ($scope as $entry) {
            $entry = trim($entry, '/');
            if ($entry === '.' || $file === $entry || str_starts_with($file, $entry . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{command: string, status: 'passed_evidence'|'mentioned_without_result'|'missing'}>
     */
    private function validationReport(?WorkBrief $brief, ?Session $session): array
    {
        if ($brief === null || $session === null) {
            return [];
        }

        $path = $session->path . '/validation.md';
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $result = [];
        foreach ($brief->validation as $command) {
            $result[] = [
                'command' => $command,
                'status' => $this->validationStatus($content, $command),
            ];
        }

        return $result;
    }

    /** @return 'passed_evidence'|'mentioned_without_result'|'missing' */
    private function validationStatus(string $content, string $command): string
    {
        if (!str_contains($content, $command)) {
            return 'missing';
        }

        $quotedCommand = preg_quote($command, '/');
        if (preg_match('/' . $quotedCommand . '.{0,160}(?:\\[OK\\]|\\bpass(?:ed)?\\b|\\bsuccess(?:ful|fully)?\\b|\\bexit(?: code)?\\s*0\\b)/i', $content) === 1) {
            return 'passed_evidence';
        }

        return 'mentioned_without_result';
    }

    /** @return array{status: string, meta_path: string, task_files: list<string>, outcome_draft: bool, logged_outcomes: int} */
    private function recallReport(string $taskId, ?string $learningRoot): array
    {
        $relative = 'recall/' . $taskId . '/meta.json';
        $path = rtrim($this->rootPath, '/') . '/' . $relative;
        $taskFiles = [];
        $status = 'missing';
        if (is_file($path)) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || (isset($decoded['task_id']) && $decoded['task_id'] !== $taskId)) {
                    $status = 'invalid';
                } else {
                    $status = 'present';
                    $taskFiles = $this->stringList($decoded['task_files'] ?? []);
                }
            } catch (JsonException) {
                $status = 'invalid';
            }
        }

        return [
            'status' => $status,
            'meta_path' => $relative,
            'task_files' => $taskFiles,
            'outcome_draft' => is_file(dirname($path) . '/recall-log.draft.json'),
            'logged_outcomes' => $this->loggedOutcomeCount($taskId, $this->resolveLearningRoot($learningRoot)),
        ];
    }

    /** @return array{exists: bool, status: string|null, invalid: bool, path: string} */
    private function reviewReport(string $taskId): array
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);

        return [...$reader->read($taskId), 'path' => $reader->relativePath($taskId)];
    }

    /** @return array{status: string, root: string|null, findings: int, proposals: int, outcomes: int} */
    private function learningReport(string $taskId, ?string $learningRoot): array
    {
        $root = $this->resolveLearningRoot($learningRoot);
        if ($root === null) {
            return ['status' => 'unavailable', 'root' => null, 'findings' => 0, 'proposals' => 0, 'outcomes' => 0];
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
            ];
        } catch (RuntimeException) {
            return ['status' => 'invalid', 'root' => $this->relativePath($root), 'findings' => 0, 'proposals' => 0, 'outcomes' => 0];
        }
    }

    /** @return array{recorded: bool, path: string} */
    private function acceptedRiskReport(string $taskId): array
    {
        $relative = '.agent-loop/risks/' . $taskId . '.accepted-risk.md';

        return ['recorded' => is_file(rtrim($this->rootPath, '/') . '/' . $relative), 'path' => $relative];
    }

    private function resolveLearningRoot(?string $explicit): ?string
    {
        if ($explicit !== null) {
            return is_dir($explicit) ? rtrim($explicit, '/') : null;
        }

        foreach (['infra/doc/agent-learning', 'learning-root'] as $relative) {
            $candidate = rtrim($this->rootPath, '/') . '/' . $relative;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function loggedOutcomeCount(string $taskId, ?string $learningRoot): int
    {
        if ($learningRoot === null) {
            return 0;
        }

        $path = $learningRoot . '/history/outcomes.jsonl';
        if (!is_file($path)) {
            return 0;
        }

        $count = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($record) && ($record['task_id'] ?? null) === $taskId) {
                    ++$count;
                }
            } catch (JsonException) {
                continue;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /** @param array<string, mixed> $report */
    private function printText(array $report): void
    {
        echo 'Workflow report: ' . $report['task_id'] . "\n\n";

        $session = $report['session'];
        echo sprintf("Session: %s (%d total, %d active)\n", $session['status'], $session['count'], $session['active_count']);

        $brief = $report['work_brief'];
        if ($brief['status'] === 'missing') {
            echo "Work brief: missing\n";
        } else {
            $approval = $brief['approval']['by'] === null ? 'not approved' : 'approved by ' . $brief['approval']['by'];
            echo sprintf("Work brief: %s revision %d (%s)\n", $brief['status'], $brief['revision'], $approval);
            echo 'Approved scope: ' . implode(', ', $brief['scope']) . "\n";
        }

        $scope = $report['scope'];
        if (!$scope['changed_files_supplied']) {
            echo "Changed files: not supplied (pass --changed-file; workflow report does not run git)\n";
        } elseif ($scope['outside_approved_scope'] === []) {
            echo "Changed files: all supplied paths are within approved scope\n";
        } else {
            echo 'Changed files outside approved scope: ' . implode(', ', $scope['outside_approved_scope']) . "\n";
        }

        echo "Validation evidence:\n";
        if ($report['validation'] === []) {
            echo "  - no work brief or validation commands\n";
        }
        foreach ($report['validation'] as $validation) {
            echo '  - [' . $validation['status'] . '] ' . $validation['command'] . "\n";
        }

        $recall = $report['recall'];
        echo sprintf("Recall: %s, outcome draft %s, %d logged outcome(s)\n", $recall['status'], $recall['outcome_draft'] ? 'present' : 'missing', $recall['logged_outcomes']);
        $review = $report['review'];
        echo 'Review: ' . ($review['exists'] ? ($review['invalid'] ? 'invalid' : $review['status']) : 'missing') . "\n";
        $learning = $report['learning'];
        echo sprintf("Learning: %s, %d finding(s), %d proposal(s), %d outcome(s)\n", $learning['status'], $learning['findings'], $learning['proposals'], $learning['outcomes']);
        $risk = $report['accepted_risk'];
        echo 'Accepted risk: ' . ($risk['recorded'] ? 'recorded at ' . $risk['path'] : 'none') . "\n";
    }
}
