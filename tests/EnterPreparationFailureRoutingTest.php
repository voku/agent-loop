<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;

/**
 * A deterministic preparation refusal must not name `enter` as its own repair.
 *
 * Phase F4 measured this on a clean consumer: an approved Contract selecting an
 * operating prompt the declared manifest cannot resolve made `enter` refuse and
 * then report next_action `agent-loop enter <task>` with kind=command, unchanged
 * across four consecutive iterations. The owning package had already said
 * exactly what was wrong, but agent-loop discarded that text, so the only
 * machine-readable message was an exit code shared by every possible cause.
 */
final class EnterPreparationFailureRoutingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-prep-failure-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Consumer;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return 'Hello ' . \$name;\n    }\n}\n",
        );
        file_put_contents($this->root . '/prompts.json', json_encode([
            'schema_version' => '1.0',
            'prompts' => [[
                'id' => 'scoped-review',
                'level' => 2,
                'template' => 'Review the change with at least {{minimum_failure_modes}} failure modes.',
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAnUnresolvablePromptSelectionDoesNotMakeEnterNameItself(): void
    {
        $this->approveContractSelecting('{"id":"no-such-prompt","arguments":{}}');

        $payload = $this->enter();
        $action = $payload['manifest']['next_action'];

        self::assertNotSame(
            'agent-loop enter PREP-001',
            $action,
            'enter named the command that had just refused, so a host obeying it loops forever',
        );
        self::assertSame('host_work', $payload['manifest']['next_action_kind']);
    }

    public function testTheRefusalRepeatsTheSameActionOnlyBecauseItIsIrreducible(): void
    {
        $this->approveContractSelecting('{"id":"no-such-prompt","arguments":{}}');

        $first = $this->enter()['manifest'];
        $second = $this->enter()['manifest'];

        // Identical is correct here: the step is host work, not a command the
        // host can execute. What must never happen is a *command* kind that
        // reproduces its own refusal.
        self::assertSame($first['next_action'], $second['next_action']);
        self::assertNotSame('command', $second['next_action_kind']);
    }

    public function testTheOwnersOwnCauseSurvivesIntoTheMachineReadableSurface(): void
    {
        $this->approveContractSelecting('{"id":"no-such-prompt","arguments":{}}');

        $payload = $this->enter();

        self::assertStringContainsString('unknown operating prompt id: no-such-prompt', $payload['manifest']['next_action']);
        self::assertSame('enter.preparation_failed', $payload['blockers'][0]['code']);
        self::assertStringContainsString('unknown operating prompt id: no-such-prompt', $payload['blockers'][0]['message']);
    }

    public function testTwoDifferentCausesNoLongerProduceTheSameMessage(): void
    {
        $this->approveContractSelecting('{"id":"no-such-prompt","arguments":{}}');
        $unknownId = $this->enter()['blockers'][0]['message'];

        $this->removeDirectory($this->root . '/.agent-loop');
        $this->approveContractSelecting('{"id":"scoped-review","arguments":{}}');
        $missingArgument = $this->enter()['blockers'][0]['message'];

        self::assertStringContainsString('missing arguments: minimum_failure_modes', $missingArgument);
        self::assertNotSame(
            $unknownId,
            $missingArgument,
            'both causes collapsed into one exit-code message, so the host cannot tell them apart',
        );
    }

    private function approveContractSelecting(string $selection): void
    {
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'PREP-001', '--by', 'lars',
            '--file', 'src/Greeter.php', '--goal', 'Punctuate.',
            '--validation', 'php -l src/Greeter.php',
            '--operating-prompt-manifest', $this->root . '/prompts.json',
            '--operating-prompt', $selection,
        ]), 'plan');
        self::assertSame(0, $this->dispatch(['agent-loop', 'map', 'build', '--paths=src']), 'map build');
        self::assertSame(0, $this->dispatch(['agent-loop', 'workflow', 'approve', 'PREP-001', '--by', 'lars']), 'approve');
    }

    /** @return array<string, mixed> */
    private function enter(): array
    {
        $dispatcher = new Dispatcher($this->root);
        $runner = static function (array $rest) use ($dispatcher): int {
            /** @var list<string> $argv */
            $argv = ['agent-loop', 'recall', ...array_values($rest)];

            return $dispatcher->run($argv);
        };

        ob_start();
        (new HostFrontDoorCommand($this->root, $runner))->run('enter', ['PREP-001', '--format=json']);
        $output = (string) ob_get_clean();

        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        $current = getcwd();
        self::assertIsString($current);
        chdir($this->root);

        try {
            ob_start();

            return (new Dispatcher($this->root))->run($argv);
        } finally {
            ob_end_clean();
            chdir($current);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
