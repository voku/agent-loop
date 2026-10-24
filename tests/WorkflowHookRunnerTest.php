<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\WorkflowHookRunner;

final class WorkflowHookRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hooks-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReturnsEmptyWhenNoHooksConfigured(): void
    {
        $runner = new WorkflowHookRunner($this->root);
        $results = $runner->run('enter', 'TASK-1');

        self::assertSame([], $results);
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

        $runner = new WorkflowHookRunner($this->root);
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

        $runner = new WorkflowHookRunner($this->root);

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

        $runner = new WorkflowHookRunner($this->root);
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

        $originalSecret = getenv('HOOK_ENV_TEST_API_TOKEN');
        $originalPassthrough = getenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        putenv('HOOK_ENV_TEST_API_TOKEN=super-secret-token');
        putenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        try {
            $runner = new WorkflowHookRunner($this->root);
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

        $originalComposerAuth = getenv('COMPOSER_AUTH');
        putenv('COMPOSER_AUTH={"http-basic":{"example.test":{"username":"u","password":"p"}}}');
        try {
            $runner = new WorkflowHookRunner($this->root);
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

        $runner = new WorkflowHookRunner($this->root);
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

        $originalSecret = getenv('HOOK_ENV_TEST_API_TOKEN');
        $originalPassthrough = getenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH');
        putenv('HOOK_ENV_TEST_API_TOKEN=super-secret-token');
        putenv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH=1');
        try {
            $runner = new WorkflowHookRunner($this->root);
            $runner->run('enter', 'TASK-ENV-PASSTHROUGH');
        } finally {
            $this->restoreEnv('HOOK_ENV_TEST_API_TOKEN', $originalSecret);
            $this->restoreEnv('AGENT_LOOP_HOOK_ENV_PASSTHROUGH', $originalPassthrough);
        }

        self::assertSame("super-secret-token\n", file_get_contents($outputFile));
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
