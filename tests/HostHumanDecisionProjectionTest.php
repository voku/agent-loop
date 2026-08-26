<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class HostHumanDecisionProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-human-decision-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create test root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testEnterShowsExactCandidateContractBeforeApproval(): void
    {
        (new TaskContractStore($this->root))->create(
            taskId: 'DECISION-1',
            goal: 'Change the workflow UX without widening authority.',
            scope: ['src/Workflow'],
            nonGoals: ['Do not auto-approve contracts.'],
            validation: ['vendor/bin/phpunit', 'vendor/bin/phpstan analyse'],
            plannedBy: 'codex',
            behaviorAnchors: ['preserve-owner-boundary'],
            operatingPromptManifest: 'skills/operational-prompting/operating-prompts.json',
            operatingPrompts: [[
                'id' => 'blind-spot-analysis',
                'arguments' => ['mode' => 'before-implementation'],
            ]],
            acceptanceCriteria: [
                'Only genuine human authority gates interrupt the developer.',
                'Every human gate shows the exact decision subject.',
            ],
        );

        $result = $this->runBinary(['enter', 'DECISION-1', '--format=json']);
        $payload = $this->json($result['stdout']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame('decision_required', $payload['next_action_kind'] ?? null);
        self::assertSame('contract_approval', $payload['human_decision']['type'] ?? null);
        self::assertSame('human_required', $payload['human_decision']['authority'] ?? null);
        self::assertSame(1, $payload['human_decision']['subject']['contract_revision'] ?? null);
        self::assertSame(
            'Change the workflow UX without widening authority.',
            $payload['human_decision']['subject']['goal'] ?? null,
        );
        self::assertSame(['src/Workflow'], $payload['human_decision']['subject']['scope'] ?? null);
        self::assertSame(
            ['Do not auto-approve contracts.'],
            $payload['human_decision']['subject']['non_goals'] ?? null,
        );
        self::assertSame(
            [
                'Only genuine human authority gates interrupt the developer.',
                'Every human gate shows the exact decision subject.',
            ],
            $payload['human_decision']['subject']['acceptance_criteria'] ?? null,
        );
        self::assertSame(
            ['vendor/bin/phpunit', 'vendor/bin/phpstan analyse'],
            $payload['human_decision']['subject']['validation'] ?? null,
        );
        self::assertSame(
            ['preserve-owner-boundary'],
            $payload['human_decision']['subject']['behavior_anchors'] ?? null,
        );
        self::assertSame(
            'blind-spot-analysis',
            $payload['human_decision']['subject']['operating_prompts'][0]['id'] ?? null,
        );
    }

    public function testFinishSurfacesReviewWhileDelegatingAcknowledgementAndLearning(): void
    {
        [$contract, $run, $session, $snapshot] = $this->prepareReviewDecision('DECISION-2');
        $review = (new WorkflowReviewReportReader($this->root))->read('DECISION-2');
        self::assertSame('unacknowledged', $review['status']);
        self::assertIsString($review['sha256']);

        $result = $this->runBinary(['finish', 'DECISION-2', '--format=json']);
        $payload = $this->json($result['stdout']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame('command_template', $payload['next_action_kind'] ?? null);
        self::assertArrayNotHasKey('human_decision', $payload);
        self::assertStringContainsString('--reviewed-report-sha256 ' . $review['sha256'], $payload['next_action'] ?? '');
        self::assertSame($review['sha256'], $payload['review_presentation']['review_sha256'] ?? null);
        self::assertSame('ok', $payload['review_presentation']['report_status'] ?? null);
        self::assertSame($snapshot->digest, $payload['review_presentation']['implementation_snapshot'] ?? null);
        self::assertSame([], $payload['review_presentation']['findings'] ?? null);
        self::assertTrue($payload['review_presentation']['exists'] ?? false);
        $presentationPath = $payload['review_presentation']['path'] ?? null;
        self::assertIsString($presentationPath);
        self::assertFileExists($this->root . '/' . $presentationPath);

        (new ReviewAcknowledgementStore($this->root))->record(
            $run,
            $contract,
            $snapshot,
            $review['sha256'],
            'codex',
        );

        $learning = $this->runBinary(['finish', 'DECISION-2', '--format=json']);
        $learningPayload = $this->json($learning['stdout']);
        self::assertSame(1, $learning['exit'], $learning['stderr']);
        self::assertSame('command_template', $learningPayload['next_action_kind'] ?? null);
        self::assertArrayNotHasKey('human_decision', $learningPayload);
        self::assertStringContainsString(
            '--learning <no_durable_learning|findings_recorded|follow_up_required>',
            $learningPayload['next_action'] ?? '',
        );
        self::assertStringContainsString('--learning-reason <learning-reason>', $learningPayload['next_action'] ?? '');

        self::assertSame($session->id, $run->sessionId);
    }

    /**
     * @return array{
     *     0: \voku\AgentLoop\Workflow\TaskContract,
     *     1: \voku\AgentLoop\Run\GovernedRun,
     *     2: \voku\AgentSession\Session,
     *     3: ImplementationSnapshot
     * }
     */
    private function prepareReviewDecision(string $taskId): array
    {
        if (!mkdir($this->root . '/src', 0o775, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Unable to create source directory.');
        }
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Prove the review decision projection.',
            ['src'],
            [],
            ['vendor/bin/phpunit'],
            'codex',
        );
        $contract = $contracts->approve($taskId, 'lars');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', $taskId, by: 'codex');
        $run = (new GovernedRunStore($this->root))->prepare(
            $contract,
            $session,
            $this->root . '/.agent-loop/learning',
        );

        $recall = $this->root . '/.agent-loop/recall/' . $taskId;
        if (!mkdir($recall . '/reviews', 0o775, true) && !is_dir($recall . '/reviews')) {
            throw new RuntimeException('Unable to create Recall review directory.');
        }
        file_put_contents(
            $recall . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'compilation_id' => $taskId . '-001',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            25,
            'codex',
            implementationSnapshot: $snapshot->digest,
        );
        file_put_contents(
            $recall . '/reviews/' . $taskId . '.blindspots.json',
            json_encode([
                'version' => 2,
                'task_id' => $taskId,
                'status' => 'ok',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        return [$contract, $run, $session, $snapshot];
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runBinary(array $args): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', ...$args],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-loop binary.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @return array<string, mixed> */
    private function json(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
