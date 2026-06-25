<?php

declare(strict_types=1);

namespace voku\AgentLoop\Verification;

use Throwable;
use voku\AgentKanban\TodoBoardCli;
use voku\AgentKanban\TodoBoardVerifier;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentLoop\MemoryPromotionAnalyzer;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentSession\Cli as SessionCli;

/**
 * Workflow-level wiring check for the agentic-coding loop.
 *
 * Unlike `agent-loop verify` (AgentLoopVerifier; deep cross-package
 * consistency: tasks, board content, session/recall linkage with staleness
 * detection, learning root content), this only answers a narrower question:
 * is the command surface `agent-loop` coordinates actually wired up and
 * callable? It never inspects board/session/recall/learning *content*, never
 * mutates a file, and never delegates to a command that has side effects --
 * every check below only resolves a class and, where safe, constructs it.
 */
final class WorkflowVerifier
{
    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    /**
     * @param list<string> $tokens tokens after the `workflow:verify` namespace
     */
    public function run(array $tokens): int
    {
        if (array_intersect($tokens, ['help', '--help', '-h']) !== []) {
            echo $this->usage();

            return 0;
        }

        echo "agent-loop workflow:verify - workflow wiring check\n\n";

        $result = $this->verify();

        foreach ($result->messages() as $message) {
            echo $message->render() . "\n";
        }

        echo "\n" . ($result->hasFailures()
            ? "[FAIL] agent-loop workflow:verify: required workflow wiring is broken, see above.\n"
            : "[OK] agent-loop workflow:verify: workflow wiring looks intact.\n");

        return $result->exitCode();
    }

    public function verify(): VerificationResult
    {
        return new VerificationResult([
            $this->checkBoardCommandWiring(),
            $this->checkBoardVerifierAvailability(),
            $this->checkSessionCommandWiring(),
            $this->checkRecallCommandWiring(),
            $this->checkLearnCommandWiring(),
            $this->checkMemoryCommandWiring(),
            $this->checkReadmeDocumentsWorkflowVerify(),
        ]);
    }

    /**
     * Mirrors the `board` case in Dispatcher::run(): the same class the
     * Dispatcher constructs to delegate `board ...`. Construction performs no
     * I/O (TodoBoardCli resolves its project prefix and card directory lazily,
     * only once a command actually runs), so this proves the command is
     * wired without running board verification itself.
     */
    private function checkBoardCommandWiring(): VerificationMessage
    {
        if (!class_exists(TodoBoardCli::class)) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'board', 'voku/agent-kanban TodoBoardCli is not installed; the board command cannot be wired');
        }

        try {
            new TodoBoardCli($this->rootPath);
        } catch (Throwable $exception) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'board', 'board command failed to wire: ' . $exception->getMessage());
        }

        return new VerificationMessage(VerificationSeverity::OK, 'board', 'board command is wired');
    }

    /**
     * Confirms the same TodoBoardVerifier class `board:verify` delegates to
     * is installed and constructible, without running it (running it -- and
     * judging its result -- is `board:verify`'s and `agent-loop verify`'s
     * job, not this command's).
     */
    private function checkBoardVerifierAvailability(): VerificationMessage
    {
        if (!class_exists(TodoBoardVerifier::class)) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'board', 'voku/agent-kanban TodoBoardVerifier is not installed; board:verify cannot run');
        }

        try {
            new TodoBoardVerifier($this->rootPath);
        } catch (Throwable $exception) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'board', 'board verifier failed to construct: ' . $exception->getMessage());
        }

        return new VerificationMessage(VerificationSeverity::OK, 'board', 'board verifier is available');
    }

    private function checkSessionCommandWiring(): VerificationMessage
    {
        if (!class_exists(SessionCli::class)) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'session', 'voku/agent-session Cli is not installed; the session command cannot be wired');
        }

        try {
            new SessionCli();
        } catch (Throwable $exception) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'session', 'session command failed to wire: ' . $exception->getMessage());
        }

        return new VerificationMessage(VerificationSeverity::OK, 'session', 'session command is wired');
    }

    private function checkRecallCommandWiring(): VerificationMessage
    {
        if (!class_exists(RecallCli::class)) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'recall', 'voku/agent-recall-compiler Cli is not installed; the recall command cannot be wired');
        }

        try {
            new RecallCli();
        } catch (Throwable $exception) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'recall', 'recall command failed to wire: ' . $exception->getMessage());
        }

        return new VerificationMessage(VerificationSeverity::OK, 'recall', 'recall command is wired');
    }

    private function checkLearnCommandWiring(): VerificationMessage
    {
        if (!class_exists(LearningCli::class)) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'learn', 'voku/agent-learning Cli is not installed; the learn command cannot be wired');
        }

        try {
            new LearningCli();
        } catch (Throwable $exception) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'learn', 'learn command failed to wire: ' . $exception->getMessage());
        }

        return new VerificationMessage(VerificationSeverity::OK, 'learn', 'learn command is wired');
    }

    private function checkMemoryCommandWiring(): VerificationMessage
    {
        if (!class_exists(MemoryPromotionAnalyzer::class)) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'memory', 'voku/agent-loop MemoryPromotionAnalyzer is missing; the memory command cannot be wired');
        }

        try {
            $analyzer = new MemoryPromotionAnalyzer($this->rootPath);
        } catch (Throwable $exception) {
            return new VerificationMessage(VerificationSeverity::FAIL, 'memory', 'memory command failed to wire: ' . $exception->getMessage());
        }

        return new VerificationMessage(VerificationSeverity::OK, 'memory', 'memory review command is wired');
    }

    /**
     * Lightweight documentation sanity check, not prose parsing: it only
     * looks for the literal token `workflow:verify` somewhere in README.md.
     * A miss is a WARN, never a FAIL -- missing documentation is not a
     * broken workflow contract.
     */
    private function checkReadmeDocumentsWorkflowVerify(): VerificationMessage
    {
        $readmeFile = rtrim($this->rootPath, '/') . '/README.md';
        if (!is_file($readmeFile)) {
            return new VerificationMessage(VerificationSeverity::SKIP, 'docs', "no README.md found at {$readmeFile}");
        }

        $content = (string) file_get_contents($readmeFile);
        if (str_contains($content, 'workflow:verify')) {
            return new VerificationMessage(VerificationSeverity::OK, 'docs', 'README documents workflow:verify');
        }

        return new VerificationMessage(VerificationSeverity::WARN, 'docs', 'README does not yet document workflow:verify');
    }

    private function usage(): string
    {
        return <<<TXT
        agent-loop workflow:verify - workflow wiring check.

        Usage:
          agent-loop workflow:verify [options]

        Checks (each emits exactly one [OK]/[WARN]/[SKIP]/[FAIL] line):
          - board:   the board command and the board-only verifier both
                     resolve to an installed voku/agent-kanban class
          - session: the session command resolves to an installed
                     voku/agent-session class
          - recall:  the recall command resolves to an installed
                     voku/agent-recall-compiler class
          - learn:   the learn command resolves to an installed
                     voku/agent-learning class
          - memory:  the memory review command resolves to this package's
                     own MemoryPromotionAnalyzer
          - docs:    README.md mentions "workflow:verify" (informational
                     only; never fails the command)

        This is a wiring check, not a content check: it does not inspect
        board/session/recall/learning state, does not approve anything, and
        does not replace `agent-loop verify` or `agent-loop board:verify`.

        TXT;
    }
}
