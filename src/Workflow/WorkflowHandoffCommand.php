<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use ReflectionClass;
use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentRecallCompiler\RecallCompiler;
use voku\AgentSession\SessionStore;

/**
 * Compile a self-contained durable task-handoff prompt from current owner state.
 *
 * Current chat/session prose is supplied explicitly as bounded candidate context;
 * it is not durable authority. The current governed Run binds that context to an
 * exact Session identity, while Contract and board facts come from their owners.
 */
final readonly class WorkflowHandoffCommand
{
    private Closure $recallRunner;
    private SessionStore $sessionStore;

    /** @param callable(list<string>): int $recallRunner */
    public function __construct(
        private string $rootPath,
        callable $recallRunner,
        ?SessionStore $sessionStore = null,
        private ?string $operatingPromptManifest = null,
    ) {
        $this->recallRunner = Closure::fromCallable($recallRunner);
        $this->sessionStore = $sessionStore ?? new SessionStore();
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $context = $this->context(array_slice($args, 1));
            $layout = new ProjectLayout($this->rootPath);

            $run = (new GovernedRunStore($this->rootPath))->find($taskId->value);
            if ($run === null) {
                throw new RuntimeException('No governed Run exists for task ' . $taskId->value . '. Run `agent-loop enter ' . $taskId->value . '` first.');
            }
            $session = $this->sessionStore->load($layout->sessionsRoot(), $run->sessionId);
            if ($session->taskId !== $taskId->value) {
                throw new RuntimeException('Governed Run Session task does not match handoff task ' . $taskId->value . '.');
            }

            $contract = (new TaskContractStore($this->rootPath))->find($taskId->value);
            $contractEvidence = $contract === null
                ? 'No durable Contract was found for this task. Do not invent one.'
                : json_encode(
                    $contract->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            $sessionEvidence = json_encode(
                $session->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            $descriptionParts = [
                'Prepare a self-contained durable handoff for task ' . $taskId->value . '.',
                "Current-session handoff notes supplied explicitly by the acting host. Treat these as candidate context, not durable authority; re-ground every material claim against repository/owner evidence before persisting it:\n" . $context,
                "Current governed Session identity/metadata:\n```json\n" . $sessionEvidence . "\n```",
            ];
            if ($contract === null) {
                $descriptionParts[] = 'Current durable Contract evidence: ' . $contractEvidence;
            } else {
                $descriptionParts[] = "Current durable Contract evidence:\n```json\n" . $contractEvidence . "\n```";
            }

            $outputDirectory = $layout->recallRoot() . '/' . $taskId->value . '/handoff';
            $recallArgs = [
                'compile',
                '--task', $taskId->value,
                '--description', implode("\n\n", $descriptionParts),
                '--operating-prompt-manifest', $this->manifestPath(),
                '--operating-prompt', '{"id":"todo-card-handoff","arguments":{}}',
                '--output-dir', $outputDirectory,
            ];

            $kanbanContext = (new WorkflowKanbanContextWriter($this->rootPath))->write($taskId->value, $session);
            if ($kanbanContext !== null) {
                $recallArgs[] = '--kanban-context';
                $recallArgs[] = $kanbanContext;
            }

            $exit = ($this->recallRunner)($recallArgs);
            if ($exit !== 0) {
                return $exit;
            }

            echo "\n[HANDOFF] Compiled self-contained task handoff prompt: " . $layout->display($outputDirectory . '/system.md') . "\n";
            echo "[NEXT] Give that prompt to the acting agent and update the existing durable task/card through its owner; do not copy the whole chat or create a duplicate task.\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow handoff: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @param list<string> $tokens */
    private function context(array $tokens): string
    {
        if (count($tokens) !== 2 || !in_array($tokens[0], ['--context', '--context-file'], true)) {
            throw new RuntimeException('Usage: workflow handoff <task-id> (--context <text> | --context-file <path>).');
        }

        if ($tokens[0] === '--context') {
            $context = trim($tokens[1]);
            if ($context === '') {
                throw new RuntimeException('workflow handoff --context requires non-empty bounded session notes.');
            }

            return $context;
        }

        $path = trim($tokens[1]);
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('workflow handoff --context-file must name a readable file.');
        }
        $context = file_get_contents($path);
        if (!is_string($context) || trim($context) === '') {
            throw new RuntimeException('workflow handoff --context-file is empty or unreadable: ' . $path);
        }

        return trim($context);
    }

    private function manifestPath(): string
    {
        if ($this->operatingPromptManifest !== null) {
            return $this->operatingPromptManifest;
        }

        $source = (new ReflectionClass(RecallCompiler::class))->getFileName();
        if (!is_string($source)) {
            throw new RuntimeException('Unable to resolve the installed agent-recall-compiler package path.');
        }

        $manifest = dirname($source, 2) . '/skills/agent-recall-consumer/operating-prompts.json';
        if (!is_file($manifest)) {
            throw new RuntimeException('Bundled todo-card-handoff manifest not found: ' . $manifest);
        }

        return $manifest;
    }
}
