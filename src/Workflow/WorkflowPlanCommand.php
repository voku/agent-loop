<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentSession\OperatingPromptSelection;
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
    public function __construct(private string $rootPath)
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
            $sessions = new SessionStore();
            $briefs = new WorkBriefStore();
            $activeSession = $this->activeSession($taskId->value);

            if ($activeSession === null) {
                $activeSession = $sessions->create(
                    rtrim($this->rootPath, '/') . '/session_plan',
                    $taskId->value,
                    null,
                    $options['by'],
                    $options['baseCommit'],
                    $options['ephemeral'],
                );
                $briefAction = 'create';
            } else {
                $briefAction = $briefs->find($activeSession) === null ? 'create' : 'revise';
            }

            if ($briefAction === 'create') {
                $briefs->create(
                    $activeSession,
                    $options['goal'],
                    $options['scope'],
                    $options['nonGoals'],
                    $options['validation'],
                    $options['tags'],
                    $options['behaviorAnchors'],
                    $options['operatingPromptManifest'],
                    $options['operatingPrompts'],
                );
            } else {
                $briefs->revise(
                    $activeSession,
                    $options['goal'],
                    $options['scope'],
                    $options['nonGoals'],
                    $options['validation'],
                    $options['tags'],
                    $options['behaviorAnchors'],
                    $options['operatingPromptManifest'],
                    $options['operatingPrompts'],
                );
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[FAIL] workflow plan: ' . $e->getMessage() . "\n");

            return 1;
        }

        try {
            $manifestPath = (new RunManifestTransitionWriter($this->rootPath))->write($taskId->value);
        } catch (Throwable $exception) {
            fwrite(
                STDERR,
                '[FAIL] workflow plan: candidate brief was written, but run-manifest refresh failed: '
                . $exception->getMessage()
                . "\n[ACTION REQUIRED] Run agent-loop workflow manifest {$taskId->value} --write after repairing the projection error.\n",
            );

            return 1;
        }

        echo "[OK] workflow plan: candidate work brief {$briefAction}d for {$taskId->value}\n";
        echo "[OK] workflow plan: run manifest refreshed at {$manifestPath}\n";
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
            throw new RuntimeException("Multiple active sessions found for task {$taskId}.");
        }

        return $sessions[0] ?? null;
    }

    /**
     * @param list<string> $tokens
     * @return array{by: string, learningRoot: string, files: list<string>, goal: string, scope: list<string>, nonGoals: list<string>, validation: list<string>, tags: list<string>, behaviorAnchors: list<string>, operatingPromptManifest: string|null, operatingPrompts: list<OperatingPromptSelection>, baseCommit: string|null, ephemeral: bool}
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
        $behaviorAnchors = [];
        $operatingPromptManifest = null;
        $operatingPrompts = [];
        $operatingPromptIds = [];
        $baseCommit = null;
        $ephemeral = false;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--ephemeral') {
                $ephemeral = true;

                continue;
            }
            if (!in_array($token, ['--by', '--learning-root', '--root', '--file', '--goal', '--scope', '--non-goal', '--validation', '--tag', '--behavior-anchor', '--operating-prompt-manifest', '--operating-prompt', '--base-commit'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }

            $value = trim($tokens[++$i]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }

            switch ($token) {
                case '--by':
                    $by = $value;
                    break;
                case '--learning-root':
                case '--root':
                    $learningRoot = $value;
                    break;
                case '--file':
                    $files[] = $value;
                    break;
                case '--goal':
                    $goal = $value;
                    break;
                case '--scope':
                    $scope[] = $value;
                    break;
                case '--non-goal':
                    $nonGoals[] = $value;
                    break;
                case '--validation':
                    $validation[] = $value;
                    break;
                case '--tag':
                    $tags[] = $value;
                    break;
                case '--behavior-anchor':
                    $behaviorAnchors[] = $value;
                    break;
                case '--operating-prompt-manifest':
                    if ($operatingPromptManifest !== null) {
                        throw new InvalidArgumentException('--operating-prompt-manifest may be provided only once.');
                    }
                    $operatingPromptManifest = $value;
                    break;
                case '--operating-prompt':
                    $selection = OperatingPromptSelection::fromJson($value);
                    if (isset($operatingPromptIds[$selection->id])) {
                        throw new InvalidArgumentException('Operating prompt selected more than once: ' . $selection->id);
                    }
                    $operatingPromptIds[$selection->id] = true;
                    $operatingPrompts[] = $selection;
                    break;
                case '--base-commit':
                    $baseCommit = $value;
                    break;
            }
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
        if ($operatingPrompts !== [] && $operatingPromptManifest === null) {
            throw new InvalidArgumentException('--operating-prompt requires --operating-prompt-manifest.');
        }
        if ($operatingPromptManifest !== null && $operatingPrompts === []) {
            throw new InvalidArgumentException('--operating-prompt-manifest requires at least one --operating-prompt.');
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
            'behaviorAnchors' => $behaviorAnchors,
            'operatingPromptManifest' => $operatingPromptManifest,
            'operatingPrompts' => $operatingPrompts,
            'baseCommit' => $baseCommit,
            'ephemeral' => $ephemeral,
        ];
    }
}
