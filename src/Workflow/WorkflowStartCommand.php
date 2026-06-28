<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

final readonly class WorkflowStartCommand
{
    /** @param callable(list<string>): int $sessionRunner @param callable(list<string>): int $recallRunner */
    public function __construct(private mixed $sessionRunner, private mixed $recallRunner)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
        } catch (InvalidArgumentException $e) {
            fwrite(\STDERR, '[FAIL] workflow start: ' . $e->getMessage() . "\n");
            return 1;
        }

        $sessionArgv = ['start', '--task', $taskId->value, '--by', $options['by']];
        if ($options['baseCommit'] !== null) {
            $sessionArgv[] = '--base-commit';
            $sessionArgv[] = $options['baseCommit'];
        }

        $exit = ($this->sessionRunner)($sessionArgv);
        if ($exit !== 0) {
            return $exit;
        }
        echo "[OK] workflow start: session started for {$taskId->value}\n";

        $recallArgv = ['compile', '--root', $options['learningRoot'], '--task', $taskId->value];
        foreach ($options['files'] as $file) {
            $recallArgv[] = '--file';
            $recallArgv[] = $file;
        }

        $exit = ($this->recallRunner)($recallArgv);
        if ($exit !== 0) {
            return $exit;
        }

        echo "[OK] workflow start: recall compile completed for {$taskId->value}\n\n";
        echo "Next:\n";
        echo "  agent-loop session checkpoint {$taskId->value} --title \"Validation\" --body \"PHPStan passed.\"\n";
        echo "  agent-loop review blindspots {$taskId->value}\n";
        echo "  agent-loop verify\n";
        echo "  agent-loop workflow status {$taskId->value}\n";
        echo "  agent-loop workflow close {$taskId->value} --status done\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{by: string, learningRoot: string, files: list<string>, baseCommit: string|null}
     */
    private function parse(array $tokens): array
    {
        $by = null; $root = null; $files = []; $base = null;
        for ($i = 0, $c = count($tokens); $i < $c; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--by', '--learning-root', '--root', '--file', '--base-commit'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = $tokens[++$i];
            if ($token === '--by') { $by = $value; }
            elseif ($token === '--learning-root' || $token === '--root') { $root = $value; }
            elseif ($token === '--file') { $files[] = $value; }
            else { $base = $value; }
        }
        if ($by === null || trim($by) === '') { throw new InvalidArgumentException('--by is required.'); }
        if ($root === null || trim($root) === '') { throw new InvalidArgumentException('--learning-root is required.'); }
        if ($files === []) { throw new InvalidArgumentException('--file is required.'); }
        return ['by' => $by, 'learningRoot' => $root, 'files' => $files, 'baseCommit' => $base];
    }
}
