<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Creates or revises durable candidate Contract state.
 *
 * PLAN deliberately creates neither Session nor Run. Working memory starts only
 * after an exact Contract revision is approved and a governed Run is prepared.
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
            $contracts = new TaskContractStore($this->rootPath);
            $existing = $contracts->find($taskId->value);

            if ($existing !== null && (new WorkflowSessionResolver($this->rootPath))->active($taskId->value) !== null) {
                throw new RuntimeException(
                    'Cannot revise Contract for ' . $taskId->value
                    . ' while a governed Session is active. Finish or drop that Run before changing durable intent.',
                );
            }

            if ($existing === null) {
                $contract = $contracts->create(
                    $taskId->value,
                    $options['goal'],
                    $options['scope'],
                    $options['nonGoals'],
                    $options['validation'],
                    $options['by'],
                    $options['baseCommit'],
                    $options['tags'],
                    $options['behaviorAnchors'],
                    $options['operatingPromptManifest'],
                    $options['operatingPrompts'],
                    $options['acceptanceCriteria'],
                );
                $action = 'created';
            } else {
                $contract = $contracts->revise(
                    $taskId->value,
                    $options['goal'],
                    $options['scope'],
                    $options['nonGoals'],
                    $options['validation'],
                    $options['by'],
                    $options['baseCommit'],
                    $options['tags'],
                    $options['behaviorAnchors'],
                    $options['operatingPromptManifest'],
                    $options['operatingPrompts'],
                    $options['acceptanceCriteria'],
                );
                $action = 'revised';
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow plan: ' . $exception->getMessage() . "\n");

            return 1;
        }

        echo "[OK] workflow plan: candidate Contract {$action} for {$taskId->value} revision {$contract->revision}\n";
        echo "[OK] workflow plan: durable source {$contract->path}\n";
        echo "Next:\n";
        echo "  agent-loop workflow approve {$taskId->value} --by {$options['by']}\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @return array{by: string, files: list<string>, goal: string, scope: list<string>, nonGoals: list<string>, validation: list<string>, acceptanceCriteria: list<string>, tags: list<string>, behaviorAnchors: list<string>, operatingPromptManifest: string|null, operatingPrompts: list<array{id: string, arguments: array<string, bool|int|string>}>, baseCommit: string|null}
     */
    private function parse(array $tokens): array
    {
        $by = null;
        $files = [];
        $goal = null;
        $scope = [];
        $nonGoals = [];
        $validation = [];
        $acceptanceCriteria = [];
        $tags = [];
        $behaviorAnchors = [];
        $operatingPromptManifest = null;
        $operatingPrompts = [];
        $operatingPromptIds = [];
        $baseCommit = null;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--by', '--file', '--goal', '--scope', '--non-goal', '--validation', '--acceptance', '--tag', '--behavior-anchor', '--operating-prompt-manifest', '--operating-prompt', '--base-commit'], true)) {
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
                case '--acceptance':
                    $acceptanceCriteria[] = $value;
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
                    $selection = $this->operatingPrompt($value);
                    if (isset($operatingPromptIds[$selection['id']])) {
                        throw new InvalidArgumentException('Operating prompt selected more than once: ' . $selection['id']);
                    }
                    $operatingPromptIds[$selection['id']] = true;
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
            'files' => $files,
            'goal' => $goal,
            'scope' => $scope === [] ? $files : $scope,
            'nonGoals' => $nonGoals,
            'validation' => $validation,
            'acceptanceCriteria' => $acceptanceCriteria,
            'tags' => $tags,
            'behaviorAnchors' => $behaviorAnchors,
            'operatingPromptManifest' => $operatingPromptManifest,
            'operatingPrompts' => $operatingPrompts,
            'baseCommit' => $baseCommit,
        ];
    }

    /** @return array{id: string, arguments: array<string, bool|int|string>} */
    private function operatingPrompt(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Operating prompt selection must be valid JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_array($data['arguments'] ?? [])) {
            throw new InvalidArgumentException('Operating prompt selection requires string id and object arguments.');
        }
        $id = trim($data['id']);
        if ($id === '' || preg_match('/^[a-z][a-z0-9._-]*$/', $id) !== 1) {
            throw new InvalidArgumentException('Operating prompt id must match [a-z][a-z0-9._-]*.');
        }
        $arguments = [];
        foreach ($data['arguments'] ?? [] as $name => $argument) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || (!is_bool($argument) && !is_int($argument) && !is_string($argument))) {
                throw new InvalidArgumentException('Operating prompt arguments must use valid names and bool, int, or string values.');
            }
            $arguments[$name] = $argument;
        }
        ksort($arguments);

        return ['id' => $id, 'arguments' => $arguments];
    }
}
