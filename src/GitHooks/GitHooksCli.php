<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;

/**
 * Entry point the installed `pre-commit` and `commit-msg` hooks call.
 */
final readonly class GitHooksCli
{
    private HookConfigTrustStore $trustStore;

    public function __construct(private string $rootPath, ?HookConfigTrustStore $trustStore = null)
    {
        $this->trustStore = $trustStore ?? new HookConfigTrustStore();
    }

    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        $command = $tokens[0] ?? 'help';
        $rest = array_slice($tokens, 1);

        try {
            return match ($command) {
                'pre-commit' => $this->runPreCommit($rest),
                'commit-msg' => $this->runCommitMessage($rest),
                'verify' => $this->runVerify($rest),
                'trust' => $this->runTrust($rest),
                'help', '--help', '-h', '' => $this->printUsage(0),
                default => $this->printUsage(1, $command),
            };
        } catch (InvalidArgumentException | RuntimeException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function runPreCommit(array $tokens): int
    {
        $configPath = $this->resolveConfigPath(OptionTokens::value($tokens, 'config'));
        $config = $this->loadTrustedPreCommitConfig($configPath);

        return (new PreCommitRunner($this->rootPath, $config))->run();
    }

    /**
     * Explicitly trust the current bytes of a hook config after the operator has reviewed them.
     *
     * @param list<string> $tokens
     */
    private function runTrust(array $tokens): int
    {
        $configPath = $this->resolveConfigPath(OptionTokens::value($tokens, 'config'));
        $digest = $this->trustStore->trust($this->rootPath, $configPath);
        echo 'Hook configuration trusted: ' . $configPath . ' (sha256:' . $digest . ")\n";

        return 0;
    }

    private function loadTrustedPreCommitConfig(string $configPath): GitHookConfig
    {
        if (!is_file($configPath)) {
            return GitHookConfig::fromArray([]);
        }

        $content = file_get_contents($configPath);
        if (!is_string($content)) {
            throw new InvalidArgumentException('Unable to read git hook configuration: ' . $configPath);
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Git hook configuration is not valid JSON: ' . $configPath);
        }

        $config = GitHookConfig::fromArray($decoded);
        if ($config->checks !== []) {
            $this->trustStore->assertTrusted($this->rootPath, $configPath, $content);
        }

        return $config;
    }

    private function resolveConfigPath(?string $configPath): string
    {
        $path = $configPath ?? GitHookConfig::DEFAULT_PATH;

        return str_starts_with($path, '/') ? $path : rtrim($this->rootPath, '/') . '/' . $path;
    }

    /**
     * @param list<string> $tokens
     */
    private function runCommitMessage(array $tokens): int
    {
        $messageFile = null;
        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                $messageFile = $token;

                break;
            }
        }

        if ($messageFile === null) {
            fwrite(\STDERR, "Missing commit message file argument.\n");

            return 1;
        }
        if (!is_file($messageFile)) {
            fwrite(\STDERR, 'Commit message file not found: ' . $messageFile . "\n");

            return 1;
        }

        $config = GitHookConfig::load($this->rootPath, OptionTokens::value($tokens, 'config'));

        if ($config->skipMergeCommits) {
            $output = [];
            $exitCode = 0;
            exec('git -C ' . escapeshellarg($this->rootPath) . ' rev-parse -q --verify MERGE_HEAD 2>/dev/null', $output, $exitCode);
            if ($exitCode === 0 && $output !== []) {
                return 0;
            }
        }

        $message = file_get_contents($messageFile);
        if (!is_string($message)) {
            fwrite(\STDERR, 'Unable to read commit message file: ' . $messageFile . "\n");

            return 1;
        }

        $cleanup = GitCommitCleanup::fromRepository($this->rootPath);
        $violations = (new CommitMessageValidator($config, $cleanup))->validate($message);
        if ($violations === []) {
            return 0;
        }

        echo "Commit blocked:\n";
        foreach ($violations as $violation) {
            echo '  - ' . $violation . "\n";
        }
        echo "Use --no-verify only when the message is genuinely self-explanatory.\n";

        return 1;
    }

    /**
     * @param list<string> $tokens
     */
    private function runVerify(array $tokens): int
    {
        $config = GitHookConfig::load($this->rootPath, OptionTokens::value($tokens, 'config'));
        $examples = new CommitMessageExamples($config);

        if (!$examples->declared()) {
            echo "No commit_msg.examples declared; nothing to verify.\n";

            return 0;
        }

        $problems = $examples->problems();
        if ($problems === []) {
            echo 'Commit message examples verified: ' . $examples->passCount() . ' pass, ' . $examples->failCount() . " fail.\n";

            return 0;
        }

        echo "Commit message examples do not behave as declared:\n";
        foreach ($problems as $problem) {
            echo '  - ' . $problem . "\n";
        }

        return 1;
    }

    private function printUsage(int $exitCode, string $unknownCommand = ''): int
    {
        $usage = <<<'TXT'
        agent-loop githooks - run the package-owned Git hook logic.

        Usage:
          agent-loop githooks trust [--config=PATH]
          agent-loop githooks pre-commit [--config=PATH]
          agent-loop githooks commit-msg <message-file> [--config=PATH]
          agent-loop githooks verify [--config=PATH]

        Both read their rules from .agent-loop/githooks.json: the staged-file
        filters and check commands for pre-commit, the commit convention for
        commit-msg. Without that file the hooks are a no-op.

        verify runs the commit_msg.examples (messages that must pass and must fail)
        through the same validator and exits non-zero when one behaves differently.
        trust records the current hook config digest outside the checkout. Review the config before running it.
        TXT;

        if ($unknownCommand === '') {
            echo $usage . "\n";

            return $exitCode;
        }

        fwrite(\STDERR, 'Unknown githooks command: ' . $unknownCommand . "\n" . $usage . "\n");

        return $exitCode;
    }
}
