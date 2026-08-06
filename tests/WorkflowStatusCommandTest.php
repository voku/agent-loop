<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

    public function testStatusNamesEveryStageAndTheSingleNextCommandWithoutWritingAnything(): void
    {
        $before = $this->files();

        $out = $this->statusOf('ABC-123');

        // The point of the view is that one read answers "where am I", so every stage is named even
        // when nothing exists yet.
        foreach (['Session:', 'Work brief:', 'Recall:', 'Edit bundle:', 'Review:', 'Learning:'] as $stage) {
            self::assertStringContainsString($stage, $out);
        }
        self::assertStringContainsString('workflow plan ABC-123', $out, 'an empty task must point at plan, not at close');
        self::assertSame($before, $this->files(), 'status is read-only');
    }

    public function testTheNextCommandFollowsTheLifecycleAsArtifactsAppear(): void
    {
        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/session_plan', 'ABC-123', by: 'lars');
        self::assertStringContainsString('workflow approve ABC-123', $this->statusOf('ABC-123'));

        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');
        $approved = $this->statusOf('ABC-123');
        self::assertStringContainsString('approved', $approved);
        self::assertStringContainsString('revision 1 by lars', $approved);
        self::assertStringContainsString('workflow approve ABC-123', $approved, 'an approved brief without a briefing still needs approve to compile recall');

        mkdir($this->root . '/recall/ABC-123', 0o775, true);
        file_put_contents($this->root . '/recall/ABC-123/meta.json', '{}');
        self::assertStringContainsString('review blindspots ABC-123', $this->statusOf('ABC-123'));

        mkdir($this->root . '/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString('learning decide', $this->statusOf('ABC-123'));
    }

    public function testAnUnverifiedEditBundleIsTheNextThingToDo(): void
    {
        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/session_plan', 'ABC-123', by: 'lars');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');
        mkdir($this->root . '/recall/ABC-123', 0o775, true);
        file_put_contents($this->root . '/recall/ABC-123/meta.json', '{}');
        mkdir($this->root . '/.agent-loop/edit/ABC-123', 0o775, true);

        $out = $this->statusOf('ABC-123');

        self::assertStringContainsString('unverified', $out);
        self::assertStringContainsString('edit verify --bundle=.agent-loop/edit/ABC-123', $out);
    }

    public function testAnEphemeralSessionIsShownAsAnExperimentAndAsksToBeClosed(): void
    {
        (new SessionStore())->create($this->root . '/session_plan', 'ABC-123', by: 'lars', ephemeral: true);

        $out = $this->statusOf('ABC-123');

        self::assertStringContainsString('ephemeral', $out);
        self::assertStringContainsString('repository gates ignore it', $out);
        self::assertStringContainsString('session close', $out);
    }

    public function testAnUnreadableReviewReportIsReportedAsInvalidRatherThanAsProgress(): void
    {
        mkdir($this->root . '/recall/ABC-123/reviews', 0o775, true);
        file_put_contents($this->root . '/recall/ABC-123/reviews/ABC-123.blindspots.json', '{');

        self::assertStringContainsString('invalid', $this->statusOf('ABC-123'));
    }

    private function statusOf(string $taskId): string
    {
        ob_start();
        $exit = (new WorkflowStatusCommand($this->root))->run([$taskId]);
        $out = (string) ob_get_clean();
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
