<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitHooks\CheckCommandFactory;
use voku\AgentLoop\GitHooks\CommitMessageValidator;
use voku\AgentLoop\GitHooks\GitHookConfig;
use voku\AgentLoop\GitHooks\PreCommitRunner;

/**
 * @internal
 */
final class GitHooksRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-githooks-runner-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/vendor/acme', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testStagedFilesAreFilteredByPatternExclusionAndExistence(): void
    {
        file_put_contents($this->root . '/src/Kept.php', "<?php\n");
        file_put_contents($this->root . '/vendor/acme/Skipped.php', "<?php\n");
        file_put_contents($this->root . '/README.md', "docs\n");

        $runner = new PreCommitRunner($this->root, GitHookConfig::fromArray([
            'pre_commit' => ['file_patterns' => ['*.php'], 'exclude_paths' => ['/vendor/']],
        ]));

        $files = $runner->stagedFiles($this->stubRunner([
            'src/Kept.php',
            'vendor/acme/Skipped.php',
            'README.md',
            'src/Deleted.php',
        ]));

        self::assertSame(['src/Kept.php'], $files);
    }

    public function testWithoutConfiguredChecksTheHookIsANoOp(): void
    {
        $runner = new PreCommitRunner($this->root, GitHookConfig::fromArray([]));

        ob_start();
        $exit = $runner->run($this->stubRunner(['src/Kept.php']));
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('No pre-commit checks configured.', $output);
    }

    public function testMergeCommitSkipsEveryCheck(): void
    {
        $runner = new PreCommitRunner($this->root, GitHookConfig::fromArray([
            'pre_commit' => ['checks' => [['name' => 'never', 'command' => 'exit 1']]],
        ]));

        ob_start();
        $exit = $runner->run(static function (string $command): array {
            if (str_contains($command, 'MERGE_HEAD')) {
                return ['output' => ['abc123'], 'exit' => 0];
            }

            return ['output' => [], 'exit' => 0];
        });
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Merge commit detected', $output);
    }

    public function testCommitMessageValidatorAcceptsAConformingMessage(): void
    {
        $violations = (new CommitMessageValidator($this->commitConfig()))->validate(
            "[+]: \"agent-loop\" -> add claude hook sync\n\nWHY:\n- the settings key was unmanaged and got clobbered on every sync\n",
        );

        self::assertSame([], $violations);
    }

    public function testCommitMessageValidatorRejectsHeaderPlaceholderAndMissingSection(): void
    {
        $validator = new CommitMessageValidator($this->commitConfig());

        self::assertStringContainsString('Invalid commit header', $validator->validate("just some text\n")[0]);
        self::assertStringContainsString(
            'placeholder',
            $validator->validate("[+]: \"scope\" -> subject\n\nWHY: [FILL]\n")[0],
        );
        self::assertStringContainsString(
            'No WHY section found',
            $validator->validate("[+]: \"scope\" -> subject\n\nsome body without the section\n")[0],
        );
    }

    public function testTrivialCommitsSkipTheRequiredSection(): void
    {
        $violations = (new CommitMessageValidator($this->commitConfig()))->validate("[*]: \"styles\" -> reformat\n");

        self::assertSame([], $violations);
    }

    public function testShortAndVagueSectionIsRejectedWhileConcreteShortTextPasses(): void
    {
        $validator = new CommitMessageValidator($this->commitConfig());

        $vague = $validator->validate("[+]: \"scope\" -> subject\n\nWHY:\n- cleanup\n");
        self::assertNotSame([], $vague);
        self::assertStringContainsString('vague', $vague[0]);

        self::assertSame([], $validator->validate("[+]: \"scope\" -> subject\n\nWHY:\n- TICKET-42 blocked the nightly sync\n"));
    }

    public function testBuiltInCheckTypesRenderTheStandardToolCommands(): void
    {
        $config = GitHookConfig::fromArray(['pre_commit' => ['checks' => [
            ['name' => 'lint', 'type' => 'php-lint'],
            ['name' => 'sniffer', 'type' => 'phpcs', 'standard' => 'build/phpcs.xml'],
            ['name' => 'fixer', 'type' => 'php-cs-fixer', 'config' => 'build/.php-cs-fixer.php'],
            ['name' => 'static analysis', 'type' => 'phpstan', 'config' => 'phpstan.neon', 'level' => 8, 'memory_limit' => '2G'],
        ]]]);

        self::assertSame('php -l', $config->checks[0]['command']);
        self::assertTrue($config->checks[0]['per_file']);

        self::assertSame("vendor/bin/phpcs --standard='build/phpcs.xml'", $config->checks[1]['command']);
        self::assertFalse($config->checks[1]['per_file']);

        // Without "fix" the fixer must not rewrite files behind the committer's back.
        self::assertStringContainsString('--dry-run --diff', $config->checks[2]['command']);

        self::assertSame(
            "vendor/bin/phpstan analyse --no-progress --configuration='phpstan.neon' --level=8 --memory-limit='2G'",
            $config->checks[3]['command'],
        );
    }

    public function testFixModeDropsTheDryRunFlag(): void
    {
        $config = GitHookConfig::fromArray(['pre_commit' => ['checks' => [
            ['name' => 'fixer', 'type' => 'php-cs-fixer', 'config' => 'build/.php-cs-fixer.php', 'fix' => true],
        ]]]);

        self::assertStringNotContainsString('--dry-run', $config->checks[0]['command']);
    }

    public function testUnknownCheckTypeIsRejectedWithTheKnownList(): void
    {
        $this->expectExceptionMessageMatches('/Unknown pre-commit check type: psalm/');

        GitHookConfig::fromArray(['pre_commit' => ['checks' => [['name' => 'x', 'type' => 'psalm']]]]);
    }

    public function testCommandTypeStaysTheEscapeHatch(): void
    {
        $config = GitHookConfig::fromArray(['pre_commit' => ['checks' => [
            ['name' => 'custom', 'command' => 'tools/custom-check.sh {files}'],
        ]]]);

        self::assertSame('tools/custom-check.sh {files}', $config->checks[0]['command']);
        self::assertContains('command', CheckCommandFactory::BUILT_IN_TYPES);
    }

    private function commitConfig(): GitHookConfig
    {
        return GitHookConfig::fromArray([
            'commit_msg' => [
                'header_pattern' => '/^\[(\+|~|!|\*|!!!)\]:\s+[^ ].*\s+->\s+.+$/',
                'header_hint' => '[SYMBOL]: "scope" -> <subject>',
                'trivial_header_pattern' => '/^\[\*\]:/',
                'forbidden_patterns' => [
                    ['pattern' => '/WHY:\s*\[FILL\]/i', 'message' => 'Commit template placeholder found (WHY: [FILL]).'],
                ],
                'required_section' => 'WHY',
                'vague_phrases' => ['cleanup', 'minor fixes', 'various changes'],
                'vague_word_threshold' => 8,
            ],
        ]);
    }

    /**
     * @param list<string> $stagedFiles
     * @return callable(string): array{output: list<string>, exit: int}
     */
    private function stubRunner(array $stagedFiles): callable
    {
        return static function (string $command) use ($stagedFiles): array {
            if (str_contains($command, 'MERGE_HEAD')) {
                return ['output' => [], 'exit' => 1];
            }

            return ['output' => $stagedFiles, 'exit' => 0];
        };
    }
}
