<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\FindingRepository;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;

final class WorkflowFinishFindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-finish-finding-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFinishBindsAValidatedFindingToAnAdHocWorkflowTaskWithoutHandWrittenJson(): void
    {
        $taskId = 'ad-hoc-learning-lineage-001';
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Create durable Learning through the owner API.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contract = $contracts->approve($taskId, 'fixture-approver');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', $taskId, by: 'fixture-agent');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $recall = $this->root . '/.agent-loop/recall/' . $taskId;
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'compilation_id' => $taskId . '-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved command.\n");

        $prepared = $this->finish($taskId, ['--format=json']);
        self::assertSame(1, $prepared['exit']);

        $review = (new WorkflowReviewReportReader($this->root))->read($taskId);
        self::assertSame('unacknowledged', $review['status']);
        self::assertNotNull($review['sha256']);

        $closed = $this->finish($taskId, [
            '--format=json',
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--learning', 'findings_recorded',
            '--learning-reason', 'The consumer friction is durable and evidence-backed.',
            '--by', 'fixture-reviewer',
            '--finding-observation', 'Ordinary close-out previously required hand-authored Finding JSON.',
            '--finding-hypothesis', 'Finding persistence should stay behind the Learning owner boundary.',
            '--finding-conclusion', 'finish can create a validated Finding through FindingCreator.',
            '--finding-confidence', 'high',
            '--finding-sensitivity', 'public',
        ]);

        self::assertSame(0, $closed['exit'], json_encode($closed['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($closed['payload']['complete'] ?? false);

        $learningRoot = WorkflowLearningRoot::forRun($this->root, $run);
        $decision = (new RunLearningDecisionStore($learningRoot))->find($run->runId);
        self::assertNotNull($decision);
        self::assertSame(RunLearningDecisionStatus::FINDINGS_RECORDED, $decision->decision);
        self::assertCount(1, $decision->findingIds);

        $findings = (new FindingRepository())->loadValidated($learningRoot);
        $finding = $findings[$decision->findingIds[0]] ?? null;
        self::assertNotNull($finding);
        self::assertSame($taskId, $finding->taskId);
        self::assertSame($session->id, $finding->session);
        self::assertSame(['src/Foo.php'], $finding->scope);
        self::assertSame('finish can create a validated Finding through FindingCreator.', $finding->validatedConclusion);
        self::assertNotSame([], $finding->evidence);
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function finish(string $taskId, array $options): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('finish', [$taskId, ...$options]);
            $stdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!is_string($stdout)) {
            throw new RuntimeException('Unable to capture finish JSON.');
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
