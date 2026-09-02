<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostLearningNoteFollowUpProjector;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentSession\SessionStore;

final class HostLearningNoteFollowUpProjectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-learning-note-follow-up-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning/findings/validated', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testProjectsOnlyExplicitAddLearningNoteFindingsFromTheCurrentRunDecision(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'LEARN-349',
            'Expose optional LearningNote authoring without making it a close gate.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contract = $contracts->approve('LEARN-349', 'fixture-approver');
        $session = (new SessionStore())->create(
            $this->root . '/.agent-loop/sessions',
            'LEARN-349',
            by: 'fixture-agent',
        );
        $run = (new GovernedRunStore($this->root))->prepare(
            $contract,
            $session,
            $this->root . '/.agent-loop/learning',
        );
        $learningRoot = WorkflowLearningRoot::forRun($this->root, $run);

        $noteFinding = 'finding.2026-09-02.001';
        $skillFinding = 'finding.2026-09-02.002';
        $this->writeFinding($learningRoot, $noteFinding, 'ADD_LEARNING_NOTE', 'workflow.optional_precedent');
        $this->writeFinding($learningRoot, $skillFinding, 'UPDATE_SKILL', 'workflow.existing_skill');

        (new RunLearningDecisionStore($learningRoot))->record(
            $run->runId,
            RunLearningDecisionStatus::FINDINGS_RECORDED,
            'fixture-agent',
            'Two validated findings were recorded.',
            [$skillFinding, $noteFinding],
        );

        self::assertSame([[
            'kind' => 'learning_note',
            'finding_ids' => [$noteFinding],
            'skill' => 'agent-learning-note',
        ]], (new HostLearningNoteFollowUpProjector($this->root))->project('LEARN-349'));
    }

    public function testNoExplicitLearningNoteClassificationMeansNoOptionalFollowUp(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'LEARN-349-NONE',
            'Do not invent LearningNote work.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contract = $contracts->approve('LEARN-349-NONE', 'fixture-approver');
        $session = (new SessionStore())->create(
            $this->root . '/.agent-loop/sessions',
            'LEARN-349-NONE',
            by: 'fixture-agent',
        );
        $run = (new GovernedRunStore($this->root))->prepare(
            $contract,
            $session,
            $this->root . '/.agent-loop/learning',
        );
        $learningRoot = WorkflowLearningRoot::forRun($this->root, $run);
        $findingId = 'finding.2026-09-02.003';
        $this->writeFinding($learningRoot, $findingId, 'IGNORE', 'workflow.task_local');
        (new RunLearningDecisionStore($learningRoot))->record(
            $run->runId,
            RunLearningDecisionStatus::FINDINGS_RECORDED,
            'fixture-agent',
            'The finding is not a LearningNote candidate.',
            [$findingId],
        );

        self::assertSame([], (new HostLearningNoteFollowUpProjector($this->root))->project('LEARN-349-NONE'));
    }

    private function writeFinding(string $learningRoot, string $id, string $classification, string $patternKey): void
    {
        $path = $learningRoot . '/findings/validated/' . $id . '.json';
        file_put_contents($path, json_encode([
            'id' => $id,
            'task_id' => str_contains($id, '.003') ? 'LEARN-349-NONE' : 'LEARN-349',
            'session' => 'fixture-session',
            'created_at' => '2026-09-02T00:00:00+00:00',
            'created_by' => 'fixture-agent',
            'scope' => ['src/Foo.php'],
            'observation' => 'A bounded validated observation.',
            'evidence' => [[
                'type' => 'manual_verification',
                'summary' => 'Verified in the focused fixture.',
            ]],
            'hypothesis' => 'The finding may be reusable.',
            'validated_conclusion' => 'The owner classification decides the durable route.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'classification' => $classification,
            'pattern_key' => $patternKey,
            'validation_case' => [
                'given' => 'A completed governed run with a validated Finding.',
                'when' => 'The host projects optional post-close Learning work.',
                'then' => 'Only explicit ADD_LEARNING_NOTE findings are surfaced.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
