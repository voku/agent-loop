<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use JsonException;
use RuntimeException;

/** Routes refactor verification from persisted runner identity without weakening either verifier. */
final readonly class RefactorVerifyDispatchCommand
{
    public function __construct(private string $projectRoot)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if (in_array($tokens[0] ?? '', ['help', '--help', '-h'], true)) {
            return (new RefactorVerifyCommand($this->projectRoot))->run($tokens);
        }

        try {
            $bundle = $this->bundle($tokens);
            $raw = file_get_contents($bundle . '/execution.json');
            if (!is_string($raw)) {
                throw new RuntimeException('Unable to read refactor execution evidence.');
            }
            $execution = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($execution)) {
                throw new RuntimeException('Refactor execution evidence must decode to an object.');
            }
        } catch (JsonException|RuntimeException $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 2;
        }

        return match ($execution['runner']['name'] ?? null) {
            'method-removal-plan' => (new MethodRemovalVerifyCommand($this->projectRoot))->run($tokens),
            'property-removal-plan' => (new PropertyRemovalVerifyCommand($this->projectRoot))->run($tokens),
            'class-constant-removal-plan' => (new ClassConstantRemovalVerifyCommand($this->projectRoot))->run($tokens),
            default => (new RefactorVerifyCommand($this->projectRoot))->run($tokens),
        };
    }

    /** @param list<string> $tokens */
    private function bundle(array $tokens): string
    {
        $value = null;
        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (str_starts_with($token, '--bundle=')) {
                $value = substr($token, strlen('--bundle='));
                break;
            }
            if ($token === '--bundle') {
                $value = $tokens[$index + 1] ?? null;
                break;
            }
        }
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Refactor verify requires --bundle.');
        }

        $root = realpath($this->projectRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Project root not found: ' . $this->projectRoot);
        }
        $candidate = str_starts_with($value, '/') ? $value : $root . '/' . $value;
        $bundle = realpath($candidate);
        if (!is_string($bundle) || !is_dir($bundle)) {
            throw new RuntimeException('Refactor verify bundle not found: ' . $value);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $bundle = str_replace('\\', '/', $bundle);
        if ($bundle !== $root && !str_starts_with($bundle, $root . '/')) {
            throw new RuntimeException('Refactor verify bundle escapes the project root.');
        }

        return $bundle;
    }
}
