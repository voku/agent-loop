<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowContextBudget;
use voku\AgentLoop\Workflow\WorkflowContextCommand;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentSession\SessionStore;

final class WorkflowContextCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-context-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.agent-loop/sessions', 0777, true);
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/.agent-loop/recall/ABC-123', 0777, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nnamespace Demo; final class Foo { public function run(): void {} }\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Render a compact context.',
            ['src/Foo.php'],
            ['No source bodies.'],
            ['vendor/bin/phpunit tests/FooTest.php'],
            'lars',
            acceptanceCriteria: [
                'The coding agent can see the required outcome.',
                'Required validation survives unverified context pressure.',
            ],
        );
        $contracts->approve('ABC-123', 'lars');

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', 'context', 'lars');
        $sessions->appendRecord($session, 'decision', 'Keep output bounded', 'Do not load source bodies.');
        $sessions->addCheckpoint($session, 'Map available', 'Indexed source symbols.');

        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => 'ABC-123',
            'selected_guidance' => ['G-001'],
            'selected_constraints' => [['id' => 'C-001']],
        ], JSON_THROW_ON_ERROR));
        mkdir($this->root . '/.agent-loop/map', 0777, true);
        (new IndexWriter())->write((new AgentMapBuilder())->build($this->root, ['src'], []), $this->root . '/.agent-loop/map/php-symbols.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testContextCombinesExistingArtifactsWithoutMutation(): void
    {
        $before = hash_file('sha256', $this->root . '/.agent-loop/sessions/' . $this->sessionId() . '/session.json');
        ob_start();
        $exit = (new WorkflowContextCommand($this->root))->run(['ABC-123']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Render a compact context.', $output);
        self::assertStringContainsString('Acceptance criteria (required, not proof):', $output);
        self::assertStringContainsString('The coding agent can see the required outcome.', $output);
        self::assertStringContainsString(
            'Human explanations: ask (interactive: ask; unattended: skip). Optional model-generated explanation work only; deterministic projections stay available; human authority remains required.',
            $output,
        );
        self::assertStringContainsString(
            'Future work: focus; do not proactively inspect adjacent future work after the current task completes. Never widen the current Contract; follow-up execution requires separate Contract authority.',
            $output,
        );
        self::assertStringContainsString('G-001 (.agent-loop/recall/ABC-123/meta.json)', $output);
        self::assertStringContainsString('Demo\\Foo', $output);
        self::assertSame($before, hash_file('sha256', $this->root . '/.agent-loop/sessions/' . $this->sessionId() . '/session.json'));
    }

    public function testContextProjectsNeverPolicyWithoutChangingHumanAuthority(): void
    {
        file_put_contents($this->root . '/.agent-loop/init.json', json_encode([
            'interaction' => ['human_explanations' => 'never'],
        ], JSON_THROW_ON_ERROR));

        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);
        $rendered = implode("\n", $context['lines']);

        self::assertSame('never', $context['interaction']['human_explanations']);
        self::assertSame('skip', $context['interaction']['interactive_behavior']);
        self::assertSame('skip', $context['interaction']['unattended_behavior']);
        self::assertSame('human_required', $context['interaction']['authority_bearing_decisions']);
        self::assertStringContainsString(
            'Human explanations: never (interactive: skip; unattended: skip). Optional model-generated explanation work only; deterministic projections stay available; human authority remains required.',
            $rendered,
        );
    }

    public function testContextProjectsInvestPolicyWithoutGrantingFollowUpAuthority(): void
    {
        file_put_contents($this->root . '/.agent-loop/init.json', json_encode([
            'workflow' => [
                'future_work' => [
                    'mode' => 'invest',
                    'max_follow_up_slices' => 2,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);
        $rendered = implode("\n", $context['lines']);

        self::assertSame([
            'mode' => 'invest',
            'max_follow_up_slices' => 2,
            'current_contract_scope_expansion' => 'forbidden',
            'follow_up_authority' => 'separate_contract_required',
        ], $context['future_work']);
        self::assertStringContainsString(
            'Future work: invest; after the current task completes, permit bounded future-work reflection and preparation of up to 2 separate follow-up candidate slice(s). Never widen the current Contract; follow-up execution requires separate Contract authority.',
            $rendered,
        );
    }

    public function testContextProjectsCanonicalLifecycleAuthority(): void
    {
        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);
        $rendered = implode("\n", $context['lines']);

        self::assertStringContainsString('Lifecycle authority (agent-loop):', $rendered);
        self::assertStringContainsString('State: incomplete', $rendered);
        self::assertStringContainsString('Next kind: command', $rendered);
        self::assertStringContainsString('Next: agent-loop enter ABC-123', $rendered);
    }

    public function testContextBudgetPreservesLifecycleAuthorityUnderPressure(): void
    {
        $budget = new WorkflowContextBudget(4, 1000);
        $budget->add('authority', 'State: incomplete');
        $budget->add('authority', 'Next kind: command');
        $budget->add('authority', 'Next: agent-loop enter ABC-123');
        $budget->add('candidate_context', 'expanded candidate');
        $budget->add('candidate_navigation', 'ranked navigation lead');
        $budget->finish();

        self::assertContains('State: incomplete', $budget->lines());
        self::assertContains('Next kind: command', $budget->lines());
        self::assertContains('Next: agent-loop enter ABC-123', $budget->lines());
        self::assertNotContains('expanded candidate', $budget->lines());
        self::assertNotContains('ranked navigation lead', $budget->lines());
    }

    public function testContextReprojectsFreshLifecycleAuthorityAfterRunPreparation(): void
    {
        $before = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);
        $beforeManifest = (new RunManifestProjector($this->root))->project('ABC-123');
        $contracts = new TaskContractStore($this->root);
        $contract = $contracts->find('ABC-123');
        self::assertNotNull($contract);
        $sessions = new SessionStore();
        $session = $sessions->load($this->root . '/.agent-loop/sessions', $this->sessionId());

        (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $after = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);
        $afterManifest = (new RunManifestProjector($this->root))->project('ABC-123');
        $afterRendered = implode("\n", $after['lines']);

        self::assertNotSame($beforeManifest->runId, $afterManifest->runId);
        self::assertNotSame($beforeManifest->nextAction, $afterManifest->nextAction);
        self::assertStringContainsString('State: ' . $afterManifest->state, $afterRendered);
        self::assertStringContainsString('Next kind: ' . $afterManifest->nextActionKind, $afterRendered);
        self::assertStringContainsString('Next: ' . $afterManifest->nextAction, $afterRendered);
        self::assertStringNotContainsString('Next: ' . $beforeManifest->nextAction, $afterRendered);
    }

    public function testContextBudgetHasClosedAuthorityByteBoundaries(): void
    {
        $authority = [
            'State: incomplete',
            'Next kind: command',
            'Next: agent-loop enter ABC-123',
        ];
        $authorityBytes = array_sum(array_map(static fn (string $line): int => strlen($line) + 1, $authority));

        $under = new WorkflowContextBudget(3, $authorityBytes - 1);
        $under->add('authority', $authority[0]);
        $under->add('authority', $authority[1]);
        $this->expectException(RuntimeException::class);
        $under->add('authority', $authority[2]);
    }

    public function testContextBudgetPreservesExactAuthorityAndFitsCandidateAtExactRemainder(): void
    {
        $authority = [
            'State: incomplete',
            'Next kind: command',
            'Next: agent-loop enter ABC-123',
        ];
        $authorityBytes = array_sum(array_map(static fn (string $line): int => strlen($line) + 1, $authority));
        $candidate = 'candidate context';
        $candidateBytes = strlen($candidate) + 1;

        $exact = new WorkflowContextBudget(3, $authorityBytes);
        foreach ($authority as $line) {
            $exact->add('authority', $line);
        }
        $exact->finish();
        self::assertSame($authority, $exact->lines());

        $withCandidate = new WorkflowContextBudget(4, $authorityBytes + $candidateBytes);
        foreach ($authority as $line) {
            $withCandidate->add('authority', $line);
        }
        $withCandidate->add('candidate_context', $candidate);
        $withCandidate->finish();

        self::assertSame([...$authority, $candidate], $withCandidate->lines());
    }

    public function testCandidatePruningDoesNotChangeProjectedAuthority(): void
    {
        $authority = [
            'State: incomplete',
            'Next kind: command',
            'Next: agent-loop enter ABC-123',
        ];
        $authorityBytes = array_sum(array_map(static fn (string $line): int => strlen($line) + 1, $authority));
        $omission = 'Omitted: 1 additional candidate_context';
        $budgetBytes = $authorityBytes + strlen($omission) + 1;

        $withoutCandidate = new WorkflowContextBudget(4, $budgetBytes);
        foreach ($authority as $line) {
            $withoutCandidate->add('authority', $line);
        }
        $withoutCandidate->finish();

        $withCandidate = new WorkflowContextBudget(4, $budgetBytes);
        foreach ($authority as $line) {
            $withCandidate->add('authority', $line);
        }
        $withCandidate->add('candidate_context', str_repeat('unverified candidate ', 20));
        $withCandidate->finish();

        self::assertSame(
            $authority,
            array_values(array_filter($withoutCandidate->lines(), static fn (string $line): bool => in_array($line, $authority, true))),
        );
        self::assertSame(
            $authority,
            array_values(array_filter($withCandidate->lines(), static fn (string $line): bool => in_array($line, $authority, true))),
        );
        self::assertNotContains('unverified candidate ', $withCandidate->lines());
    }

    public function testContextReportsOmissionsAndMissingMap(): void
    {
        unlink($this->root . '/.agent-loop/map/php-symbols.json');
        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 12, 512);

        self::assertNotEmpty($context['omitted']);
        self::assertContains('agent-map: index missing (.agent-loop/map/php-symbols.json)', $context['skipped']);
        $rendered = implode("\n", $context['lines']);
        self::assertStringContainsString('[SKIP] agent-map: index missing', $rendered);
        self::assertSame(1, substr_count($rendered, '[SKIP] agent-map: index missing'));
    }

    public function testContextReadsFileSectionWhenRelationCompanionIsUnavailable(): void
    {
        $index = $this->root . '/.agent-loop/map/php-symbols.json';
        $relations = MapArtifactPaths::relationsFileFor($index);
        self::assertFileExists($relations);
        unlink($relations);

        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);

        self::assertStringContainsString('Demo\\Foo', implode("\n", $context['lines']));
        self::assertNotContains('agent-map: index invalid (.agent-loop/map/php-symbols.json)', $context['skipped']);
    }

    public function testContextUsesNavigationFactsFromRecallBundleBeforeLegacyMap(): void
    {
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/facts.json', json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => hash('sha256', 'bundle-test'),
            'facts' => [[
                'id' => 'map.file.src/Foo.php',
                'type' => 'navigation',
                'authority' => 'derived_navigation',
                'source_ref' => '.agent-loop/map/php-symbols.json',
                'scope' => ['src/Foo.php'],
                'payload' => [
                    'path' => 'src/Foo.php',
                    'symbols' => [[
                        'fqn' => 'Demo\\BundleFoo',
                        'kind' => 'class',
                        'line_start' => 7,
                        'line_end' => 9,
                    ]],
                ],
                'conflict_key' => null,
            ]],
        ], JSON_THROW_ON_ERROR));
        unlink($this->root . '/.agent-loop/map/php-symbols.json');

        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);

        self::assertStringContainsString('Demo\\BundleFoo — src/Foo.php:7', implode("\n", $context['lines']));
        self::assertNotContains('agent-map: index missing (.agent-loop/map/php-symbols.json)', $context['skipped']);
    }

    public function testContextProjectsMethodsFromCompiledRecallNavigationFacts(): void
    {
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/facts.json', json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => hash('sha256', 'bundle-test'),
            'facts' => [[
                'id' => 'map.file.src/Foo.php',
                'type' => 'navigation',
                'authority' => 'derived_navigation',
                'source_ref' => '.agent-loop/map/php-symbols.json',
                'scope' => ['src/Foo.php'],
                'payload' => [
                    'path' => 'src/Foo.php',
                    'symbols' => [[
                        'name' => 'BundleFoo',
                        'fqn' => 'Demo\\BundleFoo',
                        'kind' => 'class',
                        'line_start' => 7,
                        'line_end' => 20,
                        'methods' => [[
                            'name' => 'doWork',
                            'line_start' => 12,
                            'line_end' => 18,
                        ]],
                    ]],
                ],
                'conflict_key' => null,
            ]],
        ], JSON_THROW_ON_ERROR));
        unlink($this->root . '/.agent-loop/map/php-symbols.json');

        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);
        $lines = implode("\n", $context['lines']);

        self::assertStringContainsString('Demo\\BundleFoo — src/Foo.php:7', $lines);
        self::assertStringContainsString('BundleFoo::doWork() — src/Foo.php:12', $lines);
    }

    public function testContextRendersSmallKanbanFactWithoutReadingBoardAgain(): void
    {
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/facts.json', json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => hash('sha256', 'bundle-test'),
            'facts' => [[
                'id' => 'kanban.ABC-123',
                'type' => 'kanban',
                'authority' => 'kanban_board',
                'source_ref' => '.agent-loop/todo/cards/ABC-123.md',
                'scope' => ['src/Foo.php'],
                'payload' => [
                    'card' => [
                        'title' => 'Keep the context bounded',
                        'lane' => 'READY',
                        'status' => 'Selected',
                        'next_action' => 'Inspect the sealed facts.',
                    ],
                ],
                'conflict_key' => 'kanban:ABC-123',
            ]],
        ], JSON_THROW_ON_ERROR));

        $context = (new WorkflowContextCommand($this->root))->build('ABC-123', 120, 12000);

        self::assertStringContainsString('Keep the context bounded (READY / Selected)', implode("\n", $context['lines']));
        self::assertStringContainsString('Next: Inspect the sealed facts.', implode("\n", $context['lines']));
    }

    public function testContextBudgetPrefersContractObligationsOverUnverifiedCandidates(): void
    {
        $budget = new WorkflowContextBudget(3, 1000);
        $budget->add('acceptance', 'Required outcome');
        $budget->add('candidate_navigation', 'ranked lead');
        $budget->add('candidate_context', 'expanded candidate');
        $budget->add('validation', 'composer test');
        $budget->finish();

        self::assertContains('Required outcome', $budget->lines());
        self::assertContains('composer test', $budget->lines());
        self::assertNotContains('ranked lead', $budget->lines());
        self::assertNotContains('expanded candidate', $budget->lines());
        self::assertSame(1, $budget->omitted()['candidate_navigation'] ?? 0);
        self::assertSame(1, $budget->omitted()['candidate_context'] ?? 0);
        self::assertStringContainsString('Omitted:', implode("\n", $budget->lines()));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }

    private function sessionId(): string
    {
        $directories = glob($this->root . '/.agent-loop/sessions/*', GLOB_ONLYDIR) ?: [];
        if ($directories === []) {
            self::fail('Expected the context fixture to contain one session.');
        }

        return basename($directories[0]);
    }
}
