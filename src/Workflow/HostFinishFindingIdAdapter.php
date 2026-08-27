<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunPolicyEvaluator;
use voku\AgentSession\SessionStore;

/**
 * Bridges existing durable Finding ids into the host-facing finish command.
 *
 * The ordinary finish command still owns deterministic validation, review
 * acknowledgement and close. This adapter only fills the one missing bridge:
 * reusing Findings that already exist in agent-learning.
 */
final readonly class HostFinishFindingIdAdapter
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function supports(array $args): bool
    {
        return $this->hasOption(array_slice($args, 1), 'finding');
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $taskId = new WorkflowTaskId($args[0] ?? '');
        $tokens = array_slice($args, 1);
        $format = OptionTokens::value($tokens, 'format') ?? 'text';
        $learning = OptionTokens::value($tokens, 'learning');
        $by = OptionTokens::value($tokens, 'by');
        $reason = OptionTokens::value($tokens, 'learning-reason');
        $followUpRef = OptionTokens::value($tokens, 'follow-up-ref');
        $findingIds = OptionTokens::values($tokens, 'finding');

        try {
            if ($learning !== 'findings_recorded') {
                throw new InvalidArgumentException('--finding is only valid with --learning findings_recorded.');
            }
            if ($findingIds === []) {
                throw new InvalidArgumentException('--finding requires at least one non-empty Finding id.');
            }
            if ($this->hasInlineFindingInput($tokens)) {
                throw new InvalidArgumentException(
                    '--finding cannot be combined with inline --finding-observation/--finding-hypothesis/--finding-conclusion/--finding-confidence/--finding-sensitivity input.',
                );
            }
            if ($by === null || $reason === null) {
                throw new InvalidArgumentException('--learning requires --by <actor> and --learning-reason <text>.');
            }

            $command = new HostFrontDoorCommand($this->rootPath);
            $preparation = $this->runQuietly($command, $this->withoutLearningInput($args));
            $manifest = (new RunManifestProjector($this->rootPath))->project($taskId->value);
            if (!in_array($manifest->references['review']['state'] ?? null, ['ok', 'warn'], true)) {
                echo $preparation['stdout'];

                return $preparation['exit'];
            }

            $contract = (new TaskContractStore($this->rootPath))->load($taskId->value);
            $run = (new GovernedRunStore($this->rootPath))->find($taskId->value)
                ?? throw new InvalidArgumentException('agent-loop finish requires a governed Run.');
            if ($contract->status !== TaskContract::APPROVED || $contract->revision !== $run->contractRevision) {
                throw new InvalidArgumentException('agent-loop finish requires the governed Run to match the current approved Contract.');
            }

            $session = (new SessionStore())->load((new ProjectLayout($this->rootPath))->sessionsRoot(), $run->sessionId);
            if ($session->taskId !== $taskId->value) {
                throw new InvalidArgumentException('Governed Run Session belongs to another task.');
            }

            (new WorkflowLearningRecorder($this->rootPath))->record(
                run: $run,
                contract: $contract,
                session: $session,
                decisionValue: 'findings_recorded',
                decidedBy: $by,
                reason: $reason,
                followUpRef: $followUpRef,
                findingIds: $findingIds,
            );

            return $command->run('finish', $this->finalArgs($taskId->value, $format));
        } catch (Throwable $exception) {
            return $this->refuse($taskId->value, $format, $exception->getMessage());
        }
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string}
     */
    private function runQuietly(HostFrontDoorCommand $command, array $args): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $command->run('finish', $args);
            $stdout = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return ['exit' => $exit, 'stdout' => $stdout];
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function withoutLearningInput(array $args): array
    {
        $result = [];
        $removed = ['learning', 'learning-reason', 'finding', 'follow-up-ref'];
        for ($index = 0, $count = count($args); $index < $count; ++$index) {
            $token = $args[$index];
            if (!str_starts_with($token, '--')) {
                $result[] = $token;
                continue;
            }

            $name = strtok(substr($token, 2), '=');
            if (!is_string($name) || !in_array($name, $removed, true)) {
                $result[] = $token;
                continue;
            }
            if (!str_contains($token, '=')) {
                ++$index;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function finalArgs(string $taskId, string $format): array
    {
        return $format === 'json' ? [$taskId, '--format', 'json'] : [$taskId];
    }

    private function refuse(string $taskId, string $format, string $message): int
    {
        if ($format !== 'json') {
            fwrite(STDERR, '[FAIL] finish: ' . $message . "\n");

            return 1;
        }

        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId);
        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
        $error = [
            'code' => 'finish.closeout_failed',
            'owner' => 'agent-loop',
            'message' => $message,
        ];
        echo json_encode([
            'schema_version' => '1.0',
            'command' => 'finish',
            'task_id' => $taskId,
            'complete' => false,
            'mutation_status' => 'refused',
            'error' => $error,
            'blockers' => [$error],
            'next_action' => $policy->nextAction,
            'next_action_kind' => $policy->nextActionKind,
            'manifest' => $manifest->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        return 1;
    }

    /** @param list<string> $tokens */
    private function hasInlineFindingInput(array $tokens): bool
    {
        $names = [
            'finding-observation',
            'finding-hypothesis',
            'finding-conclusion',
            'finding-confidence',
            'finding-sensitivity',
        ];
        foreach ($names as $name) {
            if ($this->hasOption($tokens, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tokens */
    private function hasOption(array $tokens, string $name): bool
    {
        $exact = '--' . $name;
        $prefix = $exact . '=';
        foreach ($tokens as $token) {
            if ($token === $exact || str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
