<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentKanban\Cli\CliApplication;

/**
 * Creates the smallest local state needed for the governed workflow.
 *
 * The example card is deliberately created through agent-kanban's public CLI
 * so this package never has to duplicate its Markdown card format.
 */
final readonly class InitScaffoldCommand
{
    private const string EXAMPLE_TASK_ID = 'DEMO-1';

    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        if ($tokens !== [] && $tokens !== ['--dry-run']) {
            fwrite(STDERR, '[FAIL] init scaffold: only --dry-run is supported.' . "\n");

            return 1;
        }

        $dryRun = $tokens === ['--dry-run'];
        $root = rtrim($this->rootPath, '/');

        foreach ([
            '.agent-loop',
            'todo/cards',
            'tasks',
            'session_plan',
            'infra/doc/agent-learning/findings',
        ] as $directory) {
            $this->ensureDirectory($root, $directory, $dryRun);
        }

        $this->ensureFile($root, '.agent-loop/init.json', "{\n  \"version\": 1\n}\n", $dryRun);
        $this->ensureFile($root, 'todo/board.md', <<<'MD'
# Board Metadata

- **Source:** `todo/cards/*.md`
- **Project prefix:** DEMO
- **Done count:** 0
MD
            . "\n", $dryRun);
        $this->ensureFile($root, 'tasks/DEMO-1.md', <<<'MD'
# DEMO-1: Add a small validated change

Use this generated task to try the governed workflow. Choose one small,
real change in this repository, then record the validation that proves it.
MD
            . "\n", $dryRun);

        $cardPath = $root . '/todo/cards/' . self::EXAMPLE_TASK_ID . '.md';
        if (is_file($cardPath) || is_file($root . '/todo/jira/' . self::EXAMPLE_TASK_ID . '.md')) {
            echo '[SKIP] todo/cards/DEMO-1.md already exists' . "\n";
        } elseif ($dryRun) {
            echo '[DRY-RUN] would create todo/cards/DEMO-1.md' . "\n";
        } else {
            $board = new CliApplication($root);
            ob_start();
            try {
                $exit = $board->run([
                    'agent-loop',
                    'card',
                    'create',
                    self::EXAMPLE_TASK_ID,
                    '--title=Add a small validated change',
                    '--lane=READY',
                    '--status=Selected',
                    '--summary=Generated example task for your first governed workflow.',
                ]);
                if ($exit === 0) {
                    $exit = $board->run([
                        'agent-loop',
                        'card',
                        'update',
                        self::EXAMPLE_TASK_ID,
                        '--brief=Choose one small real change, then record the validation that proves it.',
                    ]);
                }
            } finally {
                ob_end_clean();
            }
            if ($exit !== 0) {
                fwrite(STDERR, '[FAIL] init scaffold: could not create the example board card.' . "\n");

                return $exit;
            }
            echo '[CREATE] todo/cards/DEMO-1.md' . "\n";
        }

        echo "\n[OK] init scaffold: minimal local workflow structure is ready.\n";
        echo "Next:\n";
        echo "  agent-loop board card show DEMO-1\n";
        echo "  agent-loop workflow plan DEMO-1 --by <actor> --file composer.json --goal \"Add a small validated change.\" --validation \"composer test\"\n";

        return 0;
    }

    private function ensureDirectory(string $root, string $relativePath, bool $dryRun): void
    {
        $path = $root . '/' . $relativePath;
        if (is_dir($path)) {
            echo '[SKIP] ' . $relativePath . '/ already exists' . "\n";

            return;
        }

        if ($dryRun) {
            echo '[DRY-RUN] would create ' . $relativePath . '/' . "\n";

            return;
        }

        if (!mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory: ' . $path);
        }

        echo '[CREATE] ' . $relativePath . '/' . "\n";
    }

    private function ensureFile(string $root, string $relativePath, string $content, bool $dryRun): void
    {
        $path = $root . '/' . $relativePath;
        if (is_file($path)) {
            echo '[SKIP] ' . $relativePath . ' already exists' . "\n";

            return;
        }

        if ($dryRun) {
            echo '[DRY-RUN] would create ' . $relativePath . "\n";

            return;
        }

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to write file: ' . $path);
        }

        echo '[CREATE] ' . $relativePath . "\n";
    }
}
