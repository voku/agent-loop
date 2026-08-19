<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use ReflectionClass;
use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentRecallCompiler\RecallCompiler;
use voku\AgentSession\SessionHandoffProjector;
use voku\AgentSession\SessionStore;

/**
 * Compile a self-contained durable task-handoff prompt from current owner state.
 *
 * Session remains pruneable working memory. This command only projects its
 * bounded handoff packet into derived Recall input; the generated L2 prompt
 * instructs the acting host to update the repository's existing durable task
 * or card through its owner instead of inventing another backlog format.
 */
final readonly class WorkflowHandoffCommand
{
    private Closure $recallRunner;

    private SessionStore $sessionStore;

    private SessionHandoffProjector $handoffProjector;

    /**
     * @param callable(list<string>): int $recallRunner
     */
    public function __construct(
        private string $rootPath,
        callable $recallRunner,
        ?SessionStore $sessionStore = null,
        ?SessionHandoffProjector $handoffProjector = null,
        private ?string $operatingPromptManifest = null,
    ) {
        $this->recallRunner = Closure::fromCallable($recallRunner);
        $this->sessionStore = $sessionStore ?? new SessionStore();
        $this->handoffProjector = $handoffProjector ?? new SessionHandoffProjector();
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            if (count($args) !== 1) {
                throw new RuntimeException('Usage: workflow handoff <task-id>.');
            }

            $taskId = new WorkflowTaskId($args[0]);
            $layout = new ProjectLayout($this->rootPath);
            $session = $this->sessionStore->activeForTask($layout->sessionsRoot(), $taskId->value);
            if ($session === null) {
                throw new RuntimeException('No active governed Session exists for task ' . $taskId->value . '.');
            }

            $handoff = $this->handoffProjector->project($session);
            $contract = (new TaskContractStore($this->rootPath))->find($taskId->value);
            $contractEvidence = $contract === null
                ? "No durable Contract was found for this task. Do not invent one."
                : json_encode(
                    $contract->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );

            $description = implode("\n\n", [
                'Prepare a self-contained durable handoff for task ' . $taskId->value . '.',
                "Current durable Contract evidence:\n```json\n" . $contractEvidence . "\n```",
                "Current bounded Session handoff projection (derived working memory, not durable authority):\n" . $handoff->toMarkdown(),
            ]);

            $outputDirectory = $layout->recallRoot() . '/' . $taskId->value . '/handoff';
            $exit = ($this->recallRunner)([
                'compile',
                '--task', $taskId->value,
                '--description', $description,
                '--operating-prompt-manifest', $this->manifestPath(),
                '--operating-prompt', '{"id":"todo-card-handoff","arguments":{}}',
                '--output-dir', $outputDirectory,
            ]);
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

    private function manifestPath(): string
    {
        if ($this->operatingPromptManifest !== null) {
            return $this->operatingPromptManifest;
        }

        $source = (new ReflectionClass(RecallCompiler::class))->getFileName();
        if (!is_string($source) || $source === '') {
            throw new RuntimeException('Unable to resolve the installed agent-recall-compiler package path.');
        }

        $manifest = dirname($source, 2) . '/skills/agent-recall-consumer/operating-prompts.json';
        if (!is_file($manifest)) {
            throw new RuntimeException('Bundled todo-card-handoff manifest not found: ' . $manifest);
        }

        return $manifest;
    }
}
