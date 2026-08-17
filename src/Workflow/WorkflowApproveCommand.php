<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use ItpContext\Attribute\Rule;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLoop\Init\RepositoryActivation;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\RecallOutputRoot;

#[Rule(ArchitectureRules::TypedPackageApisInsideWorkflow)]
#[Rule(ArchitectureRules::EvidenceIsNotAuthority)]
final readonly class WorkflowApproveCommand
{
    /** @param callable(list<string>): int $recallRunner */
    public function __construct(private string $rootPath, private mixed $recallRunner)
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
            $preparer = new WorkflowRunPreparer($this->rootPath, $this->recallRunner);
            $mapReadiness = $preparer->discoveryReadiness($contract);
            $alreadyApproved = $contract->status === TaskContract::APPROVED;

            if (!$alreadyApproved) {
                $contract = $contracts->approve($taskId->value, $options['by']);
                echo "[OK] workflow approve: Contract revision {$contract->revision} approved for {$taskId->value}\n";

                $archive = (new WorkflowRecallOutputSuperseder($this->rootPath))->archiveIfPresent($taskId->value);
                if ($archive !== null) {
                    echo "[OK] workflow approve: superseded recall output archived at {$archive}\n";
                }
            } else {
                echo "[OK] workflow approve: current Contract revision was already approved; resuming Run preparation\n";
            }

            $result = $preparer->prepare($contract, $mapReadiness);
            echo "[OK] workflow approve: governed Run {$result->run->runId} prepared for Contract revision {$contract->revision}\n";
            echo "[OK] workflow approve: working Session {$result->session->id} attached to governed Run {$result->run->runId}\n";
            echo '[OK] workflow approve: governed Run bound to durable Learning root '
                . PathResolver::relativeTo($this->rootPath, $result->learningRoot) . "\n";
            echo "[OK] workflow approve: approved-state Run projection refreshed at {$result->preparedManifestPath}\n";

            if ($result->searchWarning !== null) {
                echo '[WARN] workflow approve: ' . $result->searchWarning . "\n";
            }
            if (!$result->recallCompiled()) {
                fwrite(
                    STDERR,
                    "[FAIL] workflow approve: Contract and governed Run remain resumable, but recall compilation failed. Rerun the same workflow approve command after fixing compiler input.\n",
                );

                return $result->recallExitCode;
            }

            $compiledManifestPath = $result->compiledManifestPath;
            if ($compiledManifestPath === null) {
                throw new RuntimeException('Recall compilation succeeded without a compiled-state Run projection.');
            }

            echo "[OK] workflow approve: Contract approved and governed Recall compiled for {$taskId->value}\n";
            echo "[OK] workflow approve: compiled-state Run projection refreshed at {$compiledManifestPath}\n";
            $this->printRecallHandoff($taskId->value);

            return 0;
        } catch (RuntimeException $exception) {
            fwrite(
                STDERR,
                '[FAIL] workflow approve: ' . $exception->getMessage()
                . "\n[ACTION REQUIRED] Inspect agent-loop workflow status {$taskId->value} --format=json and rerun workflow approve after repair.\n",
            );

            return 1;
        } catch (Throwable $exception) {
            fwrite(
                STDERR,
                '[FAIL] workflow approve: durable Contract may be approved, but Run preparation failed: '
                . $exception->getMessage()
                . "\n[ACTION REQUIRED] Inspect agent-loop workflow status {$taskId->value} --format=json and rerun workflow approve after repair.\n",
            );

            return 1;
        }
    }

    private function printRecallHandoff(string $taskId): void
    {
        $cli = (new RepositoryActivation($this->rootPath))->cliPath();
        $systemPath = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/system.md';
        $displaySystemPath = RecallOutputRoot::relativeTo($this->rootPath, $systemPath);

        echo "[NEXT] {$cli} workflow context {$taskId} --max-lines 120 --max-bytes 12000\n";
        echo "[NEXT] Read {$displaySystemPath} before planning or modifying code.\n";
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
