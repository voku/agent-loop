<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use InvalidArgumentException;
use Throwable;
use voku\AgentEdit\Cli\ApplyCommand;
use voku\AgentEdit\EditEngine;
use voku\AgentLoop\Edit\EditMutationLock;
use voku\AgentLoop\Edit\EditRunResult;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\ExecutionContractStore;

/**
 * Governance facade for `agent-loop edit refactor PLAN`.
 *
 * Loop owns only the task id, the `.agent-loop/edit/<task>` bundle and map-index defaults, the execution-contract
 * gate and the shared project mutation lock. Plan decoding, preflight, the transactional apply, receipt writing
 * and the stdout contract are owned by `voku/agent-edit`.
 */
final readonly class RefactorEditCommand
{
    public function __construct(
        private string $projectRoot,
        private EditEngine $engine = new EditEngine(),
        private EditMutationLock $mutationLock = new EditMutationLock(),
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || in_array($tokens[0], ['help', '--help', '-h'], true)) {
            return $this->help();
        }

        try {
            [$arguments, $dryRun] = $this->resolve($tokens);
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }

        $root = $this->projectRoot;
        $command = new ApplyCommand(
            $root,
            $this->engine,
            static function (string $taskId) use ($root): void {
                (new ExecutionContractStore($root))->assertReadyForMutation($taskId);
            },
        );
        if ($dryRun) {
            return $command->run($arguments);
        }

        $exit = 1;
        try {
            $this->mutationLock->synchronized($root, static function () use ($command, $arguments, &$exit): EditRunResult {
                $exit = $command->run($arguments);

                return new EditRunResult($exit === 0 ? 'runner_succeeded' : 'runner_failed', $exit);
            });
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }

        return $exit;
    }

    /**
     * Applies Loop's CLI contract (explicit task id, project-layout defaults) to the raw tokens.
     *
     * @param list<string> $tokens
     * @return array{0: list<string>, 1: bool} agent-edit apply arguments and the dry-run flag
     */
    private function resolve(array $tokens): array
    {
        $task = null;
        $hasMapIndex = false;
        $hasOutput = false;
        $dryRun = false;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--dry-run') {
                $dryRun = true;
            } elseif (str_starts_with($token, '--task=')) {
                $task = substr($token, strlen('--task='));
            } elseif ($token === '--task') {
                $task = $tokens[++$index] ?? '';
            } elseif ($token === '--map-index' || str_starts_with($token, '--map-index=')) {
                $hasMapIndex = true;
            } elseif ($token === '--output-dir' || str_starts_with($token, '--output-dir=')) {
                $hasOutput = true;
            }
        }

        $task = trim((string) $task);
        if ($task === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $task) !== 1 || str_contains($task, '..')) {
            throw new InvalidArgumentException('edit refactor requires a valid explicit --task ID.');
        }

        $layout = new ProjectLayout($this->projectRoot);
        if (!$hasMapIndex) {
            $tokens[] = '--map-index=' . $layout->mapIndex();
        }
        if (!$hasOutput) {
            $tokens[] = '--output-dir=' . $layout->editBundle($task);
        }

        return [$tokens, $dryRun];
    }

    /** Prints the supported governed refactor CLI contract. */
    private function help(): int
    {
        echo <<<'TXT'
Usage:
  agent-loop edit refactor PLAN [options]

Consumes one safe versioned agent-map refactor plan through agent-loop's governance boundary; plan
validation, the transactional apply and the receipt are executed by voku/agent-edit
(`agent-edit capabilities` lists the supported contracts; unknown plan types or contract versions are
rejected, as are arbitrary edit plans and Rector execution).

Options:
  --task ID            Required governed task ID.
  --map-index PATH     Current agent-map JSON/TOON. Default: the agent-map project index.
  --map-root PATH      Runtime source root for hash/currentness checks. Default: project root.
  --output-dir PATH    Evidence bundle. Default: .agent-loop/edit/<task-id>
  --dry-run            Validate the complete plan and current source without mutation.

Mutation requires the task's current execution contract to be ready and runs under the shared project
mutation lock. Every source hash, inclusive byte range, expected token and plan provenance is
revalidated before publication; all rewritten PHP is staged and syntax-checked, and every source is
restored on any publication failure.

TXT;

        return 0;
    }
}
