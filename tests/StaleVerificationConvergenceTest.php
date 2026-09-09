<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;

/**
 * A stale verification receipt must converge instead of naming itself.
 *
 * Real `voku/agent-ui` dogfood (agent-loop#399) recorded exact-head evidence
 * for implementation A, amended the implementation to B, and then watched the
 * lifecycle project the correctly stale receipt while recommending the
 * read-only `workflow status` command, which can never change the fact it just
 * reported. Verification currentness is a Loop fact, so the convergence has to
 * come from Loop rather than from a consumer working around it.
 */
final class StaleVerificationConvergenceTest extends TestCase
{
    private const TASK = 'STALE-399';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-stale-verification-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        $this->writeImplementation('Hello ');
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'plan', self::TASK,
            '--by', 'lars',
            '--file', 'src/Greeter.php',
            '--goal', 'Keep verification evidence convergent after the implementation changes.',
            '--validation', 'php -l src/Greeter.php',
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAmendedImplementationConvergesThroughAFreshExactHeadReceipt(): void
    {
        $this->closeWithExactHeadEvidence();
        $receiptA = (new RunVerificationReceiptStore($this->root))->find(self::TASK);
        self::assertNotNull($receiptA);
        self::assertNotNull($receiptA->implementationSnapshot);

        // Implementation A -> B. The persisted receipt is now history.
        $this->writeImplementation('Hello, ');

        // The closed Session cannot record replacement evidence, so the stale
        // receipt stays the projected answer and policy routes through `enter`.
        $superseded = $this->reference('verification');
        self::assertNotSame('passed', $superseded['state'] ?? null, 'stale evidence must never read as passed');
        self::assertSame(
            $receiptA->implementationSnapshot,
            $superseded['superseded_receipt']['implementation_snapshot'] ?? null,
        );
        self::assertSame('agent-loop enter ' . self::TASK, $this->nextAction());

        self::assertSame(0, $this->frontDoor('enter'), 'enter must reopen a Session for the amended implementation');
        $this->frontDoor('finish');
        $this->frontDoor('finish', ['--reviewed-report-sha256', $this->reviewSha256(), '--by', 'lars']);

        // The exact state agent-loop#399 reported: review and Learning are
        // current for B while the verification receipt still describes A.
        $verification = $this->reference('verification');
        self::assertNotSame(
            'agent-loop workflow status ' . self::TASK . ' --format=json',
            $this->nextAction(),
            'a stale receipt must not route back to a read-only inspection command',
        );
        self::assertNotSame('passed', $verification['state'] ?? null, 'stale evidence must never read as passed');
        self::assertSame(
            $receiptA->implementationSnapshot,
            $verification['superseded_receipt']['implementation_snapshot'] ?? null,
            'the superseded receipt must remain visible as evidence',
        );
        self::assertStringContainsString(
            'different implementation snapshot',
            (string) ($verification['superseded_receipt']['reason'] ?? ''),
        );

        // Obeying the canonical action must reach a fresh exact-head receipt.
        self::assertSame(0, $this->obeyUntilComplete(), 'the canonical action must converge');

        $receiptB = (new RunVerificationReceiptStore($this->root))->find(self::TASK);
        self::assertNotNull($receiptB);
        self::assertNotSame(
            $receiptA->implementationSnapshot,
            $receiptB->implementationSnapshot,
            'a fresh receipt must describe the amended implementation',
        );
        self::assertSame('passed', $this->reference('verification')['state'] ?? null);
        self::assertSame('none', $this->nextAction());

        // Supersession history is preserved rather than rewritten.
        self::assertNotSame([], $receiptB->supersedes);
        self::assertSame(
            $receiptA->implementationSnapshot,
            $receiptB->supersedes[0]['implementation_snapshot'] ?? null,
        );
    }

    private function obeyUntilComplete(): int
    {
        $seen = [];
        for ($step = 0; $step < 6; ++$step) {
            $action = $this->nextAction();
            if ($action === 'none') {
                return 0;
            }
            self::assertArrayNotHasKey(
                $action,
                $seen,
                'obeying the canonical action repeated "' . $action . '" without changing the lifecycle',
            );
            $seen[$action] = true;
            if (str_contains($action, '--learning <')) {
                // A command template's placeholders are model-owned; filling
                // them is exactly what a host does when it obeys the action.
                $this->frontDoor('finish', [
                    '--learning', 'no_durable_learning',
                    '--learning-reason', 're-verifying the amended implementation',
                    '--by', 'lars',
                ]);
                continue;
            }
            if (str_contains($action, '--reviewed-report-sha256 ')) {
                $this->frontDoor('finish', ['--reviewed-report-sha256', $this->reviewSha256(), '--by', 'lars']);
                continue;
            }
            if (str_starts_with($action, 'agent-loop finish ')) {
                $this->frontDoor('finish');
                continue;
            }
            if (str_starts_with($action, 'agent-loop enter ')) {
                $this->frontDoor('enter');
                continue;
            }
            self::fail('unexpected canonical action for a stale verification receipt: ' . $action);
        }

        return 1;
    }

    private function closeWithExactHeadEvidence(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'map', 'build', '--paths=src']), 'map build');
        self::assertSame(0, $this->dispatch(['agent-loop', 'workflow', 'approve', self::TASK, '--by', 'lars']), 'approve');
        self::assertSame(0, $this->frontDoor('enter'), 'enter');
        $this->writeImplementation('Hello ', '!');
        $this->frontDoor('finish');
        $this->frontDoor('finish', ['--reviewed-report-sha256', $this->reviewSha256(), '--by', 'lars']);
        $this->frontDoor('finish', ['--learning', 'no_durable_learning', '--learning-reason', 'none', '--by', 'lars']);
        self::assertSame('passed', $this->reference('verification')['state'] ?? null, 'exact-head close must persist a receipt');
    }

    private function writeImplementation(string $greeting, string $suffix = ''): void
    {
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Consumer;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return '" . $greeting . "' . \$name . '" . $suffix . "';\n    }\n}\n",
        );
    }

    /** @return array<string, mixed> */
    private function reference(string $name): array
    {
        $reference = $this->statusPayload()['manifest']['references'][$name] ?? null;
        self::assertIsArray($reference);

        return $reference;
    }

    private function nextAction(): string
    {
        $action = $this->statusPayload()['manifest']['next_action'] ?? null;
        self::assertIsString($action);

        return $action;
    }

    private function reviewSha256(): string
    {
        $sha = $this->reference('review')['source']['sha256'] ?? null;
        self::assertIsString($sha);

        return $sha;
    }

    /** @return array<string, mixed> */
    private function statusPayload(): array
    {
        ob_start();
        (new Dispatcher($this->root))->run(['agent-loop', 'workflow', 'status', self::TASK, '--format=json']);
        $output = (string) ob_get_clean();
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param list<string> $rest */
    private function frontDoor(string $command, array $rest = []): int
    {
        $dispatcher = new Dispatcher($this->root);
        $runner = static function (array $args) use ($dispatcher): int {
            /** @var list<string> $argv */
            $argv = ['agent-loop', 'recall', ...array_values($args)];

            return $dispatcher->run($argv);
        };

        ob_start();
        $exit = (new HostFrontDoorCommand($this->root, $runner))->run($command, [self::TASK, ...$rest]);
        ob_end_clean();

        return $exit;
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        ob_start();
        $exit = (new Dispatcher($this->root))->run($argv);
        ob_end_clean();

        return $exit;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
