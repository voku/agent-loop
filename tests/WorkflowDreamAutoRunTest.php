<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\WorkflowDreamAutoRun;

final class WorkflowDreamAutoRunTest extends TestCase
{
    private string $root;
    private string $learningRoot;
    private string $stateFile;
    private string|false $originalAutorun = false;

    protected function setUp(): void
    {
        // Autorun must be enabled here, whatever the caller exported; the original value comes back in tearDown().
        $this->originalAutorun = getenv('AGENT_LOOP_DREAM_AUTORUN');
        putenv('AGENT_LOOP_DREAM_AUTORUN');
        $this->root = sys_get_temp_dir() . '/agent-loop-dream-auto-' . bin2hex(random_bytes(4));
        $layout = new ProjectLayout($this->root);
        $this->learningRoot = $layout->learningRoot();
        $this->stateFile = $layout->stateRoot() . '/dream/auto.json';
        self::assertTrue(mkdir($this->learningRoot . '/findings/validated', 0o775, true));
        self::assertTrue(mkdir($this->root . '/src', 0o775, true));
        file_put_contents($this->root . '/src/Example.php', "<?php\n");
        // Two independent tasks with one lesson: the smallest corpus Dream turns into a review decision.
        $this->writeFinding('finding.2026-06-01.001', 'TASK-1');
        $this->writeFinding('finding.2026-06-02.001', 'TASK-2');
    }

    protected function tearDown(): void
    {
        putenv($this->originalAutorun === false ? 'AGENT_LOOP_DREAM_AUTORUN' : 'AGENT_LOOP_DREAM_AUTORUN=' . $this->originalAutorun);
        $this->removeTree($this->root);
    }

    public function testFirstCallRunsTheReadOnlyPreviewAndWritesNothingIntoTheLearningRoot(): void
    {
        $before = $this->learningTree();

        $digest = (new WorkflowDreamAutoRun($this->root))->runIfDue();

        self::assertNotNull($digest);
        self::assertTrue($digest['ran']);
        self::assertSame('no previous automatic run', $digest['reason']);
        self::assertGreaterThan(0, $digest['reviewDecisions'], 'the fixture must produce a review decision');
        self::assertSame($before, $this->learningTree(), 'the preview must not create, approve or change guidance');
        self::assertFileExists($this->stateFile);
        self::assertSame([], glob($this->learningRoot . '/proposals/candidate/*.json') ?: []);
    }

    public function testUnchangedInputsDoNotRunAgainButKeepReportingPendingDecisions(): void
    {
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $autoRun->runIfDue();

        $second = $autoRun->runIfDue();

        self::assertNotNull($second);
        self::assertFalse($second['ran']);
        self::assertSame('pending', $second['reason']);
        self::assertGreaterThan(0, $second['reviewDecisions']);
    }

    public function testChangedLearningInputsMakeDreamDueAgain(): void
    {
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $autoRun->runIfDue();
        $this->writeFinding('finding.2026-06-03.001', 'TASK-3');

        $digest = $autoRun->runIfDue();

        self::assertNotNull($digest);
        self::assertTrue($digest['ran']);
        self::assertSame('Learning inputs changed since the last run', $digest['reason']);
    }

    public function testChangedProjectGuidanceMakesGuidanceReviewDueWithoutChangingLearning(): void
    {
        file_put_contents($this->root . '/AGENTS.md', "# Guidance\n\nKeep changes small.\n");
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $before = $this->learningTree();
        $autoRun->runIfDue();

        file_put_contents($this->root . '/AGENTS.md', "# Guidance\n\nKeep changes small and verified.\n");
        $digest = $autoRun->runIfDue();

        self::assertNotNull($digest);
        self::assertTrue($digest['ran']);
        self::assertSame('project guidance changed since the last run', $digest['reason']);
        self::assertSame($before, $this->learningTree(), 'detecting guidance drift must remain read-only');
    }

    public function testUnchangedProjectGuidanceDoesNotMakeDreamDueAgain(): void
    {
        file_put_contents($this->root . '/AGENTS.md', "# Guidance\n\nKeep changes small.\n");
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $autoRun->runIfDue();

        $digest = $autoRun->runIfDue();

        self::assertNotNull($digest);
        self::assertFalse($digest['ran']);
        self::assertSame('pending', $digest['reason']);
    }

    public function testOnlyTouchingMtimesDoesNotMakeDreamDue(): void
    {
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $autoRun->runIfDue();
        touch($this->learningRoot . '/findings/validated/finding.2026-06-01.001.json', time() + 100);

        $digest = $autoRun->runIfDue();

        self::assertNotNull($digest);
        self::assertFalse($digest['ran'], 'a checkout rewrites mtimes; only content may trigger Dream');
    }

    public function testAddingAndRemovingProjectMemoryMakesDreamDue(): void
    {
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $autoRun->runIfDue();
        $before = $this->learningTree();

        file_put_contents($this->root . '/MEMORY.md', "# Memory\n\nVerify the affected behavior.\n");
        $added = $autoRun->runIfDue();
        self::assertNotNull($added);
        self::assertTrue($added['ran']);
        self::assertSame('project guidance changed since the last run', $added['reason']);

        unlink($this->root . '/MEMORY.md');
        $removed = $autoRun->runIfDue();
        self::assertNotNull($removed);
        self::assertTrue($removed['ran']);
        self::assertSame('project guidance changed since the last run', $removed['reason']);
        self::assertSame($before, $this->learningTree());
    }

    public function testGuidanceMtimeAndUnrelatedContentDoNotMakeDreamDue(): void
    {
        file_put_contents($this->root . '/AGENTS.md', "# Guidance\n");
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $autoRun->runIfDue();

        touch($this->root . '/AGENTS.md', time() + 100);
        file_put_contents($this->root . '/src/Example.php', "<?php\n// Changed implementation.\n");

        $digest = $autoRun->runIfDue();
        self::assertNotNull($digest);
        self::assertFalse($digest['ran']);
    }

    public function testLegacyStateWithoutGuidanceFingerprintIsRefreshedOnce(): void
    {
        $autoRun = new WorkflowDreamAutoRun($this->root);
        $autoRun->runIfDue();
        $state = json_decode((string) file_get_contents($this->stateFile), true, flags: JSON_THROW_ON_ERROR);
        unset($state['guidance_fingerprint']);
        file_put_contents($this->stateFile, json_encode($state, JSON_THROW_ON_ERROR));

        $refreshed = $autoRun->runIfDue();
        self::assertNotNull($refreshed);
        self::assertTrue($refreshed['ran']);
        self::assertSame('project guidance changed since the last run', $refreshed['reason']);
        $unchanged = $autoRun->runIfDue();
        self::assertNotNull($unchanged);
        self::assertFalse($unchanged['ran']);
    }

    public function testAgeLimitMakesDreamDueEvenWithoutInputChanges(): void
    {
        $now = 1_800_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $autoRun = new WorkflowDreamAutoRun($this->root, $clock);
        $autoRun->runIfDue();

        $now += WorkflowDreamAutoRun::MAX_AGE_SECONDS - 1;
        $justBefore = $autoRun->runIfDue();
        $now += 1;
        $atLimit = $autoRun->runIfDue();

        self::assertNotNull($justBefore);
        self::assertFalse($justBefore['ran']);
        self::assertNotNull($atLimit);
        self::assertTrue($atLimit['ran']);
        self::assertSame('last run is older than 7 days', $atLimit['reason']);
    }

    public function testCorruptStateCountsAsNoPreviousRun(): void
    {
        self::assertTrue(mkdir(dirname($this->stateFile), 0o775, true));
        file_put_contents($this->stateFile, '{"fingerprint": 42');

        $digest = (new WorkflowDreamAutoRun($this->root))->runIfDue();

        self::assertNotNull($digest);
        self::assertTrue($digest['ran']);
        self::assertIsArray(json_decode((string) file_get_contents($this->stateFile), true), 'the state file is rewritten as valid JSON');
    }

    public function testOptOutSwitchesItOffWithoutTouchingAnything(): void
    {
        putenv('AGENT_LOOP_DREAM_AUTORUN=0');

        self::assertNull((new WorkflowDreamAutoRun($this->root))->runIfDue());
        self::assertFileDoesNotExist($this->stateFile);
    }

    public function testRepositoryWithoutLearningRootIsLeftAlone(): void
    {
        $empty = sys_get_temp_dir() . '/agent-loop-dream-auto-empty-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($empty, 0o775, true));

        try {
            self::assertNull((new WorkflowDreamAutoRun($empty))->runIfDue());
            self::assertFileDoesNotExist((new ProjectLayout($empty))->stateRoot() . '/dream/auto.json');
        } finally {
            $this->removeTree($empty);
        }
    }

    public function testSessionStartReportsTheAutomaticRunAndSubagentStartDoesNot(): void
    {
        $hook = new AgentDisciplineHook($this->root);

        $session = $hook->contextOutput('SessionStart', json_encode(['hook_event_name' => 'SessionStart'], JSON_THROW_ON_ERROR));
        $subagent = $hook->contextOutput('SubagentStart', json_encode(['hook_event_name' => 'SubagentStart'], JSON_THROW_ON_ERROR));

        $context = $session['hookSpecificOutput']['additionalContext'];
        self::assertStringContainsString('## Agent Loop Dream', $context);
        self::assertStringContainsString('ran the read-only Dream preview automatically (no previous automatic run)', $context);
        self::assertStringContainsString('the preview wrote nothing into the Learning root', $context);
        self::assertStringNotContainsString('## Agent Loop Dream', $subagent['hookSpecificOutput']['additionalContext']);
    }

    private function writeFinding(string $id, string $taskId): void
    {
        file_put_contents($this->learningRoot . '/findings/validated/' . $id . '.json', json_encode([
            'id' => $id,
            'task_id' => $taskId,
            'session' => 'session.' . $taskId,
            'created_at' => '2026-06-01T00:00:00+00:00',
            'created_by' => 'tester',
            'scope' => ['src'],
            'observation' => 'Observation is concrete.',
            'evidence' => [['type' => 'file_reference', 'path' => 'src/Example.php', 'line' => 1]],
            'hypothesis' => 'Hypothesis is distinct.',
            'validated_conclusion' => 'Conclusion is validated.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'pattern_key' => 'loop.dream',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> relative path => content hash of every file under the Learning root */
    private function learningTree(): array
    {
        $tree = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->learningRoot, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile()) {
                $tree[substr($file->getPathname(), strlen($this->learningRoot))] = (string) hash_file('sha256', $file->getPathname());
            }
        }
        ksort($tree);

        return $tree;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
