<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use voku\AgentKanban\Cli\CliApplication;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ConfigurationException;
use voku\AgentKanban\Repository\BoardConfigurationWriter;
use voku\AgentKanban\Repository\BoardContext;
use voku\AgentKanban\Repository\BoardContextResolver;

/**
 * Creates the smallest local state needed for the governed workflow.
 *
 * Demo state is opt-in. Board configuration and storage are bootstrapped through
 * agent-kanban's owner API, while the example card still uses its public CLI.
 */
final readonly class InitScaffoldCommand
{
    private const string EXAMPLE_TASK_ID = 'DEMO-1';

    private const string VCS_POLICY = <<<'GITIGNORE'
/map/
/recall/
/sessions/
/edit/
/runs/*/*.lock
/tool-inventory.json
GITIGNORE;

    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
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
        $boardPrefix = $options['boardPrefix'];
        $demo = $options['demo'];
        $root = rtrim($this->rootPath, '/');
        $stateRoot = $root . '/.agent-loop';
        $configPath = $stateRoot . '/init.json';
        $vcsPolicyPath = $stateRoot . '/.gitignore';
        $sessionsRoot = $stateRoot . '/sessions';
        $learningRoot = $stateRoot . '/learning';

        $this->ensureDirectory($stateRoot, '.agent-loop', $dryRun);
        if (!is_file($configPath)) {
            $this->ensureFile($configPath, '.agent-loop/init.json', "{\n  \"version\": 1\n}\n", $dryRun);
        } else {
            echo '[SKIP] .agent-loop/init.json already exists' . "\n";
        }
        $this->ensureFile($vcsPolicyPath, '.agent-loop/.gitignore', self::VCS_POLICY . "\n", $dryRun);

        foreach ([
            [$stateRoot . '/tasks', $this->relative($root, $stateRoot . '/tasks')],
            [$sessionsRoot, $this->relative($root, $sessionsRoot)],
            [$learningRoot, $this->relative($root, $learningRoot)],
        ] as [$directory, $display]) {
            $this->ensureDirectory($directory, $display, $dryRun);
        }

        $boardContext = null;
        if ($boardPrefix !== null) {
            if ($dryRun) {
                echo '[DRY-RUN] would bootstrap board configuration/storage for ' . $boardPrefix . "\n";
            } else {
                $boardContext = (new BoardConfigurationWriter())->bootstrapConventional($stateRoot, $boardPrefix);
                echo '[OK] board configuration/storage ready for ' . $boardContext->config->projectPrefix . "\n";
            }
        }

        if ($demo) {
            $demoExit = $this->ensureDemoTaskAndCard($root, $stateRoot, $dryRun, $boardContext);
            if ($demoExit !== 0) {
                return $demoExit;
            }
        }

        $hasBoardIdentity = $boardPrefix !== null;
        if (!$dryRun && !$hasBoardIdentity) {
            $hasBoardIdentity = (new BoardContextResolver())->resolveOptional($stateRoot) !== null;
        }
        $cliPath = (new RepositoryActivation($root))->cliPath();

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

            $hostStatusCommand = $agent->isAll()
                ? $cliPath . ' init host-status --format=json'
                : $cliPath . ' init host-status --agent=' . $agent->canonicalName() . ' --format=json';

            echo "\n[OK] init scaffold: local workflow structure and host assets are ready for repository convergence.\n";
            echo "Next:\n";
            echo '  ' . $hostStatusCommand . "\n";
            echo "  Follow its next_action until next_action_kind=none, then start a fresh agent session so projected assets can be consumed.\n";
            echo '  ' . $cliPath . " map build --paths=src,tests\n";
            echo '  ' . $cliPath . " map search-index build\n";
            $this->printBoardNext($hasBoardIdentity, $demo);

            return 0;
        }

        echo "\n[OK] init scaffold: minimal local workflow structure is ready.\n";
        echo "[WARN] Host assets were not projected because --agent was not provided.\n";
        echo "Before workflow work:\n";
        echo '  ' . $cliPath . " init host-status --format=json\n";
        echo "  Follow its decision_required/next_action result to select and converge the detected coding host.\n";
        $this->printBoardNext($hasBoardIdentity, $demo);
        echo "  Build or refresh agent-map before workflow approve when governed Recall depends on repository discovery.\n";

        return 0;
    }

    private function ensureDemoTaskAndCard(
        string $root,
        string $stateRoot,
        bool $dryRun,
        ?BoardContext $boardContext,
    ): int {
        $this->ensureFile($stateRoot . '/tasks/DEMO-1.md', $this->relative($root, $stateRoot . '/tasks/DEMO-1.md'), <<<'MD'
# DEMO-1: Add a small validated change

Use this generated task to try the governed workflow. Choose one small,
real change in this repository, then record the validation that proves it.
MD
            . "\n", $dryRun);

        if ($dryRun) {
            echo '[DRY-RUN] would create demo board card ' . self::EXAMPLE_TASK_ID . "\n";

            return 0;
        }

        $boardContext ??= (new BoardContextResolver())->resolve($stateRoot);
        $cardId = CardId::fromString(self::EXAMPLE_TASK_ID);
        if ($boardContext->repository->exists($cardId)) {
            echo '[SKIP] demo board card ' . self::EXAMPLE_TASK_ID . ' already exists' . "\n";

            return 0;
        }

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
                '--brief=Choose one small real change, then record the validation that proves it.',
            ]);
        } finally {
            ob_end_clean();
        }
        if ($exit !== 0) {
            fwrite(STDERR, '[FAIL] init scaffold: could not create the example board card.' . "\n");

            return $exit;
        }

        echo '[CREATE] demo board card ' . self::EXAMPLE_TASK_ID . "\n";

        return 0;
    }

    private function printBoardNext(bool $hasBoardIdentity, bool $demo): void
    {
        if ($demo) {
            echo "  agent-loop board card show DEMO-1\n";

            return;
        }
        if ($hasBoardIdentity) {
            echo "  agent-loop board verify\n";

            return;
        }

        echo "  agent-loop init scaffold --prefix=<PROJECT>  # configure the real board identity before board commands\n";
    }

    /**
     * @param list<string> $tokens
     * @return array{dryRun: bool, agent: InitAgent|null, boardPrefix: string|null, demo: bool}
     */
    private function parse(array $tokens): array
    {
        $dryRun = false;
        $demo = false;
        $requestedAgent = null;
        $requestedPrefix = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === '--dry-run') {
                $dryRun = true;

                continue;
            }
            if ($token === '--demo') {
                $demo = true;

                continue;
            }
            if (str_starts_with($token, '--agent=')) {
                $requestedAgent = substr($token, strlen('--agent='));

                continue;
            }
            if (str_starts_with($token, '--prefix=')) {
                if ($requestedPrefix !== null) {
                    throw new \InvalidArgumentException('--prefix may be provided only once.');
                }
                $requestedPrefix = substr($token, strlen('--prefix='));

                continue;
            }
            if ($token === '--agent' || $token === '--prefix') {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || $candidate === '' || str_starts_with($candidate, '--')) {
                    throw new \InvalidArgumentException('Missing value for option: ' . $token);
                }
                if ($token === '--agent') {
                    $requestedAgent = $candidate;
                } else {
                    if ($requestedPrefix !== null) {
                        throw new \InvalidArgumentException('--prefix may be provided only once.');
                    }
                    $requestedPrefix = $candidate;
                }
                ++$i;

                continue;
            }

            throw new \InvalidArgumentException(
                'supported options are --agent=<agent|all>, --prefix=<PROJECT>, --demo and --dry-run.',
            );
        }

        if ($demo && $requestedPrefix !== null) {
            throw new \InvalidArgumentException('--demo and --prefix are mutually exclusive.');
        }

        $agent = $requestedAgent === null
            ? null
            : InitAgent::parse($requestedAgent, InitAgent::canonicalNames(), true);

        $boardPrefix = $demo ? 'DEMO' : $requestedPrefix;
        if ($boardPrefix !== null) {
            try {
                BoardConfig::default($boardPrefix);
            } catch (ConfigurationException $exception) {
                throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
            }
        }

        return [
            'dryRun' => $dryRun,
            'agent' => $agent,
            'boardPrefix' => $boardPrefix,
            'demo' => $demo,
        ];
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
