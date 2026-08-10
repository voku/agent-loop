<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

/** @internal */
final class AgentMapDiscoveryBinaryTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testRoutesDiscoveryAndPreservesLegacyPathCoupling(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-map-discovery-' . bin2hex(random_bytes(6));
        mkdir($root . '/legacy/ui', 0o775, true);
        mkdir($root . '/legacy/domain', 0o775, true);

        $frontSource = "<?php\n\nfinal class Front\n{\n    public function handle(): void {}\n}\n";
        $serviceSource = "<?php\n\nfinal class Service\n{\n    public function run(): void {}\n}\n";
        file_put_contents($root . '/legacy/ui/Front.php', $frontSource);
        file_put_contents($root . '/legacy/domain/Service.php', $serviceSource);

        $front = new SymbolEntry(
            'class',
            'Front',
            'Front',
            3,
            6,
            [new MethodEntry('handle', 'public', 5, 5)],
        );
        $service = new SymbolEntry(
            'class',
            'Service',
            'Service',
            3,
            6,
            [new MethodEntry('run', 'public', 5, 5)],
        );

        $map = new AgentMapIndex(
            '2.0',
            $root,
            'test',
            [
                new FileEntry('legacy/ui/Front.php', 'sha256:' . hash('sha256', $frontSource), '', [$front]),
                new FileEntry('legacy/domain/Service.php', 'sha256:' . hash('sha256', $serviceSource), '', [$service]),
            ],
            [
                RelationEntry::create(
                    'method:Front::handle',
                    'calls',
                    ['method:Service::run'],
                    'legacy/ui/Front.php',
                    5,
                    5,
                    'phpstan_resolved',
                ),
            ],
        );
        (new IndexWriter())->write($map, $root . '/.agent-map/php-symbols.json');

        try {
            [$exit, $stdout, $stderr] = $this->runBinary(['map', 'discover', '--format=json'], $root);

            self::assertSame(0, $exit, $stderr);
            self::assertSame('', trim($stderr));

            /** @var array<string, mixed> $payload */
            $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('discover', $payload['type'] ?? null);
            self::assertSame([], $payload['namespace_coupling'] ?? null);
            self::assertContains([
                'from' => 'legacy/ui',
                'to' => 'legacy/domain',
                'links' => 1,
                'uncertain_links' => 0,
            ], $payload['directory_coupling'] ?? []);
            self::assertContains([
                'from' => 'legacy/ui/Front.php',
                'to' => 'legacy/domain/Service.php',
                'links' => 1,
                'uncertain_links' => 0,
            ], $payload['file_coupling'] ?? []);
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runBinary(array $arguments, string $cwd): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop'], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-loop binary.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to read agent-loop binary output.');
        }

        return [$exit, $stdout, $stderr];
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
