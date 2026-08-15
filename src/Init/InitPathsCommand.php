<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\AgentOutput;
use voku\AgentLoop\ProjectLayout;

/**
 * Answers "where does this project keep its workflow state" so a coding agent
 * can read the layout instead of assuming `.agent-loop/`.
 *
 * Read-only: it resolves and prints, and never creates anything. Use
 * `agent-loop init scaffold` to create the tree.
 */
final readonly class InitPathsCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        try {
            $format = $this->parse($tokens);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, '[FAIL] init paths: ' . $exception->getMessage() . "\n");

            return 1;
        }

        $paths = (new ProjectLayout($this->rootPath))->describe();

        if ($format === 'json') {
            echo json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        }
        if ($format === 'toon') {
            echo AgentOutput::toon($paths);

            return 0;
        }

        $width = max(1, ...array_map(strlen(...), array_keys($paths)));
        foreach ($paths as $name => $path) {
            $exists = $name === 'project_root' || file_exists($this->absolute($path));
            printf("%-{$width}s  %s%s\n", $name, $path, $exists ? '' : '  (not created yet)');
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @return 'text'|'json'|'toon'
     */
    private function parse(array $tokens): string
    {
        $format = 'text';
        foreach ($tokens as $token) {
            if (str_starts_with($token, '--format=')) {
                $format = substr($token, strlen('--format='));
                continue;
            }

            throw new InvalidArgumentException('supported option is --format=text|json|toon, got ' . $token . '.');
        }

        if (!in_array($format, ['text', 'json', 'toon'], true)) {
            throw new InvalidArgumentException('--format must be text, json, or toon.');
        }

        return $format;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->rootPath, '/') . '/' . $path;
    }
}
