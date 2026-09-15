<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\AgentOutput;
use voku\AgentLoop\Run\CanonicalJson;

/** Read-only CLI adapter over the typed Loop-owned progress service. */
final readonly class WorkflowProgressCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $format = $this->format(array_slice($args, 1));
            $progress = (new WorkflowProgressService($this->rootPath))->forTask($taskId->value);
            $payload = $progress->toArray();

            if ($format === 'json') {
                echo CanonicalJson::pretty($payload);
            } elseif ($format === 'toon') {
                echo AgentOutput::toon($payload);
            } else {
                echo 'Task ' . $progress->taskId . "\n";
                echo 'Overall: ' . $progress->state . "\n";
                foreach ($progress->steps as $step) {
                    printf("  %-20s %-15s %s\n", $step->label . ':', $step->state, $step->owner);
                }
                echo 'Next kind: ' . $progress->nextActionKind . "\n";
                echo 'Next: ' . $progress->nextAction . "\n";
            }

            return $progress->state === 'blocked' ? 2 : 0;
        } catch (Throwable $exception) {
            fwrite(\STDERR, '[FAIL] workflow progress: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return 'text'|'json'|'toon'
     */
    private function format(array $tokens): string
    {
        if ($tokens === []) {
            return 'text';
        }

        $value = null;
        if (count($tokens) === 1 && str_starts_with($tokens[0], '--format=')) {
            $value = substr($tokens[0], strlen('--format='));
        } elseif (count($tokens) === 2 && $tokens[0] === '--format') {
            $value = $tokens[1];
        }
        if ($value === null) {
            throw new InvalidArgumentException('Usage: workflow progress <task-id> [--format text|json|toon]');
        }

        $format = strtolower(trim($value));
        if (!in_array($format, ['text', 'json', 'toon'], true)) {
            throw new InvalidArgumentException('--format must be text, json, or toon.');
        }

        return $format;
    }
}
