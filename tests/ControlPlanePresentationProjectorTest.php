<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Process\CommandProcessResult;
use voku\AgentLoop\Workflow\ControlPlanePresentationProjector;

final class ControlPlanePresentationProjectorTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $directory) {
            $this->removeDirectory($directory);
        }
        $this->tempDirs = [];
    }

    public function testDisabledControlPlaneDoesNotProbe(): void
    {
        $root = $this->tempDir();
        $called = false;
        $projector = new ControlPlanePresentationProjector(
            $root,
            static function (array $command) use (&$called): CommandProcessResult {
                $called = true;

                return new CommandProcessResult(0, '{}', '', false);
            },
        );

        self::assertNull($projector->project('ATTN-1'));
        self::assertFalse($called);
    }

    public function testReadyProbeUsesOnlyKnownAgentUiCommandAndOwnerUrl(): void
    {
        $root = $this->configuredRoot([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 9123,
            'ensure_command' => ['touch', '/tmp/should-never-run'],
        ]);
        $this->installAgentUiPlaceholder($root);
        $seen = null;
        $projector = new ControlPlanePresentationProjector(
            $root,
            static function (array $command) use (&$seen): CommandProcessResult {
                $seen = $command;

                return new CommandProcessResult(
                    0,
                    json_encode([
                        'status' => 'ready',
                        'url' => 'http://localhost:9123/',
                        'project_id' => 'owner-verified',
                        'detail' => null,
                    ], JSON_THROW_ON_ERROR),
                    '',
                    false,
                );
            },
        );

        $result = $projector->project('ATTN 42');

        self::assertNotNull($result);
        self::assertSame('ready', $result['status']);
        self::assertFalse($result['required']);
        self::assertSame('http://localhost:9123/task/ATTN%2042', $result['url']);
        self::assertSame([
            $root . '/vendor/bin/agent-ui',
            'status',
            '--root=' . $root,
            '--host=localhost',
            '--port=9123',
            '--format=json',
        ], $seen);
        self::assertNotContains('touch', $seen ?? []);
    }

    public function testMissingAgentUiIsNonBlockingPresentationStateWithoutProbe(): void
    {
        $root = $this->configuredRoot(['enabled' => true]);
        $called = false;
        $projector = new ControlPlanePresentationProjector(
            $root,
            static function (array $command) use (&$called): CommandProcessResult {
                $called = true;

                return new CommandProcessResult(0, '{}', '', false);
            },
        );

        $result = $projector->project('ATTN-1');

        self::assertNotNull($result);
        self::assertSame('not_installed', $result['status']);
        self::assertNull($result['url']);
        self::assertFalse($result['required']);
        self::assertFalse($called);
    }

    public function testOwnerNonReadyStatesNeverProduceTaskUrl(): void
    {
        foreach (['unreachable', 'wrong_service', 'wrong_project', 'invalid_response'] as $status) {
            $root = $this->configuredRoot(['enabled' => true]);
            $this->installAgentUiPlaceholder($root);
            $projector = new ControlPlanePresentationProjector(
                $root,
                static fn (array $command): CommandProcessResult => new CommandProcessResult(
                    2,
                    json_encode([
                        'status' => $status,
                        'url' => 'http://127.0.0.1:8088',
                        'project_id' => 'owner-result',
                        'detail' => $status . ' detail',
                    ], JSON_THROW_ON_ERROR),
                    '',
                    false,
                ),
            );

            $result = $projector->project('ATTN-2');

            self::assertNotNull($result);
            self::assertSame($status, $result['status']);
            self::assertNull($result['url']);
            self::assertFalse($result['required']);
        }
    }

    public function testProbeFailuresRemainPresentationFailures(): void
    {
        $cases = [
            new CommandProcessResult(1, '{broken', '', false),
            new CommandProcessResult(124, '', '', true),
            new CommandProcessResult(
                2,
                json_encode([
                    'status' => 'ready',
                    'url' => 'http://127.0.0.1:8088',
                    'detail' => null,
                ], JSON_THROW_ON_ERROR),
                '',
                false,
            ),
            new CommandProcessResult(
                0,
                json_encode(['status' => 'ready', 'detail' => null], JSON_THROW_ON_ERROR),
                '',
                false,
            ),
        ];

        foreach ($cases as $processResult) {
            $root = $this->configuredRoot(['enabled' => true]);
            $this->installAgentUiPlaceholder($root);
            $projector = new ControlPlanePresentationProjector(
                $root,
                static fn (array $command): CommandProcessResult => $processResult,
            );

            $result = $projector->project('ATTN-3');

            self::assertNotNull($result);
            self::assertSame('probe_failed', $result['status']);
            self::assertNull($result['url']);
            self::assertFalse($result['required']);
        }
    }

    /** @param array<string, mixed> $controlPlane */
    private function configuredRoot(array $controlPlane): string
    {
        $root = $this->tempDir();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', json_encode([
            'interaction' => ['control_plane' => $controlPlane],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    private function installAgentUiPlaceholder(string $root): void
    {
        if (!mkdir($root . '/vendor/bin', 0o775, true) && !is_dir($root . '/vendor/bin')) {
            throw new RuntimeException('Unable to create vendor/bin fixture.');
        }
        file_put_contents($root . '/vendor/bin/agent-ui', "#!/usr/bin/env php\n");
    }

    private function tempDir(): string
    {
        $directory = sys_get_temp_dir() . '/agent-loop-control-plane-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o775, true));
        $this->tempDirs[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
