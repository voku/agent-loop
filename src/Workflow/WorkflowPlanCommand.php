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
 * Creates the initial governed task state without compiling a prompt against
 * provisional input. Recall is compiled after the work brief is approved, so
 * its task context is an explicit, revisioned contract rather than a parallel
 * list of manual --file arguments.
 */
final readonly class WorkflowPlanCommand
{
    /** @param callable(list<string>): int $sessionRunner */
    public function __construct(private string $rootPath, private mixed $sessionRunner)
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
                $sessionArgs = ['start', '--task', $taskId->value, '--by', $options['by']];
                if ($options['baseCommit'] !== null) {
                    $sessionArgs[] = '--base-commit';
                    $sessionArgs[] = $options['baseCommit'];
                }
                $exit = ($this->sessionRunner)($sessionArgs);
                if ($exit !== 0) {
                    return $exit;
                }
                $briefAction = 'create';
            } else {
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
        foreach ($options['tags'] as $tag) {
            $briefArgs[] = '--tag';
            $briefArgs[] = $tag;
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
     * @return array{by: string, learningRoot: string, files: list<string>, goal: string, scope: list<string>, nonGoals: list<string>, validation: list<string>, tags: list<string>, baseCommit: string|null}
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
        $tags = [];
        $baseCommit = null;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--by', '--learning-root', '--root', '--file', '--goal', '--scope', '--non-goal', '--validation', '--tag', '--base-commit'], true)) {
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
                '--tag' => $tags[] = $value,
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
            'tags' => $tags,
            'baseCommit' => $baseCommit,
        ];
    }
}
