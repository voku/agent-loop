<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

use InvalidArgumentException;

/**
 * Entry point the installed `pre-commit` and `commit-msg` hooks call.
 */
final readonly class GitHooksCli
{
    public function __construct(private string $rootPath)
    {
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
                'help', '--help', '-h', '' => $this->printUsage(0),
                default => $this->printUsage(1, $command),
            };
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function runPreCommit(array $tokens): int
    {
        $config = GitHookConfig::load($this->rootPath, $this->readOptionValue($tokens, 'config'));

        return (new PreCommitRunner($this->rootPath, $config))->run();
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

        $config = GitHookConfig::load($this->rootPath, $this->readOptionValue($tokens, 'config'));

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

        $violations = (new CommitMessageValidator($config))->validate($message);
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
    private function readOptionValue(array $tokens, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($tokens as $index => $token) {
            if (str_starts_with($token, $prefix)) {
                $value = substr($token, strlen($prefix));

                return $value === '' ? null : $value;
            }

            if ($token === '--' . $name) {
                $candidate = $tokens[$index + 1] ?? null;
                if (is_string($candidate) && !str_starts_with($candidate, '--')) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function printUsage(int $exitCode, string $unknownCommand = ''): int
    {
        $usage = <<<'TXT'
        agent-loop githooks - run the package-owned Git hook logic.

        Usage:
          agent-loop githooks pre-commit [--config=PATH]
          agent-loop githooks commit-msg <message-file> [--config=PATH]

        Both read their rules from .agent-loop/githooks.json: the staged-file
        filters and check commands for pre-commit, the commit convention for
        commit-msg. Without that file the hooks are a no-op.
        TXT;

        if ($unknownCommand === '') {
            echo $usage . "\n";

            return $exitCode;
        }

        fwrite(\STDERR, 'Unknown githooks command: ' . $unknownCommand . "\n" . $usage . "\n");

        return $exitCode;
    }
}
