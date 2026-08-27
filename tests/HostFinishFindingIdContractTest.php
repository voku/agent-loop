<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

final class HostFinishFindingIdContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-finish-existing-finding-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testJsonFinishRecordsMultipleExistingFindingIds(): void
    {
        [$run, $session, $reviewSha256] = $this->prepareReview('FINDING-JSON');
        $findingIds = $this->createFindings($run, $session, 2);

        $result = $this->finishJson('FINDING-JSON', [
            '--reviewed-report-sha256', $reviewSha256,
            '--learning', 'findings_recorded',
            '--learning-reason', 'Two existing durable findings explain the close-out.',
            '--by', 'fixture-reviewer',
            '--finding', $findingIds[0],
            '--finding', $findingIds[1],
        ]);

        self::assertSame(0, $result['exit'], json_encode($result['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($result['payload']['complete'] ?? false);
        self::assertSame('accepted', $result['payload']['mutation_status'] ?? null);

        $decision = (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($run->runId);
        self::assertNotNull($decision);
        self::assertSame($findingIds, $decision->findingIds);
    }

    public function testTextFinishRecordsMultipleExistingFindingIds(): void
    {
        [$run, $session, $reviewSha256] = $this->prepareReview('FINDING-TEXT');
        $findingIds = $this->createFindings($run, $session, 2);

        ob_start();
        try {
            $exit = (new HostFrontDoorApplication($this->root))->run('finish', [
                'FINDING-TEXT',
                '--reviewed-report-sha256', $reviewSha256,
                '--learning', 'findings_recorded',
                '--learning-reason', 'The text front door must preserve existing finding ids too.',
                '--by', 'fixture-reviewer',
                '--finding', $findingIds[0],
                '--finding', $findingIds[1],
            ]);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, $stdout);
        self::assertStringContainsString('Complete: yes', $stdout);

        $decision = (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($run->runId);
        self::assertNotNull($decision);
        self::assertSame($findingIds, $decision->findingIds);
    }

    public function testJsonFinishMakesRefusedFindingsRecordedMutationExplicit(): void
    {
        [, , $reviewSha256] = $this->prepareReview('FINDING-REFUSED');

        $result = $this->finishJson('FINDING-REFUSED', [
            '--reviewed-report-sha256', $reviewSha256,
            '--learning', 'findings_recorded',
            '--learning-reason', 'This intentionally omits the required finding id.',
            '--by', 'fixture-reviewer',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertFalse($result['payload']['complete'] ?? true);
        self::assertSame('refused', $result['payload']['mutation_status'] ?? null);
        self::assertSame('finish.closeout_failed', $result['payload']['error']['code'] ?? null);
        self::assertStringContainsString('finding id', (string) ($result['payload']['error']['message'] ?? ''));
    }

    /** @return array{0: GovernedRun, 1: Session, 2: string} */
    private function prepareReview(string $taskId): array
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Exercise the host finish Learning contract.',
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
            'compilation_id' => strtolower($taskId) . '-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved command.\n");

        $prepared = $this->finishJson($taskId);
        self::assertSame(1, $prepared['exit']);

        $review = (new WorkflowReviewReportReader($this->root))->read($taskId);
        self::assertSame('unacknowledged', $review['status']);
        self::assertIsString($review['sha256']);

        return [$run, $session, $review['sha256']];
    }

    /**
     * @return list<string>
     */
    private function createFindings(GovernedRun $run, Session $session, int $count): array
    {
        $creator = new FindingCreator();
        $root = WorkflowLearningRoot::forRun($this->root, $run);
        $ids = [];
        for ($index = 1; $index <= $count; ++$index) {
            $created = $creator->createValidated(
                root: $root,
                taskId: $run->taskId,
                session: $session->id,
                createdBy: 'fixture-agent',
                scope: ['src/Foo.php'],
                observation: 'Existing durable finding observation ' . $index . '.',
                evidence: [[
                    'type' => 'runtime_observation',
                    'summary' => 'Fixture evidence ' . $index . '.',
                ]],
                hypothesis: 'Existing finding ids should remain reusable.',
                validatedConclusion: 'finish must forward existing finding id ' . $index . '.',
                confidence: 'high',
                sensitivity: 'public',
            );
            $ids[] = $created->finding->id;
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function finishJson(string $taskId, array $options = []): array
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
