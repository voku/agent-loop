<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;

/**
 * Obeying the canonical next action must make progress.
 *
 * A live host dogfood found the opposite: with existing PHP scope and no map
 * snapshot, approve refused deterministically while next_action kept naming
 * approve, so a host following the kernel's own instruction looped forever and
 * could only escape by reading prose or holding a private "map missing means
 * build the map" rule.
 */
final class CanonicalNextActionConvergenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-convergence-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Consumer;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return 'Hello ' . \$name;\n    }\n}\n",
        );
        $this->plannedContract();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testNextActionNamesDiscoveryRepairInsteadOfAnApproveThatRefuses(): void
    {
        $next = $this->nextAction();

        self::assertStringNotContainsString('workflow approve', $next);
        self::assertStringContainsString('map build', $next);
    }

    public function testObeyingNextActionConvergesInsteadOfRepeatingTheSameAction(): void
    {
        $first = $this->nextAction();
        self::assertSame(0, $this->dispatchAction($first), 'the canonical action must be executable');

        $second = $this->nextAction();
        self::assertNotSame(
            $first,
            $second,
            'obeying next_action returned the same action again, so the host cannot make progress',
        );
        self::assertStringContainsString('workflow approve', $second);
    }

    public function testAStaleMapReportsRefreshRatherThanRepeatingApprove(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'map', 'build', '--paths=src']));
        file_put_contents($this->root . '/src/Greeter.php', "\n// changed after the map was built\n", FILE_APPEND);

        $next = $this->nextAction();

        self::assertStringNotContainsString('workflow approve', $next);
        self::assertStringContainsString('map refresh', $next);
    }

    public function testAFailingValidationAsksForHostWorkInsteadOfRepeatingFinish(): void
    {
        $this->reachMutationReady();
        $this->breakDeclaredValidation();

        [$action, $kind] = $this->nextStep();

        self::assertSame('host_work', $kind, 'the required step is model work, not a command');
        self::assertStringNotContainsString('agent-loop', $action, 'a host action must not read as a command');
        self::assertStringContainsString('validation', $action);
    }

    public function testTheHostWorkActionAdvancesOnceTheHostDoesIt(): void
    {
        $this->reachMutationReady();
        $this->breakDeclaredValidation();
        self::assertSame('host_work', $this->nextStep()[1]);

        // The host does the irreducible work the action described.
        $this->repairDeclaredValidation();

        [$action, $kind] = $this->nextStep();
        self::assertSame('command', $kind);
        self::assertStringContainsString('agent-loop finish', $action);
    }

    private function frontDoor(string $command): int
    {
        $dispatcher = new Dispatcher($this->root);
        $runner = static function (array $rest) use ($dispatcher): int {
            /** @var list<string> $argv */
            $argv = ['agent-loop', 'recall', ...array_values($rest)];

            return $dispatcher->run($argv);
        };

        ob_start();
        $exit = (new HostFrontDoorCommand($this->root, $runner))->run($command, ['CONV-001']);
        ob_end_clean();

        return $exit;
    }

    /** @return array{0: string, 1: string} */
    private function nextStep(): array
    {
        ob_start();
        $this->dispatchInRoot(['agent-loop', 'workflow', 'status', 'CONV-001', '--format=json']);
        $output = (string) ob_get_clean();
        $status = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        $action = $status['manifest']['next_action'] ?? null;
        self::assertIsString($action);
        // A manifest without the field is the old command-only contract, so it
        // reads as 'command' here. That keeps these tests failing on the
        // behaviour they describe rather than on the field's absence.
        $kind = $status['manifest']['next_action_kind'] ?? 'command';
        self::assertIsString($kind);

        return [$action, $kind];
    }

    private function reachMutationReady(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'map', 'build', '--paths=src']), 'map build');
        self::assertSame(0, $this->dispatch(['agent-loop', 'workflow', 'approve', 'CONV-001', '--by', 'lars']), 'approve');
        // enter/finish are front-door commands wired in bin/agent-loop rather
        // than the Dispatcher, so the owner is invoked directly here. Its exit
        // code is 0 only once mutation is authorized, which these tests need.
        self::assertSame(0, $this->frontDoor('enter'), 'enter');
    }

    /**
     * Reproduce the observed loop: break the implementation, then run finish so
     * the declared obligation actually executes and records failed evidence.
     * That is the state where finish used to name itself forever.
     */
    private function breakDeclaredValidation(): void
    {
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nthis is not valid php\n");
        self::assertNotSame(0, $this->frontDoor('finish'), 'finish must refuse on failing validation');
    }

    private function repairDeclaredValidation(): void
    {
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Consumer;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return 'Hello ' . \$name . '!';\n    }\n}\n",
        );
    }

    private function nextAction(): string
    {
        ob_start();
        $this->dispatchInRoot(['agent-loop', 'workflow', 'status', 'CONV-001', '--format=json']);
        $output = (string) ob_get_clean();
        $status = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($status);

        $next = $status['manifest']['next_action'] ?? null;
        self::assertIsString($next);

        return $next;
    }

    private function dispatchAction(string $action): int
    {
        $argv = preg_split('/\s+/', trim($action)) ?: [];
        self::assertNotSame([], $argv);

        return $this->dispatch(['agent-loop', ...array_slice($argv, 1)]);
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        ob_start();
        $exit = $this->dispatchInRoot($argv);
        ob_end_clean();

        return $exit;
    }

    /** @param list<string> $argv */
    private function dispatchInRoot(array $argv): int
    {
        return (new Dispatcher($this->root))->run($argv);
    }

    private function plannedContract(): void
    {
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'CONV-001',
            '--by', 'lars',
            '--file', 'src/Greeter.php',
            '--goal', 'Keep the canonical next action executable.',
            '--validation', 'php -l src/Greeter.php',
        ]));
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
