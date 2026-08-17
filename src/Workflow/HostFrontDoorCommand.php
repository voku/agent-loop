<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunManifestProjector;

/**
 * Read-only facade over existing workflow owner artifacts for coding-agent hosts.
 *
 * It deliberately owns no lifecycle state. The manifest remains the canonical
 * state/next-action projection; this class only adds a derived mutation-ready
 * signal for the entry boundary and a strict complete-only completion gate.
 */
final readonly class HostFrontDoorCommand
{
    public function __construct(private string $rootPath)
    {
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
            echo "Read-only: project current workflow truth and bounded context before host mutation.\n";

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
        $context = (new WorkflowContextCommand($this->rootPath))->build($taskId->value, $maxLines, $maxBytes);
        $mutationReady = $this->mutationReady($manifest);

        $payload = [
            'schema_version' => '1.0',
            'command' => 'enter',
            'task_id' => $taskId->value,
            'mutation_ready' => $mutationReady,
            'next_action' => $manifest->nextAction,
            'manifest' => $manifest->toArray(),
            'context' => $context,
        ];

        if ($format === 'json') {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo 'agent-loop enter ' . $taskId->value . "\n";
            echo 'Run: ' . $manifest->runId . "\n";
            echo 'Mode: ' . $manifest->mode . "\n";
            echo 'State: ' . $manifest->state . "\n";
            echo 'Mutation: ' . ($mutationReady ? 'ready' : 'not_ready') . "\n";
            echo 'Next: ' . $manifest->nextAction . "\n";
            if ($manifest->disagreements !== []) {
                echo 'Disagreements: ' . count($manifest->disagreements) . "\n";
            }
            echo "\nContext:\n";
            echo implode("\n", $context['lines']) . "\n";
        }

        if ($manifest->state === 'blocked') {
            return 2;
        }
        if (in_array($manifest->state, ['ready_to_close', 'complete'], true)) {
            return 0;
        }

        return $mutationReady ? 0 : 1;
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
        $complete = $manifest->state === 'complete';

        $payload = [
            'schema_version' => '1.0',
            'command' => 'finish',
            'task_id' => $taskId->value,
            'complete' => $complete,
            'next_action' => $manifest->nextAction,
            'manifest' => $manifest->toArray(),
        ];

        if ($format === 'json') {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo 'agent-loop finish ' . $taskId->value . "\n";
            echo 'Run: ' . $manifest->runId . "\n";
            echo 'State: ' . $manifest->state . "\n";
            echo 'Complete: ' . ($complete ? 'yes' : 'no') . "\n";
            echo 'Next: ' . $manifest->nextAction . "\n";
        }

        if ($complete) {
            return 0;
        }

        return $manifest->state === 'blocked' ? 2 : 1;
    }

    private function mutationReady(RunManifest $manifest): bool
    {
        if ($manifest->mode !== 'governed' || $manifest->disagreements !== []) {
            return false;
        }

        return $this->referenceState($manifest, 'contract') === 'approved'
            && $this->referenceState($manifest, 'approval') === 'current'
            && $this->referenceState($manifest, 'session') === 'active'
            && $this->referenceState($manifest, 'recall') === 'compiled'
            && in_array($this->referenceState($manifest, 'execution_contract'), ['ready', 'not_required'], true);
    }

    private function referenceState(RunManifest $manifest, string $name): ?string
    {
        $state = $manifest->references[$name]['state'] ?? null;

        return is_string($state) ? $state : null;
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
