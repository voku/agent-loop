<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use RuntimeException;
use voku\AgentKanban\Cli\CliApplication;
use voku\AgentKanban\Verification\BoardVerifier;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentLearning\LearningRepositoryValidator;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentRecallCompiler\Review\ReviewCli as RecallReviewCli;
use voku\AgentSession\Cli as SessionCli;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

/**
 * Cross-package consistency check for the agentic-coding loop.
 *
 * `agent-loop verify` is the only command that looks across board, Session,
 * Recall, durable Contract/Run and Learning state at once. Optional inputs are
 * skipped rather than fabricated.
 */
final class AgentLoopVerifier
{
    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    /** @var list<string> */
    private array $taskIds = [];

    /** @param list<string> $tokens tokens after the `verify` namespace */
    public function run(array $tokens): int
    {
        if (array_intersect($tokens, ['help', '--help', '-h']) !== []) {
            echo $this->usage();

            return 0;
        }

        $options = $this->parseOptions($tokens);
        $strict = in_array('--strict', $tokens, true);
        $taskId = $options['task-id'];
        $boardRoot = (new ProjectLayout($this->rootPath))->boardRoot();

        echo "agent-loop verify - cross-package consistency check\n\n";
        if ($taskId !== null) {
            echo "Scoped to task {$taskId}: unrelated tasks' drift will not fail this run.\n\n";
        }

        $results = [
            $this->checkPackagesWired(),
            $this->checkTasks($options['tasks-root'], $strict, $taskId),
            $this->checkBoard($boardRoot),
            $this->checkSessionsAndRecall($options['sessions-root'], $options['recall-root'], $strict, $taskId),
            $this->checkLearningRoot($options['learning-root']),
        ];

        $passed = !in_array(false, $results, true);

        echo "\n" . ($passed
            ? "[OK] agent-loop verify: no drift detected.\n"
            : "[FAIL] agent-loop verify: drift detected, see above.\n");

        return $passed ? 0 : 1;
    }

    /**
     * @param list<string> $tokens
     * @return array{tasks-root: string, sessions-root: string, recall-root: string, learning-root: ?string, task-id: ?string}
     */
    private function parseOptions(array $tokens): array
    {
        $layout = new ProjectLayout($this->rootPath);
        $options = [
            'tasks-root' => $layout->tasksRoot(),
            'sessions-root' => $layout->sessionsRoot(),
            'recall-root' => $layout->recallRoot(),
            'learning-root' => $layout->learningRoot(),
            'task-id' => null,
        ];

        foreach ($tokens as $token) {
            foreach (['tasks-root', 'sessions-root', 'recall-root', 'learning-root', 'task-id'] as $key) {
                $prefix = '--' . $key . '=';
                if (str_starts_with($token, $prefix)) {
                    $options[$key] = substr($token, strlen($prefix));
                }
            }
        }

        return $options;
    }

    /** Confirms every namespace routed by Dispatcher still resolves. */
    private function checkPackagesWired(): bool
    {
        $delegates = [
            'board' => CliApplication::class,
            'board (verifier)' => BoardVerifier::class,
            'learn' => LearningCli::class,
            'map' => AgentMapApplication::class,
            'recall' => RecallCli::class,
            'review' => RecallReviewCli::class,
            'session' => SessionCli::class,
            'workflow' => WorkflowCli::class,
        ];

        $missing = [];
        foreach ($delegates as $namespace => $class) {
            if (!class_exists($class)) {
                $missing[] = "{$namespace} -> {$class}";
            }
        }

        if ($missing !== []) {
            echo "[FAIL] package delegates: missing classes for " . implode(', ', $missing) . "\n";

            return false;
        }

        echo "[OK] package delegates: board, learn, map, recall, review, session, workflow commands all resolve to an installed package\n";

        return true;
    }

    private function checkTasks(string $tasksRoot, bool $strict, ?string $taskId): bool
    {
        if (!is_dir($tasksRoot)) {
            return $this->skipOrFail('tasks', "no directory at {$tasksRoot}", $strict);
        }

        $files = glob($tasksRoot . '/*.md') ?: [];
        if ($files === []) {
            return $this->skipOrFail('tasks', "{$tasksRoot} has no *.md task files", $strict);
        }

        sort($files);
        $ids = [];
        $broken = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $id = basename($file, '.md');
            if (trim($content) === '' || !preg_match('/^#\s+\S/m', $content)) {
                if ($taskId === null || $id === $taskId) {
                    $broken[] = $file;
                }
                continue;
            }

            $ids[] = $id;
        }

        $this->taskIds = $ids;

        if ($broken !== []) {
            echo '[FAIL] tasks: ' . count($broken) . ' file(s) did not parse (empty or missing a top-level heading): ' . implode(', ', $broken) . "\n";

            return false;
        }

        echo '[OK] tasks: ' . count($ids) . ' task file(s) parsed' . ($taskId !== null ? " (scoped to {$taskId})" : ': ' . implode(', ', $ids)) . "\n";

        return true;
    }

    private function checkBoard(string $boardRoot): bool
    {
        $root = rtrim($boardRoot, '/');
        $metadata = $root . '/todo/board.md';
        $config = $root . '/todo/kanban.config.json';
        $cards = array_merge(glob($root . '/todo/cards/*.md') ?: [], glob($root . '/todo/jira/*.md') ?: []);
        if (!is_file($metadata) && !is_file($config) && $cards === []) {
            echo "[SKIP] board: no typed board source at {$root}/todo/board.md, {$root}/todo/kanban.config.json, {$root}/todo/cards/, or {$root}/todo/jira/\n";

            return true;
        }

        ob_start();
        try {
            $exit = (new CliApplication($boardRoot))->run(['agent-loop', 'verify']);
        } finally {
            $boardOutput = (string) ob_get_clean();
        }

        if ($exit === 0) {
            echo "[OK] board: kanban board projection verified (delegated to voku/agent-kanban)\n";

            return true;
        }

        echo $boardOutput;
        echo "[FAIL] board: kanban board verification failed, see voku/agent-kanban error above\n";

        return false;
    }

    private function checkSessionsAndRecall(string $sessionsRoot, string $recallRoot, bool $strict, ?string $taskId): bool
    {
        if (!is_dir($sessionsRoot)) {
            return $this->skipOrFail('sessions', "no directory at {$sessionsRoot}", $strict);
        }

        try {
            $sessions = (new SessionStore())->all($sessionsRoot);
        } catch (RuntimeException $exception) {
            echo "[FAIL] sessions: {$exception->getMessage()}\n";

            return false;
        }

        $ok = $this->checkRecallStaleness($recallRoot, $taskId);

        if ($taskId !== null) {
            $sessions = array_values(array_filter($sessions, static fn (Session $session): bool => $session->taskId === $taskId));
        }

        if ($sessions === []) {
            echo "[OK] sessions: 0 sessions found under {$sessionsRoot}" . ($taskId !== null ? " for task {$taskId}" : '') . "\n";

            return $ok;
        }

        $activeCount = 0;
        foreach ($sessions as $session) {
            if ($session->status->isClosed()) {
                continue;
            }

            ++$activeCount;
            if ($session->ephemeral) {
                echo "[SKIP] sessions: {$session->id} is ephemeral; repository gates do not apply\n";
                continue;
            }

            if ($taskId === null && $this->taskIds !== [] && !in_array($session->taskId, $this->taskIds, true)) {
                echo "[FAIL] sessions: session {$session->id} points to unknown task '{$session->taskId}'\n";
                $ok = false;
                continue;
            }

            $ok = $this->checkRecallCoverage($recallRoot, $session->id, $session->taskId) && $ok;
            $ok = $this->checkGovernedBinding($session) && $ok;
        }

        if ($ok) {
            echo '[OK] sessions: ' . count($sessions) . ' session(s) parsed, ' . $activeCount . " active and consistent\n";
        }

        return $ok;
    }

    private function checkRecallCoverage(string $recallRoot, string $sessionId, string $taskId): bool
    {
        $metaFile = rtrim($recallRoot, '/') . '/' . $taskId . '/meta.json';
        if (is_file($metaFile)) {
            return true;
        }

        $currentMetaFile = rtrim($recallRoot, '/') . '/current/meta.json';
        if (is_file($currentMetaFile)) {
            $decoded = json_decode((string) file_get_contents($currentMetaFile), true);
            if (is_array($decoded) && isset($decoded['task_id']) && $decoded['task_id'] === $taskId) {
                return true;
            }
        }

        echo "[FAIL] recall: active session {$sessionId} (task {$taskId}) has no compiled briefing at {$metaFile}\n";

        return false;
    }

    /**
     * If a Session belongs to a governed Run, prove the durable owner boundary.
     * Standalone Session+Recall usage remains valid and has no invented Contract.
     */
    private function checkGovernedBinding(Session $session): bool
    {
        $run = (new GovernedRunStore($this->rootPath))->find($session->taskId);
        if ($run === null) {
            return true;
        }
        if ($run->sessionId !== $session->id) {
            echo "[FAIL] governed run: {$run->runId} points to Session {$run->sessionId}, not active Session {$session->id}\n";

            return false;
        }

        $contract = (new TaskContractStore($this->rootPath))->find($session->taskId);
        if ($contract === null || $contract->status !== TaskContract::APPROVED) {
            echo "[FAIL] governed run: {$run->runId} has no current approved durable Contract\n";

            return false;
        }
        if ($run->contractRevision !== $contract->revision) {
            echo "[FAIL] governed run: {$run->runId} Contract revision {$run->contractRevision} differs from current revision {$contract->revision}\n";

            return false;
        }
        $hash = hash_file('sha256', $contract->path);
        if ($hash === false || !hash_equals($run->contractSource['sha256'], 'sha256:' . $hash)) {
            echo "[FAIL] governed run: {$run->runId} Contract digest is stale\n";

            return false;
        }

        echo "[OK] governed run: {$run->runId} binds active Session {$session->id} to approved Contract revision {$contract->revision}\n";

        return true;
    }

    /** Recomputes Recall output hashes and flags out-of-band edits. */
    private function checkRecallStaleness(string $recallRoot, ?string $taskId): bool
    {
        if (!is_dir($recallRoot)) {
            return true;
        }

        if ($taskId !== null) {
            $taskDirs = array_filter([rtrim($recallRoot, '/') . '/' . $taskId], 'is_dir');
            $currentDir = rtrim($recallRoot, '/') . '/current';
            $currentMeta = $currentDir . '/meta.json';
            if ($taskDirs === [] && is_file($currentMeta)) {
                $decoded = json_decode((string) file_get_contents($currentMeta), true);
                if (is_array($decoded) && ($decoded['task_id'] ?? null) === $taskId) {
                    $taskDirs = [$currentDir];
                }
            }
        } else {
            $taskDirs = glob($recallRoot . '/*', GLOB_ONLYDIR) ?: [];
        }
        $ok = true;

        foreach ($taskDirs as $taskDir) {
            $metaFile = $taskDir . '/meta.json';
            if (!is_file($metaFile)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($metaFile), true);
            if (!is_array($decoded)) {
                echo "[FAIL] recall: {$metaFile} is not valid JSON\n";
                $ok = false;
                continue;
            }

            $directoryTaskId = basename($taskDir);
            if (isset($decoded['task_id']) && $decoded['task_id'] !== $directoryTaskId && $directoryTaskId !== 'current') {
                echo "[INFO] recall: {$metaFile} task_id '{$decoded['task_id']}' differs from directory name '{$directoryTaskId}'\n";
            }

            $hashes = $decoded['output_hashes'] ?? [];
            if (!is_array($hashes)) {
                echo "[FAIL] recall: {$metaFile} has a malformed output_hashes field\n";
                $ok = false;
                continue;
            }

            foreach ($hashes as $relativeFile => $expectedHash) {
                if (!is_string($relativeFile) || !is_string($expectedHash)) {
                    continue;
                }

                $outputFile = $taskDir . '/' . $relativeFile;
                if (!is_file($outputFile)) {
                    echo "[FAIL] recall: {$outputFile} referenced in meta.json is missing\n";
                    $ok = false;
                    continue;
                }

                $actualHash = hash('sha256', (string) file_get_contents($outputFile));
                if (!hash_equals($expectedHash, $actualHash)) {
                    echo "[FAIL] recall: {$outputFile} is stale (hash no longer matches meta.json)\n";
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    private function skipOrFail(string $label, string $detail, bool $strict): bool
    {
        if ($strict) {
            echo "[FAIL] {$label}: {$detail} (required with --strict)\n";

            return false;
        }

        echo "[SKIP] {$label}: {$detail}\n";

        return true;
    }

    private function checkLearningRoot(?string $learningRoot): bool
    {
        if ($learningRoot === null || !is_dir($learningRoot)) {
            echo '[SKIP] learning root: no configured or detected directory found' . "\n";

            return true;
        }

        try {
            $result = (new LearningRepositoryValidator())->validate($learningRoot);
        } catch (RuntimeException $exception) {
            echo "[FAIL] learning root: {$exception->getMessage()}\n";

            return false;
        }

        echo '[OK] learning root: validated ' . $learningRoot
            . ' (' . count($result->findingsById) . ' finding(s), ' . count($result->proposalsById) . " proposal(s), outcome/decision history parsed)\n";

        return true;
    }

    private function usage(): string
    {
        return <<<TXT
        agent-loop verify - cross-package consistency check.

        Usage:
          agent-loop verify [options]

        Checks (each skips itself when its inputs are absent):
          - package delegates: board/learn/map/recall/session/workflow classes are installed
          - tasks:    every *.md file under .agent-loop/tasks/ parses
          - board:    typed kanban board verification under .agent-loop/todo/
          - sessions: every non-closed Session under .agent-loop/sessions/ points
                      to a known task id and has a compiled Recall briefing
          - governed Run: when a Session is attached to a Run, Run, Session and
                          approved Contract revision/digest must agree
          - recall:   every .agent-loop/recall/<task>/meta.json output_hashes entry
                      still matches the file on disk
          - learning: .agent-loop/learning validates when present

        Options:
          --tasks-root=PATH     Default: <root>/.agent-loop/tasks
          --sessions-root=PATH  Default: <root>/.agent-loop/sessions
          --recall-root=PATH    Default: <root>/.agent-loop/recall
          --learning-root=PATH  Default: <root>/.agent-loop/learning
          --strict              Fail (instead of [SKIP]) when tasks or Sessions
                                are missing entirely. Board and Learning remain optional.
          --task-id=ID          Scope tasks/sessions/recall checks to one task.

        TXT;
    }
}
