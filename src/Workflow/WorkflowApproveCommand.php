<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use ItpContext\Attribute\Rule;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLoop\Init\RepositoryActivation;

#[Rule(ArchitectureRules::TypedPackageApisInsideWorkflow)]
#[Rule(ArchitectureRules::EvidenceIsNotAuthority)]
final readonly class WorkflowApproveCommand
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
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, '[FAIL] workflow approve: ' . $exception->getMessage() . "\n");

            return 1;
        }

        try {
            $contracts = new TaskContractStore($this->rootPath);
            $contract = $contracts->load($taskId->value);

            if ($contract->status !== TaskContract::APPROVED) {
                (new WorkflowRunPreparer($this->rootPath))->discoveryReadiness($contract);
                $contract = $contracts->approve($taskId->value, $options['by']);
                echo "[OK] workflow approve: Contract revision {$contract->revision} approved for {$taskId->value}\n";
            } else {
                echo "[OK] workflow approve: current Contract revision is already approved\n";
            }

            $cli = (new RepositoryActivation($this->rootPath))->cliPath();
            echo "[NEXT] {$cli} enter {$taskId->value}\n";

            return 0;
        } catch (RuntimeException $exception) {
            fwrite(
                STDERR,
                '[FAIL] workflow approve: ' . $exception->getMessage()
                . "\n[ACTION REQUIRED] Repair the reported approval prerequisite and rerun workflow approve.\n",
            );

            return 1;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow approve: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{by: string}
     */
    private function parse(array $tokens): array
    {
        $by = null;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token !== '--by') {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException('--by requires a value.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($token . ' requires a non-empty value.');
            }
            $by = $value;
        }
        if ($by === null) {
            throw new InvalidArgumentException('--by is required.');
        }

        return ['by' => $by];
    }
}
