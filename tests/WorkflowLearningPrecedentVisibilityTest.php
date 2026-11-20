<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoop\Tests\Support\ManualProfileLifecycleFixture;

/**
 * A matching LearningNote must reach the planner and the approver while the
 * design is still a candidate, and the agent again once the briefing is compiled.
 *
 * Regression for agent-loop#1091: in the round-2 learning-effect experiment all
 * three governed agents fixed their design in the candidate Contract, Recall
 * compiled the precedent only after approval, and nothing the host returned
 * named it, so no agent saw it.
 */
#[Group('slow')]
final class WorkflowLearningPrecedentVisibilityTest extends TestCase
{
    private const string NOTE_ID = 'learning-note.2026-09-17.c1e5c4';
    private const string FINDING_ID = 'finding.2026-09-16.5c4992';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-precedent-visibility-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/CloseGate.php', "<?php\n\nfinal class CloseGate\n{\n}\n");
        file_put_contents($this->root . '/src/Unrelated.php', "<?php\n\nfinal class Unrelated\n{\n}\n");
        $this->writeFinding();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPrecedentIsNamedBeforeApprovalAndAgainInTheCompiledBriefing(): void
    {
        $this->writeNote(['src/CloseGate.php'], 'src/CloseGate.php');

        $plan = $this->json($this->agentLoop(['workflow', 'plan', 'GATE-1', '--by', 'tester', '--file', 'src/CloseGate.php', '--goal', 'Fix the close gate.', '--validation', 'php -l src/CloseGate.php', '--format=json']));
        self::assertSame('decision_required', $plan['next_action_kind']);
        $this->assertNamesPrecedent($plan, 'candidate_contract');

        $pending = $this->json($this->agentLoop(['enter', 'GATE-1', '--format=json'], expectSuccess: false));
        self::assertSame('decision_required', $pending['next_action_kind']);
        $this->assertNamesPrecedent($pending, 'candidate_contract');

        $text = $this->agentLoop(['enter', 'GATE-1'], expectSuccess: false);
        self::assertStringContainsString('Learning precedent (candidate contract):', $text);
        self::assertStringContainsString('Enumerate every per-plan bundle of the task.', $text);

        $this->agentLoop(['workflow', 'approve', 'GATE-1', '--revision', '1', '--by', 'tester']);
        $approved = $this->json($this->agentLoop(['enter', 'GATE-1', '--format=json']));
        self::assertSame('host_work', $approved['next_action_kind']);
        $this->assertNamesPrecedent($approved, 'compiled_recall');
    }

    public function testCompiledBriefingWithoutFinalSelectionIsReportedAsEligibleNotRendered(): void
    {
        $this->writeNote(['src/CloseGate.php'], 'src/CloseGate.php');
        $this->agentLoop(['workflow', 'plan', 'GATE-3', '--by', 'tester', '--file', 'src/CloseGate.php', '--goal', 'Fix the close gate.', '--validation', 'php -l src/CloseGate.php']);
        $this->agentLoop(['workflow', 'approve', 'GATE-3', '--revision', '1', '--by', 'tester']);
        $compiled = $this->json($this->agentLoop(['enter', 'GATE-3', '--format=json']));
        $projection = $compiled['learning_precedents'] ?? null;
        self::assertIsArray($projection);
        self::assertSame('selected', $projection['status']);

        $report = $this->root . '/.agent-loop/recall/GATE-3/selection-report.json';
        self::assertFileExists($report);
        unlink($report);

        $payload = $this->json($this->agentLoop(['enter', 'GATE-3', '--format=json'], expectSuccess: false));
        $projection = $payload['learning_precedents'] ?? null;
        self::assertIsArray($projection);
        self::assertSame('compiled_recall', $projection['source']);
        self::assertSame('eligible', $projection['status'], 'without the final selection a note must not be reported as rendered');
        self::assertIsString($projection['message']);
        self::assertStringContainsString('final selection is unavailable', $projection['message']);
    }

    public function testUnrelatedScopeNamesNoPrecedent(): void
    {
        $this->writeNote(['src/CloseGate.php'], 'src/CloseGate.php');

        $plan = $this->json($this->agentLoop(['workflow', 'plan', 'OTHER-1', '--by', 'tester', '--file', 'src/Unrelated.php', '--goal', 'Touch something else.', '--validation', 'php -l src/Unrelated.php', '--format=json']));

        self::assertArrayNotHasKey('learning_precedents', $plan);
        self::assertArrayNotHasKey('learning_precedents', $this->json($this->agentLoop(['enter', 'OTHER-1', '--format=json'], expectSuccess: false)));
    }

    public function testMissingNoteEvidenceIsReportedBeforeApprovalInsteadOfAtCompilation(): void
    {
        $this->writeNote(['src/CloseGate.php'], 'src/Deleted.php');

        $plan = $this->json($this->agentLoop(['workflow', 'plan', 'GATE-2', '--by', 'tester', '--file', 'src/CloseGate.php', '--goal', 'Fix the close gate.', '--validation', 'php -l src/CloseGate.php', '--format=json']));

        $precedents = $plan['learning_precedents'] ?? null;
        self::assertIsArray($precedents);
        self::assertSame('blocked', $precedents['status']);
        self::assertSame([], $precedents['precedents']);
        self::assertIsString($precedents['message']);
        self::assertStringContainsString(self::NOTE_ID, $precedents['message']);
    }

    /** @param array<string, mixed> $payload */
    private function assertNamesPrecedent(array $payload, string $source): void
    {
        $projection = $payload['learning_precedents'] ?? null;
        self::assertIsArray($projection);
        self::assertSame($source, $projection['source']);
        self::assertSame('selected', $projection['status']);
        self::assertSame('historical_precedent_not_instruction', $projection['use']);
        self::assertSame([[
            'note_id' => self::NOTE_ID,
            'pattern_key' => 'workflow.close_gate.multi_plan_bundles',
            'title' => 'The close gate must see every edit bundle a multi-plan task produced',
            'guidance' => 'Enumerate every per-plan bundle of the task.',
            'matching_task_files' => ['src/CloseGate.php'],
        ]], $projection['precedents']);
    }

    /** @param list<string> $scope */
    private function writeNote(array $scope, string $evidencePath): void
    {
        $evidence = $this->root . '/' . $evidencePath;
        $note = [
            'schema_version' => '1.0',
            'id' => self::NOTE_ID,
            'pattern_key' => 'workflow.close_gate.multi_plan_bundles',
            'status' => 'active',
            'scope' => $scope,
            'tags' => [],
            'source_findings' => [self::FINDING_ID],
            'source_proposals' => [],
            'validation_case' => ['given' => 'a multi-plan task', 'when' => 'close runs', 'then' => 'every bundle is checked'],
            'repository_evidence' => [[
                'source_ref' => $evidencePath,
                'sha256' => is_file($evidence) ? hash_file('sha256', $evidence) : str_repeat('0', 64),
            ]],
            'content' => [
                'title' => 'The close gate must see every edit bundle a multi-plan task produced',
                'context' => 'One bundle per applied plan.',
                'guidance' => 'Enumerate every per-plan bundle of the task.',
                'why_it_works' => 'The gate judges the real applied plans.',
                'when_to_apply' => 'When changing the edit-verification gate.',
                'when_not_to_apply' => 'For tasks without edit plans.',
                'verification' => 'Regression tests with several bundles.',
                'symptoms' => 'A failed plan closes.',
                'failed_approaches' => [],
                'root_cause' => 'The gate inspects one bundle.',
                'examples' => [],
            ],
            'created_at' => '2026-09-17T00:00:00+00:00',
            'updated_at' => '2026-09-17T00:00:00+00:00',
            'retired_reason' => null,
        ];
        $this->writeJson('.agent-loop/learning/notes/active/' . self::NOTE_ID . '.json', $note);
    }

    private function writeFinding(): void
    {
        $this->writeJson('.agent-loop/learning/findings/validated/' . self::FINDING_ID . '.json', [
            'id' => self::FINDING_ID,
            'task_id' => 'GATE-0',
            'session' => 'gate-0',
            'created_at' => '2026-09-16T05:07:47+00:00',
            'created_by' => 'tester',
            'scope' => [],
            'observation' => 'A multi-plan task could not close although every bundle passed.',
            'hypothesis' => 'The gate resolves one bundle per task.',
            'validated_conclusion' => 'Confirmed: sibling bundles are invisible to the gate.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'internal',
            'evidence' => [['type' => 'runtime_observation', 'summary' => 'Three bundles passed; close failed.']],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $relative, array $data): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    /** @param list<string> $args */
    private function agentLoop(array $args, bool $expectSuccess = true): string
    {
        if ($args[0] === 'enter') {
            ManualProfileLifecycleFixture::beforeEnter($this->root, $args[1]);
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=24575', dirname(__DIR__) . '/bin/agent-loop', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-loop.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($expectSuccess) {
            self::assertSame(0, $exit, implode(' ', $args) . "\n" . $stdout . $stderr);
        }

        return $stdout;
    }

    /** @return array<string, mixed> */
    private function json(string $output): array
    {
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $payload = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $payload[$key] = $value;
        }

        return $payload;
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
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
