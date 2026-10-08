<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class WorkflowPlanChainedValidationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function chainedValidations(): array
    {
        return [
            'semicolon' => ['make lint; make test'],
            'and-and' => ['composer validate && composer test'],
            'or-or' => ['make a || make b'],
            'chain after a quoted part' => ['php -r \'echo 1;\' && make test'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function singleCommands(): array
    {
        return [
            'plain command' => ['vendor/bin/phpunit tests/FooTest.php'],
            'semicolon inside single quotes' => ['php -r \'echo 1; echo 2;\''],
            'semicolon inside double quotes' => ['php -r "echo 1; echo 2;"'],
            'pipe is not a chain' => ['rg -n foo src | head -3'],
            'escaped semicolon in find' => ['find . -name x -exec echo {} \;'],
        ];
    }

    #[DataProvider('chainedValidations')]
    public function testChainedValidationProducesAWarningThatNamesTheEntry(string $validation): void
    {
        $output = $this->plan($validation, 'json');

        self::assertCount(1, $output['warnings']);
        self::assertStringContainsString('validation #1 chains several commands', $output['warnings'][0]);
        self::assertStringContainsString($validation, $output['warnings'][0]);
        self::assertSame('decision_required', $output['next_action_kind'], 'the warning must not block the plan');
    }

    #[DataProvider('singleCommands')]
    public function testSingleCommandsProduceNoWarning(string $validation): void
    {
        self::assertSame([], $this->plan($validation, 'json')['warnings']);
    }

    public function testTextOutputPrintsAWarnLineForTheChainedEntryOnly(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-chain-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'CHAIN-2', '--by', 'lars', '--file', 'src/Foo.php', '--goal', 'g',
                '--validation', 'make ok', '--validation', 'make a && make b',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertStringContainsString('[WARN] workflow plan: validation #2 chains several commands', $output);
            self::assertStringNotContainsString('validation #1 chains', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $validation, string $format): array
    {
        $root = sys_get_temp_dir() . '/agent-loop-chain-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'CHAIN-1', '--by', 'lars', '--file', 'src/Foo.php', '--goal', 'g', '--validation', $validation, '--format', $format,
            ]);
            $output = (string) ob_get_clean();
            self::assertSame(0, $exit);

            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
