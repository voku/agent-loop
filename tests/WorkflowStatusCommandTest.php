<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\GovernedRunManifestProjector;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

final class WorkflowStatusCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-status-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testStatusNamesTheCompleteLifecycleAndOneNextCommandWithoutWritingAnything(): void
    {
        $before = $this->files();

        [$exit, $out] = $this->statusOf('ABC-123');

        self::assertSame(0, $exit);
        foreach ([
            'Board:',
            'Session:',
            'Work brief:',
            'Approval:',
            'Map:',
            'Search index:',
            'Recall:',
            'Edit:',
            'Verification:',
            'Review:',
            'Learning:',
            'Outcome lineage:',
        ] as $stage) {
            self::assertStringContainsString($stage, $out);
        }
        self::assertStringContainsString('Overall:', $out);
        self::assertStringContainsString('Manifest:', $out);
        self::assertStringContainsString('workflow plan ABC-123', $out, 'an empty task must point at plan, not at close');
        self::assertSame($before, $this->files(), 'status is read-only');
    }

    public function testTheNextCommandFollowsTheProjectedLifecycleAsArtifactsAppear(): void
    {
        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        self::assertStringContainsString('workflow approve ABC-123', $this->statusText('ABC-123'));

        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');
        $approved = $this->statusText('ABC-123');
        self::assertStringContainsString('current', $approved);
        self::assertStringContainsString('revision 1 by lars', $approved);
        self::assertStringContainsString('workflow approve ABC-123', $approved, 'an approved brief without a briefing still needs approve to compile recall');

        mkdir($this->root . '/.agent-loop/recall/ABC-123', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/meta.json', '{}');
        self::assertStringContainsString('review blindspots ABC-123', $this->statusText('ABC-123'));

        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString('learning decide', $this->statusText('ABC-123'));
    }

    public function testAnUnverifiedEditBundleIsTheNextThingToDo(): void
    {
        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');
        mkdir($this->root . '/.agent-loop/recall/ABC-123', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/meta.json', '{}');
        mkdir($this->root . '/.agent-loop/edit/ABC-123', 0o775, true);

        $out = $this->statusText('ABC-123');

        self::assertStringContainsString('Verification:', $out);
        self::assertStringContainsString('missing', $out);
        self::assertStringContainsString('edit verify --bundle=.agent-loop/edit/ABC-123', $out);
    }

    public function testAnEphemeralSessionIsShownAsAnExperimentAndAsksToBeClosed(): void
    {
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars', ephemeral: true);

        $out = $this->statusText('ABC-123');

        self::assertStringContainsString('experiment', $out);
        self::assertStringContainsString('ephemeral', $out);
        self::assertStringContainsString('session close ' . $session->id, $out);
    }

    public function testAnUnreadableReviewReportBlocksStatusInsteadOfMasqueradingAsProgress(): void
    {
        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json', '{');

        [$exit, $out] = $this->statusOf('ABC-123');

        self::assertSame(2, $exit);
        self::assertStringContainsString('blocked', $out);
        self::assertStringContainsString('review.report_invalid', $out);
    }

    public function testJsonStatusUsesTheSameProjectionAndReportsPersistedManifestFreshness(): void
    {
        $projector = new GovernedRunManifestProjector($this->root);
        $store = new RunManifestStore($this->root);
        $store->write($projector->project('ABC-123'));

        [$currentExit, $currentOutput] = $this->statusOf('ABC-123', ['--format=json']);
        $current = json_decode($currentOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $currentExit);
        self::assertIsArray($current);
        self::assertIsArray($current['storage'] ?? null);
        self::assertIsArray($current['manifest'] ?? null);
        self::assertSame('current', $current['storage']['state']);
        self::assertSame('legacy_inferred', $current['manifest']['mode']);

        (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        [$staleExit, $staleOutput] = $this->statusOf('ABC-123', ['--format', 'json']);
        $stale = json_decode($staleOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $staleExit);
        self::assertIsArray($stale);
        self::assertIsArray($stale['storage'] ?? null);
        self::assertIsArray($stale['manifest'] ?? null);
        self::assertSame('stale', $stale['storage']['state']);
        self::assertSame('governed', $stale['manifest']['mode']);
    }

    /**
     * @param list<string> $options
     * @return array{0: int, 1: string}
     */
    private function statusOf(string $taskId, array $options = []): array
    {
        ob_start();
        $exit = (new WorkflowStatusCommand($this->root))->run([$taskId, ...$options]);
        $out = (string) ob_get_clean();

        return [$exit, $out];
    }

    private function statusText(string $taskId): string
    {
        [$exit, $out] = $this->statusOf($taskId);
        self::assertSame(0, $exit);

        return $out;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        if (!is_dir($this->root)) {
            return [];
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
