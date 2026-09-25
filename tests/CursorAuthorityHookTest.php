<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\PackageResources;

/** @internal */
final class CursorAuthorityHookTest extends TestCase
{
    /**
     * @dataProvider deniedCommandProvider
     */
    public function testAuthorityBearingRemoteMutationsAreDenied(string $command, string $label): void
    {
        $result = $this->runHook(['command' => $command, 'cwd' => '/repo', 'sandbox' => false]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('deny', $result['json']['permission'] ?? null);
        self::assertStringContainsString($label, (string) ($result['json']['user_message'] ?? ''));
    }

    /** @return iterable<string, array{string, string}> */
    public static function deniedCommandProvider(): iterable
    {
        yield 'git push' => ['git push origin main', 'git push'];
        yield 'git with sudo and global option' => ['sudo /usr/bin/git -C /repo push origin main', 'git push'];
        yield 'chained gh pr create' => ['cd /repo && gh pr create --title test', 'gh pr create'];
        yield 'absolute gh pr create' => ['/usr/local/bin/gh pr create --title test', 'gh pr create'];
        yield 'piped gh pr merge' => ['printf x | gh pr merge 123 --squash', 'gh pr merge'];
        yield 'nested shell gh pr merge' => ["sh -c 'gh pr merge 123 --squash'", 'gh pr merge'];
    }

    public function testOrdinaryShellCommandIsAllowed(): void
    {
        $result = $this->runHook(['command' => 'composer test', 'cwd' => '/repo', 'sandbox' => false]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame(['permission' => 'allow'], $result['json']);
    }

    public function testMalformedPayloadFailsSoFailClosedCanBlockTheAction(): void
    {
        $result = $this->runRaw('{broken');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('not valid JSON', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    /**
     * @param array<string, scalar> $payload
     *
     * @return array{exit:int,stdout:string,stderr:string,json:array<string,mixed>}
     */
    private function runHook(array $payload): array
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            self::fail($exception->getMessage());
        }

        $result = $this->runRaw($json);
        try {
            $decoded = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Hook stdout is not valid JSON: ' . $exception->getMessage());
        }
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return [...$result, 'json' => $decoded];
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runRaw(string $stdin): array
    {
        $process = proc_open(
            [PHP_BINARY, PackageResources::cursorAuthorityHook()],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Cursor authority hook fixture.');
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (!is_string($stdout) || !is_string($stderr)) {
            proc_close($process);
            throw new RuntimeException('Unable to read Cursor authority hook fixture output.');
        }

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
