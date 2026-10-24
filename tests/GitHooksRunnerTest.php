<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitHooks\CheckCommandFactory;
use voku\AgentLoop\GitHooks\CommitMessageValidator;
use voku\AgentLoop\GitHooks\GitHooksCli;
use voku\AgentLoop\GitHooks\GitHookConfig;
use voku\AgentLoop\GitHooks\HookConfigTrustStore;
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

    public function testNoHookPreCommitDoesNotRequireHomeOrXdgConfigHome(): void
    {
        $originalHome = getenv('HOME');
        $originalXdgConfigHome = getenv('XDG_CONFIG_HOME');
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');

        ob_start();
        try {
            $exit = (new GitHooksCli($this->root))->run(['pre-commit']);
        } finally {
            ob_end_clean();
            $this->restoreEnv('HOME', $originalHome);
            $this->restoreEnv('XDG_CONFIG_HOME', $originalXdgConfigHome);
        }

        self::assertSame(0, $exit);
    }

    public function testShellHookFailsClosedWhenNoTrustStoreLocationIsAvailable(): void
    {
        mkdir($this->root . '/.agent-loop');
        $marker = $this->root . '/must-not-run.marker';
        file_put_contents($this->root . '/.agent-loop/githooks.json', json_encode([
            'pre_commit' => ['checks' => [[
                'name' => 'marker',
                'command' => 'touch ' . escapeshellarg($marker),
            ]]],
        ], JSON_THROW_ON_ERROR));
        $originalHome = getenv('HOME');
        $originalXdgConfigHome = getenv('XDG_CONFIG_HOME');
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');

        ob_start();
        try {
            $exit = (new GitHooksCli($this->root))->run(['pre-commit']);
        } finally {
            ob_end_clean();
            $this->restoreEnv('HOME', $originalHome);
            $this->restoreEnv('XDG_CONFIG_HOME', $originalXdgConfigHome);
        }

        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($marker);
    }

    public function testPreCommitCommandDoesNotInheritAmbientSecretsByDefault(): void
    {
        file_put_contents($this->root . '/src/Kept.php', "<?php\n");
        $outputPath = $this->root . '/secret.out';
        $command = 'set -- {files}; printf "%s|%s|%s|%s|%s" "${AGENT_LOOP_BIN:-unset}" "${AGENT_LOOP_TEST_TOKEN:-unset}" "${AGENT_LOOP_DEPLOY_KEY:-unset}" "${AGENT_LOOP_JWT:-unset}" "${PHP_AUTHORIZATION:-unset}" > ' . escapeshellarg($outputPath);
        $runner = new PreCommitRunner($this->root, GitHookConfig::fromArray([
            'pre_commit' => ['checks' => [['name' => 'env-probe', 'command' => $command]]],
        ]));

        $originalBin = getenv('AGENT_LOOP_BIN');
        $originalSecret = getenv('AGENT_LOOP_TEST_TOKEN');
        $originalDeployKey = getenv('AGENT_LOOP_DEPLOY_KEY');
        $originalJwt = getenv('AGENT_LOOP_JWT');
        $originalAuthorization = getenv('PHP_AUTHORIZATION');
        $originalPassthrough = getenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        putenv('AGENT_LOOP_BIN=agent-loop-test-bin');
        putenv('AGENT_LOOP_TEST_TOKEN=ambient-secret-value');
        putenv('AGENT_LOOP_DEPLOY_KEY=ambient-deploy-key');
        putenv('AGENT_LOOP_JWT=ambient-jwt');
        putenv('PHP_AUTHORIZATION=ambient-auth-header');
        putenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        try {
            $exit = $runner->run($this->stubRunner(['src/Kept.php']));
            self::assertSame('agent-loop-test-bin', getenv('AGENT_LOOP_BIN'));
            self::assertSame('ambient-secret-value', getenv('AGENT_LOOP_TEST_TOKEN'));
            self::assertSame('ambient-deploy-key', getenv('AGENT_LOOP_DEPLOY_KEY'));
            self::assertSame('ambient-jwt', getenv('AGENT_LOOP_JWT'));
            self::assertSame('ambient-auth-header', getenv('PHP_AUTHORIZATION'));
        } finally {
            $this->restoreEnv('AGENT_LOOP_BIN', $originalBin);
            $this->restoreEnv('AGENT_LOOP_TEST_TOKEN', $originalSecret);
            $this->restoreEnv('AGENT_LOOP_DEPLOY_KEY', $originalDeployKey);
            $this->restoreEnv('AGENT_LOOP_JWT', $originalJwt);
            $this->restoreEnv('PHP_AUTHORIZATION', $originalAuthorization);
            $this->restoreEnv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH', $originalPassthrough);
        }

        self::assertSame(0, $exit);
        self::assertSame('agent-loop-test-bin|unset|unset|unset|unset', file_get_contents($outputPath));
    }

    public function testPreCommitRefusesUntrustedShellConfigBeforeRunningChecks(): void
    {
        $marker = $this->root . '/untrusted.marker';
        mkdir($this->root . '/.agent-loop');
        file_put_contents($this->root . '/.agent-loop/githooks.json', json_encode([
            'pre_commit' => ['checks' => [[
                'name' => 'marker',
                'command' => 'touch ' . escapeshellarg($marker) . ' {files}',
            ]]],
        ], JSON_THROW_ON_ERROR));
        $store = new HookConfigTrustStore($this->root . '-trust/hook-trust.json');

        ob_start();
        try {
            $exit = (new GitHooksCli($this->root, $store))->run(['pre-commit']);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($marker);
    }

    public function testExplicitTrustCommandRecordsCurrentConfigAndAllowsPreCommit(): void
    {
        mkdir($this->root . '/.agent-loop');
        file_put_contents($this->root . '/src/Kept.php', "<?php\n");
        exec('git -C ' . escapeshellarg($this->root) . ' init --quiet -b main', $output, $exitCode);
        if ($exitCode !== 0) {
            self::markTestSkipped('git init is not available.');
        }
        exec('git -C ' . escapeshellarg($this->root) . ' add src/Kept.php', $output, $exitCode);
        self::assertSame(0, $exitCode);
        $marker = $this->root . '/trusted.marker';
        file_put_contents($this->root . '/.agent-loop/githooks.json', json_encode([
            'pre_commit' => ['checks' => [['name' => 'marker', 'command' => 'true {files} && touch ' . escapeshellarg($marker)]]],
        ], JSON_THROW_ON_ERROR));
        $store = new HookConfigTrustStore($this->root . '-trust/hook-trust.json');
        $cli = new GitHooksCli($this->root, $store);

        ob_start();
        try {
            self::assertSame(0, $cli->run(['trust']));
            self::assertSame(0, $cli->run(['pre-commit']));
        } finally {
            ob_end_clean();
        }

        self::assertFileExists($this->root . '-trust/hook-trust.json');
        self::assertFileExists($marker);
    }

    public function testChangedPreCommitConfigIsRefusedAfterTrust(): void
    {
        $marker = $this->root . '/changed.marker';
        mkdir($this->root . '/.agent-loop');
        $configPath = $this->root . '/.agent-loop/githooks.json';
        file_put_contents($configPath, json_encode([
            'pre_commit' => ['checks' => [['name' => 'noop', 'command' => 'true {files}']]],
        ], JSON_THROW_ON_ERROR));
        $store = new HookConfigTrustStore($this->root . '-trust/hook-trust.json');
        $cli = new GitHooksCli($this->root, $store);
        ob_start();
        try {
            self::assertSame(0, $cli->run(['trust']));
            file_put_contents($configPath, json_encode([
                'pre_commit' => ['checks' => [[
                    'name' => 'changed',
                    'command' => 'touch ' . escapeshellarg($marker) . ' {files}',
                ]]],
            ], JSON_THROW_ON_ERROR));
            $exit = $cli->run(['pre-commit']);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($marker);
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

    /** @param string|false $originalValue */
    private function restoreEnv(string $name, string|false $originalValue): void
    {
        if ($originalValue === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $originalValue);
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

            // The runner asks Git for `-z` output, so the stub answers in that shape:
            // one NUL-terminated record per path, which `exec()` reports as one line
            // because it contains no newline.
            return ['output' => [implode("\0", $stagedFiles) . "\0"], 'exit' => 0];
        };
    }
}
