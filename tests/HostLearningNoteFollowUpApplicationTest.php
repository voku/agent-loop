<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;

final class HostLearningNoteFollowUpApplicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-learning-note-application-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning/findings/validated', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCompletedRunRemainsCompleteWhileLearningNoteAuthoringIsOptional(): void
    {
        $taskId = 'LEARN-349-E2E';
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Surface explicit LearningNote work after governed close.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contract = $contracts->approve($taskId, 'fixture-approver');
        $session = (new SessionStore())->create(
            $this->root . '/.agent-loop/sessions',
            $taskId,
            by: 'fixture-agent',
        );
        $run = (new GovernedRunStore($this->root))->prepare(
            $contract,
            $session,
            $this->root . '/.agent-loop/learning',
        );

        $recall = $this->root . '/.agent-loop/recall/' . $taskId;
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'compilation_id' => 'learn-349-e2e-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved command.\n");

        $prepared = $this->finish($taskId);
        self::assertSame(1, $prepared['exit']);
        $review = (new WorkflowReviewReportReader($this->root))->read($taskId);
        self::assertIsString($review['sha256']);

        $findingId = 'finding.2026-09-02.349';
        $learningRoot = WorkflowLearningRoot::forRun($this->root, $run);
        file_put_contents($learningRoot . '/findings/validated/' . $findingId . '.json', json_encode([
            'id' => $findingId,
            'task_id' => $taskId,
            'session' => $session->id,
            'created_at' => '2026-09-02T00:00:00+00:00',
            'created_by' => 'fixture-agent',
            'scope' => ['src/Foo.php'],
            'observation' => 'A solved case should remain reusable without becoming active guidance.',
            'evidence' => [[
                'type' => 'manual_verification',
                'summary' => 'Verified by the focused return-loop fixture.',
            ]],
            'hypothesis' => 'Optional precedent should be discoverable after close.',
            'validated_conclusion' => 'LearningNote authoring can remain downstream of software completion.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'classification' => 'ADD_LEARNING_NOTE',
            'pattern_key' => 'workflow.learning_note_return_loop',
            'validation_case' => [
                'given' => 'A completed software change with an explicit LearningNote-classified Finding.',
                'when' => 'finish closes the governed Run.',
                'then' => 'the host sees optional LearningNote authoring without another close gate.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $closed = $this->finish($taskId, [
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--learning', 'findings_recorded',
            '--learning-reason', 'The solved case is useful precedent but not active guidance.',
            '--by', 'fixture-reviewer',
            '--finding', $findingId,
        ]);

        self::assertSame(0, $closed['exit'], json_encode($closed['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($closed['payload']['complete'] ?? false);
        self::assertSame('none', $closed['payload']['next_action'] ?? null);
        self::assertSame([[
            'kind' => 'learning_note',
            'finding_ids' => [$findingId],
            'skill' => 'agent-learning-note',
        ]], $closed['payload']['optional_follow_ups'] ?? null);
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function finish(string $taskId, array $options = []): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorApplication($this->root))->run(
                'finish',
                [$taskId, '--format=json', ...$options],
            );
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Finish JSON did not decode to an object.');
        }

        return ['exit' => $exit, 'payload' => $payload];
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
