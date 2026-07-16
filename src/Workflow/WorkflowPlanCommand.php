<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

/**
 * Creates the initial governed task state without duplicating session or
 * recall implementation details. The supplied file scope is reused for the
 * work brief unless a broader/narrower explicit --scope is supplied.
 */
final readonly class WorkflowPlanCommand
{
    /** @param callable(list<string>): int $sessionRunner @param callable(list<string>): int $recallRunner */
    public function __construct(private string $rootPath, private mixed $sessionRunner, private mixed $recallRunner)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, '[FAIL] workflow plan: ' . $e->getMessage() . "\n");

            return 1;
        }

        try {
            $activeSession = $this->activeSession($taskId->value);
            if ($activeSession === null) {
                $exit = (new WorkflowStartCommand($this->rootPath, $this->sessionRunner, $this->recallRunner))->run($this->startArgs($taskId->value, $options));
                if ($exit !== 0) {
                    return $exit;
                }
                $briefAction = 'create';
            } else {
                $exit = ($this->recallRunner)($this->recallArgs($taskId->value, $options));
                if ($exit !== 0) {
                    return $exit;
                }
                $briefAction = (new WorkBriefStore())->find($activeSession) === null ? 'create' : 'revise';
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[FAIL] workflow plan: ' . $e->getMessage() . "\n");

            return 1;
        }

        $briefArgs = ['brief', $briefAction, $taskId->value, '--goal', $options['goal']];
        foreach ($options['scope'] as $scope) {
            $briefArgs[] = '--scope';
            $briefArgs[] = $scope;
        }
        foreach ($options['nonGoals'] as $nonGoal) {
            $briefArgs[] = '--non-goal';
            $briefArgs[] = $nonGoal;
        }
        foreach ($options['validation'] as $validation) {
            $briefArgs[] = '--validation';
            $briefArgs[] = $validation;
        }

        $exit = ($this->sessionRunner)($briefArgs);
        if ($exit !== 0) {
            return $exit;
        }

        echo "[OK] workflow plan: candidate work brief {$briefAction}d for {$taskId->value}\n";
        echo "Next:\n";
        echo "  agent-loop workflow approve {$taskId->value} --by {$options['by']}\n";

        return 0;
    }

    /**
     * @param array{by: string, learningRoot: string, files: list<string>, goal: string, scope: list<string>, nonGoals: list<string>, validation: list<string>, baseCommit: string|null} $options
     * @return list<string>
     */
    private function startArgs(string $taskId, array $options): array
    {
        $args = [$taskId, '--by', $options['by'], '--learning-root', $options['learningRoot']];
        foreach ($options['files'] as $file) {
            $args[] = '--file';
            $args[] = $file;
        }
        if ($options['baseCommit'] !== null) {
            $args[] = '--base-commit';
            $args[] = $options['baseCommit'];
        }

        return $args;
    }

    /**
     * @param array{by: string, learningRoot: string, files: list<string>, goal: string, scope: list<string>, nonGoals: list<string>, validation: list<string>, baseCommit: string|null} $options
     * @return list<string>
     */
    private function recallArgs(string $taskId, array $options): array
    {
        $args = ['compile', '--root', $options['learningRoot'], '--task', $taskId];
        foreach ($options['files'] as $file) {
            $args[] = '--file';
            $args[] = $file;
        }

        return $args;
    }

    private function activeSession(string $taskId): ?Session
    {
        $sessionsRoot = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($sessionsRoot)) {
            return null;
        }

        $sessions = array_values(array_filter(
            (new SessionStore())->all($sessionsRoot),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));
        if (count($sessions) > 1) {
            throw new RuntimeException("Multiple active sessions found for task {$taskId}; pass a generated session id through agent-loop session brief instead.");
        }

        return $sessions[0] ?? null;
    }

    /**
     * @param list<string> $tokens
     * @return array{by: string, learningRoot: string, files: list<string>, goal: string, scope: list<string>, nonGoals: list<string>, validation: list<string>, baseCommit: string|null}
     */
    private function parse(array $tokens): array
    {
        $by = null;
        $learningRoot = null;
        $files = [];
        $goal = null;
        $scope = [];
        $nonGoals = [];
        $validation = [];
        $baseCommit = null;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--by', '--learning-root', '--root', '--file', '--goal', '--scope', '--non-goal', '--validation', '--base-commit'], true)) {
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
                '--by' => $by = $value,
                '--learning-root', '--root' => $learningRoot = $value,
                '--file' => $files[] = $value,
                '--goal' => $goal = $value,
                '--scope' => $scope[] = $value,
                '--non-goal' => $nonGoals[] = $value,
                '--validation' => $validation[] = $value,
                '--base-commit' => $baseCommit = $value,
            };
        }

        if ($by === null) {
            throw new InvalidArgumentException('--by is required.');
        }
        if ($files === []) {
            throw new InvalidArgumentException('--file is required.');
        }
        if ($goal === null) {
            throw new InvalidArgumentException('--goal is required.');
        }
        if ($validation === []) {
            throw new InvalidArgumentException('--validation is required.');
        }

        return [
            'by' => $by,
            'learningRoot' => WorkflowLearningRoot::resolve($this->rootPath, $learningRoot),
            'files' => $files,
            'goal' => $goal,
            'scope' => $scope === [] ? $files : $scope,
            'nonGoals' => $nonGoals,
            'validation' => $validation,
            'baseCommit' => $baseCommit,
        ];
    }
}
