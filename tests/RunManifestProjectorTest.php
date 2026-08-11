<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentSession\LearningDecision;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

final class RunManifestProjectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-run-projector-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testIncompleteLegacyRunDoesNotInventMissingIdentity(): void
    {
        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('task:ABC-123:legacy', $manifest->runId);
        self::assertSame('legacy_inferred', $manifest->mode);
        self::assertSame('incomplete', $manifest->state);
        self::assertSame('missing', $manifest->references['session']['state']);
        self::assertSame('missing', $manifest->references['recall']['state']);
        self::assertSame([], $manifest->disagreements);
        self::assertStringContainsString('workflow plan ABC-123', $manifest->nextAction);
    }

    public function testCompletedRunIsTraceableThroughOwningArtifacts(): void
    {
        [$sessions, $session] = $this->preparedRun('ok');
        $sessions->setStatus($session, SessionStatus::DONE);

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('session:' . $session->id, $manifest->runId);
        self::assertSame('governed', $manifest->mode);
        self::assertSame('complete', $manifest->state);
        self::assertSame('current', $manifest->references['approval']['state']);
        self::assertSame('compiled', $manifest->references['recall']['state']);
        self::assertSame('ok', $manifest->references['review']['state']);
        self::assertSame('no_durable_learning', $manifest->references['learning']['state']);
        self::assertSame('none', $manifest->nextAction);
        self::assertSame([], $manifest->disagreements);
    }

    public function testFailedReviewCannotProduceACompletedRunOrCloseAction(): void
    {
        [$sessions, $session] = $this->preparedRun('fail');
        $sessions->setStatus($session, SessionStatus::DONE);

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('blocked', $manifest->state);
        self::assertSame('fail', $manifest->references['review']['state']);
        self::assertSame('agent-loop review blindspots ABC-123', $manifest->nextAction);
        self::assertSame([], $manifest->disagreements);
    }

    public function testArtifactDisagreementBlocksAFalseGreenProjection(): void
    {
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Detect stale approval.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');

        $path = $session->path . '/work-brief.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['revision'] = 2;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('blocked', $manifest->state);
        self::assertSame('superseded', $manifest->references['approval']['state']);
        self::assertSame('approval.revision_mismatch', $manifest->disagreements[0]['code']);
        self::assertStringContainsString('workflow manifest ABC-123 --format=json', $manifest->nextAction);
    }

    /** @return array{0: SessionStore, 1: Session} */
    private function preparedRun(string $reviewStatus): array
    {
        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Prove the completed projection.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');

        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-001',
                'bundle_sha256' => 'sha256:bundle',
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => $reviewStatus], JSON_THROW_ON_ERROR),
        );
        (new LearningDecisionStore())->decide($session, LearningDecision::NO_DURABLE_LEARNING, 'lars');

        return [$sessions, $session];
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
