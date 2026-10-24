<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\GitHooks\HookConfigTrustStore;
use voku\AgentLoop\Workflow\WorkflowHookRunner;

final class WorkflowHookRunnerTest extends TestCase
{
    private string $root;

    private string $trustRoot;

    private HookConfigTrustStore $trustStore;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hooks-' . bin2hex(random_bytes(6));
        $this->trustRoot = $this->root . '-trust';
        mkdir($this->root . '/.agent-loop', 0o775, true);
        $this->trustStore = new HookConfigTrustStore($this->trustRoot . '/hook-trust.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->removeDirectory($this->trustRoot);
    }

    public function testReturnsEmptyWhenNoHooksConfigured(): void
    {
        $runner = new WorkflowHookRunner($this->root);
        $results = $runner->run('enter', 'TASK-1');

        self::assertSame([], $results);
    }

    public function testNoHookWorkflowDoesNotRequireHomeOrXdgConfigHome(): void
    {
        $originalHome = getenv('HOME');
        $originalXdgConfigHome = getenv('XDG_CONFIG_HOME');
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');

        try {
            $results = (new WorkflowHookRunner($this->root))->run('enter', 'TASK-1');
        } finally {
            $this->restoreEnv('HOME', $originalHome);
            $this->restoreEnv('XDG_CONFIG_HOME', $originalXdgConfigHome);
        }

        self::assertSame([], $results);
    }

    public function testUntrustedHookForAnotherEventDoesNotBlockNoOpEvent(): void
    {
        $markerFile = $this->root . '/approve.marker';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'approve' => [[
                'name' => 'approve-only',
                'command' => 'touch ' . escapeshellarg($markerFile),
            ]],
        ], JSON_THROW_ON_ERROR));
        $runner = new WorkflowHookRunner($this->root, $this->trustStore);

        self::assertSame([], $runner->run('enter', 'TASK-1'));

        try {
            $runner->run('approve', 'TASK-1');
            self::fail('An untrusted hook for the requested event must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not trusted', strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($markerFile);
    }

    public function testRunsHookConfiguredInWorkflowHooksJson(): void
    {
        $markerFile = $this->root . '/enter.marker';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'touch-marker',
                    'command' => 'echo "$AGENT_LOOP_TASK_ID" > "' . $markerFile . '"',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustConfig('.agent-loop/workflow-hooks.json');

        $runner = new WorkflowHookRunner($this->root, $this->trustStore);
        $results = $runner->run('enter', 'TASK-123');

        self::assertCount(1, $results);
        self::assertSame('touch-marker', $results[0]['hook']);
        self::assertSame(0, $results[0]['exit_code']);
        self::assertNull($results[0]['error']);
        self::assertFileExists($markerFile);
        self::assertSame("TASK-123\n", file_get_contents($markerFile));
    }

    public function testFailClosedThrowsExceptionWhenCommandFails(): void
    {
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'failing-hook',
                    'command' => 'exit 42',
                    'fail_closed' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustConfig('.agent-loop/workflow-hooks.json');

        $runner = new WorkflowHookRunner($this->root, $this->trustStore);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow hook "failing-hook"');
        $runner->run('enter', 'TASK-FAIL');
    }

    public function testFailOpenRecordsFailureWithoutThrowing(): void
    {
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'failing-hook',
                    'command' => 'exit 42',
                    'fail_closed' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustConfig('.agent-loop/workflow-hooks.json');

        $runner = new WorkflowHookRunner($this->root, $this->trustStore);
        $results = $runner->run('enter', 'TASK-FAIL');

        self::assertCount(1, $results);
        self::assertSame(42, $results[0]['exit_code']);
    }

    public function testHookDoesNotInheritAmbientSecretByDefault(): void
    {
        $outputFile = $this->root . '/env.out';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'dump-secret',
                    'command' => 'echo "${HOOK_ENV_TEST_API_TOKEN:-unset}" > "' . $outputFile . '"',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustConfig('.agent-loop/workflow-hooks.json');

        $originalSecret = getenv('HOOK_ENV_TEST_API_TOKEN');
        $originalPassthrough = getenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        putenv('HOOK_ENV_TEST_API_TOKEN=super-secret-token');
        putenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        try {
            $runner = new WorkflowHookRunner($this->root, $this->trustStore);
            $runner->run('enter', 'TASK-ENV');
        } finally {
            $this->restoreEnv('HOOK_ENV_TEST_API_TOKEN', $originalSecret);
            $this->restoreEnv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH', $originalPassthrough);
        }

        self::assertSame("unset\n", file_get_contents($outputFile));
    }

    public function testHookDoesNotInheritComposerAuthCredentials(): void
    {
        $outputFile = $this->root . '/composer-auth.out';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'dump-composer-auth',
                    'command' => 'echo "${COMPOSER_AUTH:-unset}" > "' . $outputFile . '"',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustConfig('.agent-loop/workflow-hooks.json');

        $originalComposerAuth = getenv('COMPOSER_AUTH');
        putenv('COMPOSER_AUTH={"http-basic":{"example.test":{"username":"u","password":"p"}}}');
        try {
            $runner = new WorkflowHookRunner($this->root, $this->trustStore);
            $runner->run('enter', 'TASK-COMPOSER-AUTH');
        } finally {
            $this->restoreEnv('COMPOSER_AUTH', $originalComposerAuth);
        }

        self::assertSame("unset\n", file_get_contents($outputFile));
    }

    public function testHookInheritsAllowlistedPathVariable(): void
    {
        $outputFile = $this->root . '/path.out';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'dump-path',
                    'command' => 'echo "${PATH:-unset}" > "' . $outputFile . '"',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustConfig('.agent-loop/workflow-hooks.json');

        $runner = new WorkflowHookRunner($this->root, $this->trustStore);
        $runner->run('enter', 'TASK-PATH');

        self::assertNotSame("unset\n", file_get_contents($outputFile));
    }

    public function testPassthroughOptOutRestoresAmbientSecret(): void
    {
        $outputFile = $this->root . '/env.out';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'dump-secret',
                    'command' => 'echo "${HOOK_ENV_TEST_API_TOKEN:-unset}" > "' . $outputFile . '"',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustConfig('.agent-loop/workflow-hooks.json');

        $originalSecret = getenv('HOOK_ENV_TEST_API_TOKEN');
        $originalPassthrough = getenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        putenv('HOOK_ENV_TEST_API_TOKEN=super-secret-token');
        putenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH=1');
        try {
            $runner = new WorkflowHookRunner($this->root, $this->trustStore);
            $runner->run('enter', 'TASK-ENV-PASSTHROUGH');
        } finally {
            $this->restoreEnv('HOOK_ENV_TEST_API_TOKEN', $originalSecret);
            $this->restoreEnv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH', $originalPassthrough);
        }

        self::assertSame("super-secret-token\n", file_get_contents($outputFile));
    }

    public function testUntrustedWorkflowConfigCannotRunACommand(): void
    {
        $marker = $this->root . '/must-not-run.marker';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [['name' => 'untrusted', 'command' => 'touch ' . escapeshellarg($marker)]],
        ], JSON_THROW_ON_ERROR));

        try {
            (new WorkflowHookRunner($this->root, $this->trustStore))->run('enter', 'TASK-UNTRUSTED');
            self::fail('Untrusted workflow hooks must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not trusted', strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($marker);
    }

    public function testChangedWorkflowConfigCannotRunACommandAfterTrust(): void
    {
        $configPath = $this->root . '/.agent-loop/workflow-hooks.json';
        $marker = $this->root . '/must-not-run.marker';
        file_put_contents($configPath, json_encode([
            'enter' => [['name' => 'trusted', 'command' => 'true']],
        ], JSON_THROW_ON_ERROR));
        $this->trustStore->trust($this->root, $configPath);
        file_put_contents($configPath, json_encode([
            'enter' => [['name' => 'changed', 'command' => 'touch ' . escapeshellarg($marker)]],
        ], JSON_THROW_ON_ERROR));

        try {
            (new WorkflowHookRunner($this->root, $this->trustStore))->run('enter', 'TASK-CHANGED');
            self::fail('Changed workflow hooks must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('changed', strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($marker);
    }

    public function testUntrustedWorkflowHooksInGitHooksConfigCannotRun(): void
    {
        $marker = $this->root . '/must-not-run.marker';
        file_put_contents($this->root . '/.agent-loop/githooks.json', json_encode([
            'workflow_hooks' => [
                'enter' => [['name' => 'untrusted', 'command' => 'touch ' . escapeshellarg($marker)]],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            (new WorkflowHookRunner($this->root, $this->trustStore))->run('enter', 'TASK-GITHOOKS-UNTRUSTED');
            self::fail('Untrusted githooks workflow hooks must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not trusted', strtolower($exception->getMessage()));
        }

        self::assertFileDoesNotExist($marker);
    }

    public function testExplicitlyTrustedWorkflowHooksInGitHooksConfigRun(): void
    {
        $configPath = $this->root . '/.agent-loop/githooks.json';
        $marker = $this->root . '/trusted-githooks.marker';
        file_put_contents($configPath, json_encode([
            'workflow_hooks' => [
                'enter' => [['name' => 'trusted', 'command' => 'touch ' . escapeshellarg($marker)]],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->trustStore->trust($this->root, $configPath);

        $results = (new WorkflowHookRunner($this->root, $this->trustStore))->run('enter', 'TASK-GITHOOKS-TRUSTED');

        self::assertCount(1, $results);
        self::assertSame(0, $results[0]['exit_code']);
        self::assertFileExists($marker);
    }

    /** @param string|false $originalValue the value getenv() returned before the test changed it */
    private function restoreEnv(string $name, string|false $originalValue): void
    {
        if ($originalValue === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $originalValue);
    }

    private function trustConfig(string $relativePath): void
    {
        $this->trustStore->trust($this->root, $this->root . '/' . $relativePath);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
