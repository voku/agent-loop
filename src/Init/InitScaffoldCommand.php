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
        try {
            $options = $this->parse($tokens);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, '[FAIL] init scaffold: ' . $exception->getMessage() . "\n");

            return 1;
        }

        $dryRun = $options['dryRun'];
        $agent = $options['agent'];
        $root = rtrim($this->rootPath, '/');
        $stateRoot = $root . '/.agent-loop';
        $configPath = $stateRoot . '/init.json';
        $sessionsRoot = $stateRoot . '/sessions';
        $learningRoot = $stateRoot . '/learning';

        $this->ensureDirectory($stateRoot, '.agent-loop', $dryRun);
        if (!is_file($configPath)) {
            $this->ensureFile($configPath, '.agent-loop/init.json', "{\n  \"version\": 1\n}\n", $dryRun);
        } else {
            echo '[SKIP] .agent-loop/init.json already exists' . "\n";
        }

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

- **Project prefix:** DEMO
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

        if ($agent !== null) {
            foreach ($agent->messages() as $message) {
                echo $message . "\n";
            }

            $installTokens = ['--agent=' . $agent->canonicalName()];
            if ($dryRun) {
                $installTokens[] = '--dry-run';
            }
            $installExit = (new InitInstallAssetsCommand($root))->run($installTokens);
            if ($installExit !== 0) {
                return $installExit;
            }

            echo "\n[OK] init scaffold: local workflow structure and host assets are ready.\n";
            echo "Next:\n";
            echo "  Start a fresh agent session so the projected instructions and skills can actually be consumed.\n";
            echo "  agent-loop init doctor\n";
            echo "  agent-loop map build --paths=src,tests\n";
            echo "  agent-loop map search-index build\n";
            echo "  agent-loop board card show DEMO-1\n";

            return 0;
        }

        echo "\n[OK] init scaffold: minimal local workflow structure is ready.\n";
        echo "[WARN] Host assets were not projected because --agent was not provided.\n";
        echo "Before workflow work:\n";
        echo "  agent-loop init install-assets --agent=<codex|claude|copilot|antigravity>\n";
        echo "  Start a fresh agent session so the projected instructions and skills can actually be consumed.\n";
        echo "  agent-loop init doctor\n";
        echo "  Build or refresh agent-map before workflow approve when governed Recall depends on repository discovery.\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     * @return array{dryRun: bool, agent: InitAgent|null}
     */
    private function parse(array $tokens): array
    {
        $dryRun = false;
        $requestedAgent = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--dry-run') {
                $dryRun = true;

                continue;
            }

            if (str_starts_with($token, '--agent=')) {
                $requestedAgent = substr($token, strlen('--agent='));

                continue;
            }

            if ($token === '--agent') {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || $candidate === '' || str_starts_with($candidate, '--')) {
                    throw new \InvalidArgumentException('Missing value for option: --agent');
                }
                $requestedAgent = $candidate;
                ++$i;

                continue;
            }

            throw new \InvalidArgumentException('supported options are --agent=<agent|all> and --dry-run.');
        }

        $agent = $requestedAgent === null
            ? null
            : InitAgent::parse($requestedAgent, InitAgent::canonicalNames(), true);

        return ['dryRun' => $dryRun, 'agent' => $agent];
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
