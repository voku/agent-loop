<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunPolicyEvaluator;

/**
 * Host-facing lifecycle front door for governed coding work.
 *
 * `enter` may reconcile deterministic post-approval preparation, but it never
 * approves Contract scope or invents other authority-bearing decisions.
 */
final readonly class HostFrontDoorCommand
{
    private string $rootPath;

    private ?Closure $recallRunner;

    /** @param null|callable(list<string>): int $recallRunner */
    public function __construct(string $rootPath, ?callable $recallRunner = null)
    {
        $this->rootPath = $rootPath;
        $this->recallRunner = $recallRunner === null ? null : Closure::fromCallable($recallRunner);
    }

    /** @param list<string> $args */
    public function run(string $command, array $args): int
    {
        try {
            return match ($command) {
                'enter' => $this->enter($args),
                'finish' => $this->finish($args),
                default => throw new InvalidArgumentException('Unknown host front-door command: ' . $command),
            };
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, '[FAIL] ' . $command . ': ' . $exception->getMessage() . "\n");

            return 1;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] ' . $command . ': ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $args */
    private function enter(array $args): int
    {
        if ($this->helpRequested($args)) {
            echo "Usage: agent-loop enter <task-id> [--format text|json] [--max-lines N] [--max-bytes N]\n";
            echo "Prepare deterministic post-approval workflow state and project bounded context before host mutation.\n";

            return 0;
        }

        $taskId = new WorkflowTaskId($args[0] ?? '');
        $tokens = array_slice($args, 1);
        $this->validateEnterTokens($tokens);
        $format = $this->format($tokens);
        $maxLines = $this->positiveOption($tokens, 'max-lines', 120);
        $maxBytes = $this->positiveOption($tokens, 'max-bytes', 12000);
        if ($maxLines < 12 || $maxBytes < 512) {
            throw new InvalidArgumentException('Context budgets require at least --max-lines=12 and --max-bytes=512.');
        }

        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
        if ($this->needsPreparation($manifest)) {
            $this->prepareApprovedRun($taskId->value);
            $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
        }

        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
        $context = (new WorkflowContextCommand($this->rootPath))->build($taskId->value, $maxLines, $maxBytes);

        $payload = [
            'schema_version' => '1.0',
            'command' => 'enter',
            'task_id' => $taskId->value,
            'mutation_ready' => $policy->mutationAllowed,
            'next_action' => $policy->nextAction,
            'manifest' => $manifest->toArray(),
            'context' => $context,
        ];

        if ($format === 'json') {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo 'agent-loop enter ' . $taskId->value . "\n";
            echo 'Run: ' . $manifest->runId . "\n";
            echo 'Mode: ' . $manifest->mode . "\n";
            echo 'State: ' . $policy->state . "\n";
            echo 'Mutation: ' . ($policy->mutationAllowed ? 'ready' : 'not_ready') . "\n";
            echo 'Next: ' . $policy->nextAction . "\n";
            if ($manifest->disagreements !== []) {
                echo 'Disagreements: ' . count($manifest->disagreements) . "\n";
            }
            echo "\nContext:\n";
            echo implode("\n", $context['lines']) . "\n";
        }

        if ($policy->state === 'blocked') {
            return 2;
        }
        if (in_array($policy->state, ['ready_to_close', 'complete'], true)) {
            return 0;
        }

        return $policy->mutationAllowed ? 0 : 1;
    }

    /** @param list<string> $args */
    private function finish(array $args): int
    {
        if ($this->helpRequested($args)) {
            echo "Usage: agent-loop finish <task-id> [--format text|json]\n";
            echo "Read-only: permit a done claim only after the canonical Run manifest is complete.\n";

            return 0;
        }

        $taskId = new WorkflowTaskId($args[0] ?? '');
        $tokens = array_slice($args, 1);
        $this->validateFinishTokens($tokens);
        $format = $this->format($tokens);
        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
        $complete = $policy->state === 'complete';

        $payload = [
            'schema_version' => '1.0',
            'command' => 'finish',
            'task_id' => $taskId->value,
            'complete' => $complete,
            'next_action' => $policy->nextAction,
            'manifest' => $manifest->toArray(),
        ];

        if ($format === 'json') {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo 'agent-loop finish ' . $taskId->value . "\n";
            echo 'Run: ' . $manifest->runId . "\n";
            echo 'State: ' . $policy->state . "\n";
            echo 'Complete: ' . ($complete ? 'yes' : 'no') . "\n";
            echo 'Next: ' . $policy->nextAction . "\n";
        }

        if ($complete) {
            return 0;
        }

        return $policy->state === 'blocked' ? 2 : 1;
    }

    private function needsPreparation(RunManifest $manifest): bool
    {
        if (in_array($manifest->state, ['blocked', 'experiment', 'ready_to_close', 'complete'], true)) {
            return false;
        }
        if (($manifest->references['contract']['state'] ?? null) !== TaskContract::APPROVED) {
            return false;
        }
        if (($manifest->references['approval']['state'] ?? null) !== 'current') {
            return false;
        }

        return $manifest->mode !== 'governed'
            || ($manifest->references['session']['state'] ?? null) !== 'active'
            || ($manifest->references['recall']['state'] ?? null) !== 'compiled';
    }

    private function prepareApprovedRun(string $taskId): void
    {
        if ($this->recallRunner === null) {
            throw new RuntimeException('agent-loop enter requires a Recall runner for deterministic governed preparation.');
        }

        $contract = (new TaskContractStore($this->rootPath))->load($taskId);
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('agent-loop enter cannot prepare a Contract that is not approved.');
        }

        $runner = $this->recallRunner;
        /** @param list<string> $args */
        $quietRecallRunner = static function (array $args) use ($runner): int {
            ob_start();
            try {
                return $runner($args);
            } finally {
                ob_end_clean();
            }
        };
        $preparer = new WorkflowRunPreparer($this->rootPath, $quietRecallRunner);
        $mapReadiness = $preparer->discoveryReadiness($contract);
        $result = $preparer->prepare($contract, $mapReadiness);
        if (!$result->recallCompiled()) {
            throw new RuntimeException(
                'Governed Run preparation persisted resumable state, but Recall compilation failed with exit code '
                . $result->recallExitCode . '.',
            );
        }
    }

    /** @param list<string> $tokens */
    private function format(array $tokens): string
    {
        $format = OptionTokens::value($tokens, 'format') ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }

        return $format;
    }

    /** @param list<string> $tokens */
    private function positiveOption(array $tokens, string $name, int $default): int
    {
        $value = OptionTokens::value($tokens, $name);
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException('--' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /** @param list<string> $args */
    private function helpRequested(array $args): bool
    {
        return count($args) === 1 && in_array($args[0], ['help', '--help', '-h'], true);
    }

    /** @param list<string> $tokens */
    private function validateEnterTokens(array $tokens): void
    {
        $this->validateTokens($tokens, ['format', 'max-lines', 'max-bytes']);
    }

    /** @param list<string> $tokens */
    private function validateFinishTokens(array $tokens): void
    {
        $this->validateTokens($tokens, ['format']);
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $valueOptions
     */
    private function validateTokens(array $tokens, array $valueOptions): void
    {
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                throw new InvalidArgumentException('Unknown argument: ' . $token);
            }

            $name = strtok(substr($token, 2), '=');
            if (!is_string($name) || !in_array($name, $valueOptions, true)) {
                throw new InvalidArgumentException('Unknown option: --' . (is_string($name) ? $name : ''));
            }

            if (str_contains($token, '=')) {
                if (substr($token, strlen('--' . $name . '=')) === '') {
                    throw new InvalidArgumentException('--' . $name . ' requires a non-empty value.');
                }
                continue;
            }

            $value = $tokens[$index + 1] ?? null;
            if (!is_string($value) || str_starts_with($value, '--')) {
                throw new InvalidArgumentException('--' . $name . ' requires a value.');
            }
            ++$index;
        }
    }
}
