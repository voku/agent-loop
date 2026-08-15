<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitPathsCommand;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;

final class AgentFacingToonOutputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-toon-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testInitPathsToonPreservesTheJsonProjectionWithFewerBytes(): void
    {
        [$jsonExit, $json] = $this->capture(fn (): int => (new InitPathsCommand($this->root))->run(['--format=json']));
        [$toonExit, $toon] = $this->capture(fn (): int => (new InitPathsCommand($this->root))->run(['--format=toon']));

        self::assertSame(0, $jsonExit);
        self::assertSame(0, $toonExit);

        $jsonPayload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $toonPayload = Toon::decode($toon);
        self::assertIsArray($jsonPayload);
        self::assertIsArray($toonPayload);
        self::assertEquals($jsonPayload, $toonPayload);
        self::assertLessThan(strlen($json), strlen($toon));
    }

    public function testWorkflowStatusToonPreservesMachineMeaningWithFewerBytes(): void
    {
        [$jsonExit, $json] = $this->capture(fn (): int => (new WorkflowStatusCommand($this->root))->run(['ABC-123', '--format=json']));
        [$toonExit, $toon] = $this->capture(fn (): int => (new WorkflowStatusCommand($this->root))->run(['ABC-123', '--format=toon']));

        self::assertSame(0, $jsonExit);
        self::assertSame(0, $toonExit);

        $jsonPayload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $toonPayload = Toon::decode($toon);
        self::assertIsArray($jsonPayload);
        self::assertIsArray($toonPayload);
        self::assertEquals($jsonPayload, $toonPayload);
        self::assertLessThan(strlen($json), strlen($toon));
    }

    /**
     * @param callable(): int $run
     * @return array{0: int, 1: string}
     */
    private function capture(callable $run): array
    {
        ob_start();
        $exit = $run();
        $output = (string) ob_get_clean();

        return [$exit, $output];
    }
}
