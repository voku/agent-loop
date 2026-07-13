<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

final readonly class WorkflowApproveCommand
{
    /** @param callable(list<string>): int $sessionRunner */
    public function __construct(private mixed $sessionRunner)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $by = $this->parse(array_slice($args, 1));
        } catch (InvalidArgumentException $e) {
            fwrite(STDERR, '[FAIL] workflow approve: ' . $e->getMessage() . "\n");

            return 1;
        }

        $exit = ($this->sessionRunner)(['brief', 'approve', $taskId->value, '--by', $by]);
        if ($exit === 0) {
            echo "[OK] workflow approve: work brief approved for {$taskId->value}\n";
        }

        return $exit;
    }

    /** @param list<string> $tokens */
    private function parse(array $tokens): string
    {
        if (count($tokens) !== 2 || $tokens[0] !== '--by' || trim($tokens[1]) === '') {
            throw new InvalidArgumentException('--by is required.');
        }

        return trim($tokens[1]);
    }
}
