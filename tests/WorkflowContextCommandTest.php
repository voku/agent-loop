<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowContextCommand;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

final class WorkflowContextCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-context-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/session_plan', 0777, true);
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/recall/ABC-123', 0777, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nnamespace Demo; final class Foo { public function run(): void {} }\n");

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/session_plan', 'ABC-123', 'context', 'lars');
        $sessions->appendRecord($session, 'decision', 'Keep output bounded', 'Do not load source bodies.');
        $sessions->addCheckpoint($session, 'Map available', 'Indexed source symbols.');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Render a compact context.', ['src/Foo.php'], ['No source bodies.'], ['vendor/bin/phpunit tests/FooTest.php']);
        $briefs->approve($session, 'lars');

        file_put_contents($this->root . '/recall/ABC-123/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => 'ABC-123',
            'selected_guidance' => ['G-001'],
            'selected_constraints' => [['id' => 'C-001']],
        ], JSON_THROW_ON_ERROR));
        mkdir($this->root . '/.agent-map', 0777, true);
        (new IndexWriter())->write((new AgentMapBuilder())->build($this->root, ['src'], []), $this->root . '/.agent-map/php-symbols.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testContextCombinesExistingArtifactsWithoutMutation(): void
    {
        $before = hash_file('sha256', $this->root . '/session_plan/' . $this->sessionId() . '/session.json');
        ob_start();
        $exit = (new WorkflowContextCommand($this->root))->run(['ABC-123']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Render a compact context.', $output);
        self::assertStringContainsString('G-001 (recall/ABC-123/meta.json)', $output);
        self::assertStringContainsString('Demo\\Foo', $output);
        self::assertSame($before, hash_file('sha256', $this->root . '/session_plan/' . $this->sessionId() . '/session.json'));
    }

    public function testContextReportsOmissionsAndMissingMap(): void
    {
        unlink($this->root . '/.agent-map/php-symbols.json');
        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', null, 12, 512);

        self::assertNotEmpty($context['omitted']);
        self::assertContains('agent-map: index missing (.agent-map/php-symbols.json)', $context['skipped']);
        self::assertStringContainsString('[SKIP] agent-map: index missing', implode("\n", $context['lines']));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }

    private function sessionId(): string
    {
        $directories = glob($this->root . '/session_plan/*', GLOB_ONLYDIR) ?: [];
        if ($directories === []) {
            self::fail('Expected the context fixture to contain one session.');
        }

        return basename($directories[0]);
    }
}
