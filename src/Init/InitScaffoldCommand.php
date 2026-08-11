<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentKanban\Cli\CliApplication;
use voku\AgentLoop\ProjectLayout;

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
        try {
            $options = $this->parse($tokens);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, '[FAIL] init scaffold: ' . $exception->getMessage() . "\n");

            return 1;
        }

        $dryRun = $options['dryRun'];
        $root = rtrim($this->rootPath, '/');
        $configPath = $root . '/.agent-loop/init.json';
        $existingConfig = (new InitConfigLoader($root))->load('.agent-loop/init.json');
        $layout = $options['layout'] ?? $existingConfig['layout'];

        if (is_file($configPath) && $options['layout'] !== null && $existingConfig['layout'] !== $options['layout']) {
            fwrite(
                STDERR,
                '[FAIL] init scaffold: existing .agent-loop/init.json uses layout '
                . $existingConfig['layout']
                . '; refusing to rewrite it as '
                . $options['layout']
                . ".\n",
            );

            return 1;
        }

        $this->ensureDirectory($root . '/.agent-loop', '.agent-loop', $dryRun);
        if (!is_file($configPath)) {
            $config = "{\n  \"version\": 1,\n  \"layout\": \"{$layout}\"\n}\n";
            $this->ensureFile($configPath, '.agent-loop/init.json', $config, $dryRun);
        } else {
            echo '[SKIP] .agent-loop/init.json already exists' . "\n";
        }

        $stateRoot = $layout === 'compact' ? $root . '/.agent-loop' : $root;
        $sessionsRoot = $layout === 'compact' ? $stateRoot . '/sessions' : $root . '/session_plan';
        $learningRoot = $layout === 'compact' ? $stateRoot . '/learning' : $root . '/infra/doc/agent-learning';

        foreach ([
            [$stateRoot . '/todo/cards', $this->relative($root, $stateRoot . '/todo/cards')],
            [$stateRoot . '/tasks', $this->relative($root, $stateRoot . '/tasks')],
            [$sessionsRoot, $this->relative($root, $sessionsRoot)],
            [$learningRoot . '/findings', $this->relative($root, $learningRoot . '/findings')],
        ] as [$directory, $display]) {
            $this->ensureDirectory($directory, $display, $dryRun);
        }

        $this->ensureFile($stateRoot . '/todo/board.md', $this->relative($root, $stateRoot . '/todo/board.md'), <<<'MD'
# Board Metadata

- **Source:** `todo/cards/*.md`
- **Project prefix:** DEMO
- **Done count:** 0
MD
            . "\n", $dryRun);
        $this->ensureFile($stateRoot . '/tasks/DEMO-1.md', $this->relative($root, $stateRoot . '/tasks/DEMO-1.md'), <<<'MD'
# DEMO-1: Add a small validated change

Use this generated task to try the governed workflow. Choose one small,
real change in this repository, then record the validation that proves it.
MD
            . "\n", $dryRun);

        $cardPath = $stateRoot . '/todo/cards/' . self::EXAMPLE_TASK_ID . '.md';
        $cardDisplay = $this->relative($root, $cardPath);
        if (is_file($cardPath) || is_file($stateRoot . '/todo/jira/' . self::EXAMPLE_TASK_ID . '.md')) {
            echo '[SKIP] ' . $cardDisplay . ' already exists' . "\n";
        } elseif ($dryRun) {
            echo '[DRY-RUN] would create ' . $cardDisplay . "\n";
        } else {
            $board = new CliApplication($stateRoot);
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
            echo '[CREATE] ' . $cardDisplay . "\n";
        }

        if (!$dryRun) {
            $resolvedCompact = (new ProjectLayout($root))->isCompact();
            $expectedCompact = $layout === 'compact';
            if ($resolvedCompact !== $expectedCompact) {
                fwrite(STDERR, "[FAIL] init scaffold: {$layout} layout config was not persisted correctly.\n");

                return 1;
            }
        }

        echo "\n[OK] init scaffold: minimal local workflow structure is ready ({$layout} layout).\n";
        echo "Next:\n";
        echo "  agent-loop board card show DEMO-1\n";
        echo "  agent-loop workflow plan DEMO-1 --by <actor> --file composer.json --goal \"Add a small validated change.\" --validation \"composer test\"\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @return array{dryRun: bool, layout: 'legacy'|'compact'|null}
     */
    private function parse(array $tokens): array
    {
        $dryRun = false;
        $layout = null;

        foreach ($tokens as $token) {
            if ($token === '--dry-run') {
                $dryRun = true;

                continue;
            }
            if (str_starts_with($token, '--layout=')) {
                $value = substr($token, strlen('--layout='));
                if (!in_array($value, ['legacy', 'compact'], true)) {
                    throw new \InvalidArgumentException('--layout must be legacy or compact.');
                }
                if ($layout !== null) {
                    throw new \InvalidArgumentException('--layout may be provided only once.');
                }
                $layout = $value;

                continue;
            }

            throw new \InvalidArgumentException('supported options are --dry-run and --layout=legacy|compact.');
        }

        return ['dryRun' => $dryRun, 'layout' => $layout];
    }

    private function ensureDirectory(string $path, string $displayPath, bool $dryRun): void
    {
        if (is_dir($path)) {
            echo '[SKIP] ' . $displayPath . '/ already exists' . "\n";

            return;
        }

        if ($dryRun) {
            echo '[DRY-RUN] would create ' . $displayPath . '/' . "\n";

            return;
        }

        if (!mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory: ' . $path);
        }

        echo '[CREATE] ' . $displayPath . '/' . "\n";
    }

    private function ensureFile(string $path, string $displayPath, string $content, bool $dryRun): void
    {
        if (is_file($path)) {
            echo '[SKIP] ' . $displayPath . ' already exists' . "\n";

            return;
        }

        if ($dryRun) {
            echo '[DRY-RUN] would create ' . $displayPath . "\n";

            return;
        }

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to write file: ' . $path);
        }

        echo '[CREATE] ' . $displayPath . "\n";
    }

    private function relative(string $root, string $path): string
    {
        $prefix = rtrim($root, '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
