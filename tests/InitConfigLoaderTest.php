<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitConfigLoader;

final class InitConfigLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $directory) {
            $this->removeDirectory($directory);
        }
        $this->tempDirs = [];
    }

    public function testHumanExplanationsDefaultToAsk(): void
    {
        $root = $this->tempDir();

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('ask', $config['interaction']['human_explanations']);
        self::assertSame([], $config['warnings']);
    }

    public function testFutureWorkDefaultsToFocus(): void
    {
        $root = $this->tempDir();

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame([
            'mode' => 'focus',
            'max_follow_up_slices' => 1,
        ], $config['workflow']['future_work']);
        self::assertSame([], $config['warnings']);
    }

    public function testFutureWorkAcceptsDiscoverAndInvestPolicies(): void
    {
        foreach ([['discover', 1], ['invest', 3]] as [$mode, $maximum]) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode([
                'workflow' => [
                    'future_work' => [
                        'mode' => $mode,
                        'max_follow_up_slices' => $maximum,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame($mode, $config['workflow']['future_work']['mode']);
            self::assertSame($maximum, $config['workflow']['future_work']['max_follow_up_slices']);
            self::assertSame([], $config['warnings']);
        }
    }

    public function testInvalidFutureWorkPolicyWarnsAndKeepsConservativeDefaults(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'workflow' => [
                'future_work' => [
                    'mode' => 'wander',
                    'max_follow_up_slices' => 0,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame([
            'mode' => 'focus',
            'max_follow_up_slices' => 1,
        ], $config['workflow']['future_work']);
        self::assertContains(
            '[WARN] init config: workflow.future_work.mode must be focus, discover, or invest',
            $config['warnings'],
        );
        self::assertContains(
            '[WARN] init config: workflow.future_work.max_follow_up_slices must be an integer from 1 to 10',
            $config['warnings'],
        );
    }

    public function testInvalidFutureWorkObjectsWarnAndKeepFocus(): void
    {
        foreach ([
            ['workflow' => false],
            ['workflow' => ['future_work' => false]],
        ] as $value) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode($value, JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame('focus', $config['workflow']['future_work']['mode']);
            self::assertNotSame([], $config['warnings']);
        }
    }

    public function testEmptyInteractionObjectKeepsDefaultWithoutWarning(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => (object) [],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('ask', $config['interaction']['human_explanations']);
        self::assertSame([], $config['warnings']);
    }

    public function testHumanExplanationPolicyAcceptsAlwaysAndNever(): void
    {
        foreach (['always', 'never'] as $value) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode([
                'interaction' => ['human_explanations' => $value],
            ], JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame($value, $config['interaction']['human_explanations']);
            self::assertSame([], $config['warnings']);
        }
    }

    public function testInvalidHumanExplanationPolicyWarnsAndFallsBackToAsk(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => ['human_explanations' => 'surprise-me'],
        ], JSON_THROW_ON_ERROR));

        $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

        self::assertSame('ask', $config['interaction']['human_explanations']);
        self::assertContains(
            '[WARN] init config: interaction.human_explanations must be ask, always, or never',
            $config['warnings'],
        );
    }

    public function testInvalidInteractionObjectWarnsAndFallsBackToAsk(): void
    {
        foreach ([false, null, []] as $value) {
            $root = $this->tempDir();
            mkdir($root . '/.agent-loop', 0o775, true);
            file_put_contents($root . '/.agent-loop/init.json', json_encode([
                'interaction' => $value,
            ], JSON_THROW_ON_ERROR));

            $config = (new InitConfigLoader($root))->load('.agent-loop/init.json');

            self::assertSame('ask', $config['interaction']['human_explanations']);
            self::assertContains('[WARN] init config: interaction must be an object', $config['warnings']);
        }
    }

    private function tempDir(): string
    {
        $directory = sys_get_temp_dir() . '/agent-loop-init-config-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o775, true));
        $this->tempDirs[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
