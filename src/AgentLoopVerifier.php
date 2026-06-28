<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use RuntimeException;
use voku\AgentKanban\TodoBoardCli;
use voku\AgentKanban\TodoBoardVerifier;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentLearning\LearningRepositoryValidator;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentRecallCompiler\Review\ReviewCli as RecallReviewCli;
use voku\AgentSession\Cli as SessionCli;
use voku\AgentSession\SessionStore;

/**
 * Cross-package consistency check for the agentic-coding loop.
 *
 * `agent-loop verify` is the only command that looks *across* board, session,
 * recall, and learning state at once. Every check below skips itself when its
 * inputs are absent, so the command stays meaningful for a repo that only
 * wires up part of the stack (e.g. session + recall, no kanban board).
 */
final class AgentLoopVerifier
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $projectPrefix = null,
    ) {
    }

    /**
     * @var list<string>
     */
    private array $taskIds = [];

    /**
     * @param list<string> $tokens tokens after the `verify` namespace
     */
    public function run(array $tokens): int
    {
        if (array_intersect($tokens, ['help', '--help', '-h']) !== []) {
            echo $this->usage();

            return 0;
        }

        $options = $this->parseOptions($tokens);
        $strict = in_array('--strict', $tokens, true);

        echo "agent-loop verify - cross-package consistency check\n\n";

        $results = [
            $this->checkPackagesWired(),
            $this->checkTasks($options['tasks-root'], $strict),
            $this->checkBoard(),
            $this->checkSessionsAndRecall($options['sessions-root'], $options['recall-root'], $strict),
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
     *
     * @return array{tasks-root: string, sessions-root: string, recall-root: string, learning-root: ?string}
     */
    private function parseOptions(array $tokens): array
    {
        $root = rtrim($this->rootPath, '/');
        $options = [
            'tasks-root' => $root . '/tasks',
            'sessions-root' => $root . '/session_plan',
            'recall-root' => null,
            'learning-root' => null,
        ];

        foreach ($tokens as $token) {
            foreach (['tasks-root', 'sessions-root', 'recall-root', 'learning-root'] as $key) {
                $prefix = '--' . $key . '=';
                if (str_starts_with($token, $prefix)) {
                    $options[$key] = substr($token, strlen($prefix));
                }
            }
        }

        if ($options['learning-root'] === null) {
            foreach ([$root . '/infra/doc/agent-learning', $root . '/learning-root'] as $candidate) {
                if (is_dir($candidate)) {
                    $options['learning-root'] = $candidate;

                    break;
                }
            }
        }

        if ($options['recall-root'] === null) {
            $candidates = [];
            if ($options['learning-root'] !== null) {
                $candidates[] = rtrim($options['learning-root'], '/') . '/recall-output';
                $candidates[] = rtrim($options['learning-root'], '/') . '/recall';
            }
            $candidates[] = $root . '/recall';
            $candidates[] = $root . '/infra/doc/agent-learning/recall-output';
            $candidates[] = $root . '/.agent-recall';

            foreach ($candidates as $candidate) {
                if (is_dir($candidate)) {
                    $options['recall-root'] = $candidate;

                    break;
                }
            }
            $options['recall-root'] ??= $root . '/recall';
        }

        return $options;
    }

    /**
     * Confirms every namespace the Dispatcher routes to still resolves to a
     * loadable class, i.e. the command surface itself is intact.
     */
    private function checkPackagesWired(): bool
    {
        $delegates = [
            'board/verify' => TodoBoardCli::class,
            'board/verify (verifier)' => TodoBoardVerifier::class,
            'learn' => LearningCli::class,
            'recall' => RecallCli::class,
            'review' => RecallReviewCli::class,
            'session' => SessionCli::class,
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

        echo '[OK] package delegates: board, learn, recall, review, session commands all resolve to an installed package' . "\n";

        return true;
    }

    private function checkTasks(string $tasksRoot, bool $strict): bool
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
                $broken[] = $file;

                continue;
            }

            $ids[] = $id;
        }

        $this->taskIds = $ids;

        if ($broken !== []) {
            echo '[FAIL] tasks: ' . count($broken) . ' file(s) did not parse (empty or missing a top-level heading): ' . implode(', ', $broken) . "\n";

            return false;
        }

        echo '[OK] tasks: ' . count($ids) . ' task file(s) parsed: ' . implode(', ', $ids) . "\n";

        return true;
    }

    private function checkBoard(): bool
    {
        $todoFile = rtrim($this->rootPath, '/') . '/TODO.md';
        if (!is_file($todoFile)) {
            echo "[SKIP] board: no TODO.md at {$todoFile}\n";

            return true;
        }

        ob_start();
        $exit = (new TodoBoardVerifier($this->rootPath, $this->projectPrefix))->run();
        $boardOutput = (string) ob_get_clean();

        if ($exit === 0) {
            echo "[OK] board: kanban board projection verified (delegated to voku/agent-kanban)\n";

            return true;
        }

        echo $boardOutput;
        echo "[FAIL] board: kanban board verification failed, see voku/agent-kanban error above\n";

        return false;
    }

    private function checkSessionsAndRecall(string $sessionsRoot, string $recallRoot, bool $strict): bool
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

        $ok = $this->checkRecallStaleness($recallRoot);

        if ($sessions === []) {
            echo "[OK] sessions: 0 sessions found under {$sessionsRoot}\n";

            return $ok;
        }

        $activeCount = 0;
        foreach ($sessions as $session) {
            if ($session->status->isClosed()) {
                continue;
            }

            ++$activeCount;

            if ($this->taskIds !== [] && !in_array($session->taskId, $this->taskIds, true)) {
                echo "[FAIL] sessions: session {$session->id} points to unknown task '{$session->taskId}'\n";
                $ok = false;

                continue;
            }

            $ok = $this->checkRecallCoverage($recallRoot, $session->id, $session->taskId) && $ok;
        }

        if ($ok) {
            echo '[OK] sessions: ' . count($sessions) . ' session(s) parsed, ' . $activeCount . " active and consistent\n";
        }

        return $ok;
    }

    private function checkRecallCoverage(string $recallRoot, string $sessionId, string $taskId): bool
    {
        if (!is_dir($recallRoot)) {
            echo "[FAIL] recall: active session {$sessionId} (task {$taskId}) but no recall/ directory at {$recallRoot}\n";

            return false;
        }

        $metaFile = rtrim($recallRoot, '/') . '/' . $taskId . '/meta.json';
        if (!is_file($metaFile)) {
            $currentMetaFile = rtrim($recallRoot, '/') . '/current/meta.json';
            if (is_file($currentMetaFile)) {
                $decoded = json_decode((string) file_get_contents($currentMetaFile), true);
                if (is_array($decoded) && isset($decoded['task_id']) && $decoded['task_id'] === $taskId) {
                    return true;
                }
            }

            echo "[FAIL] recall: active session {$sessionId} has no compiled briefing at {$metaFile}\n";

            return false;
        }

        return true;
    }

    /**
     * Recomputes the sha256 hashes a `recall compile` run recorded for its own
     * output files and flags any output that was edited or regenerated out of
     * band since.
     */
    private function checkRecallStaleness(string $recallRoot): bool
    {
        if (!is_dir($recallRoot)) {
            return true;
        }

        $taskDirs = glob($recallRoot . '/*', GLOB_ONLYDIR) ?: [];
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

            $taskId = basename($taskDir);
            if (isset($decoded['task_id']) && $decoded['task_id'] !== $taskId && $taskId !== 'current') {
                echo "[INFO] recall: {$metaFile} task_id '{$decoded['task_id']}' differs from directory name '{$taskId}'\n";
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

    /**
     * Reports a missing-input SKIP, or -- under `--strict` -- a FAIL instead.
     *
     * Only checkTasks() and checkSessionsAndRecall() call this: a missing
     * tasks/ or session_plan/ directory means the baseline premise this
     * command exists to confirm (a task exists, a session is tracking it)
     * doesn't hold. checkBoard()'s TODO.md and checkLearningRoot()'s
     * learning root stay unconditionally skippable even under --strict,
     * since both are documented, opt-in additions on top of that baseline
     * loop (see README.md), not something every repo using `agent-loop` is
     * expected to have wired up.
     */
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
            echo '[SKIP] learning root: no directory found (checked --learning-root, infra/doc/agent-learning, learning-root)' . "\n";

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
          - package delegates: board/learn/recall/session classes are installed
          - tasks:    every *.md file under tasks/ parses (non-empty, has a heading)
          - board:    TODO.md kanban board projection (delegated to voku/agent-kanban)
          - sessions: every non-closed session under session_plan/ points to a
                      known task id and has a compiled recall briefing
          - recall:   every recall/<task>/meta.json output_hashes entry still
                      matches the file on disk (catches stale/edited briefings)
          - learning: the learning root (findings/proposals/history) validates

        Options:
          --tasks-root=PATH     Default: <root>/tasks
          --sessions-root=PATH  Default: <root>/session_plan
          --recall-root=PATH    Default: <root>/recall
          --learning-root=PATH  Default: <root>/infra/doc/agent-learning or <root>/learning-root
          --strict              Fail (instead of [SKIP]) when tasks/ or
                                 session_plan/ is missing entirely. board
                                 (TODO.md) and the learning root stay
                                 skippable even in strict mode -- both are
                                 documented opt-in additions, not part of
                                 the baseline task/session loop.

        TXT;
    }
}
