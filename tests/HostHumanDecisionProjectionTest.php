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
        self::assertSame(
            'skills/operational-prompting/operating-prompts.json',
            $payload['human_decision']['subject']['operating_prompt_manifest'] ?? null,
        );
        $text = $this->runBinary(['enter', 'DECISION-1']);
        self::assertSame(1, $text['exit'], $text['stderr']);
        self::assertStringContainsString('Operating prompt manifest:', $text['stdout']);
        self::assertStringContainsString('skills/operational-prompting/operating-prompts.json', $text['stdout']);
        self::assertStringContainsString('blind-spot-analysis {"mode":"before-implementation"}', $text['stdout']);
    }

    public function testEnterTextShowsOptionsAndConfiguredGermanDecisionLabels(): void
    {
        if (!mkdir($this->root . '/.agent-loop', 0o775, true) && !is_dir($this->root . '/.agent-loop')) {
            throw new RuntimeException('Unable to create config directory.');
        }
        file_put_contents($this->root . '/.agent-loop/init.json', json_encode([
            'prompting' => ['language' => 'de-DE'],
            'interaction' => ['decision_presentation' => 'cli'],
        ], JSON_THROW_ON_ERROR));
        (new TaskContractStore($this->root))->create(
            taskId: 'DECISION-DE',
            goal: 'Den Status in der Oberfläche anzeigen.',
            scope: ['src/Workflow'],
            nonGoals: ['Keine automatische Freigabe.'],
            validation: ['vendor/bin/phpunit'],
            plannedBy: 'codex',
            acceptanceCriteria: ['Die Freigabe gilt nur für diesen Scope.'],
        );

        $result = $this->runBinary(['enter', 'DECISION-DE']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Entscheidung erforderlich: Contract-Freigabe', $result['stdout']);
        self::assertStringContainsString('Ziel:', $result['stdout']);
        self::assertStringContainsString('Ausgeschlossen:', $result['stdout']);
        self::assertStringContainsString('Abnahmekriterien:', $result['stdout']);
        self::assertStringContainsString('Mögliche Entscheidungen:', $result['stdout']);
        self::assertStringContainsString('Änderungen anfordern:', $result['stdout']);
        self::assertStringContainsString('Es gibt keine separate Ablehnungsaktion', $result['stdout']);
        self::assertStringContainsString('Freigabeaktion: agent-loop workflow approve DECISION-DE --revision 1 --by <named-actor>', $result['stdout']);
    }

    public function testRevisedContractShowsTheDifferenceFromThePreviouslyApprovedRevision(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'DECISION-REVISION',
            'Keep the original goal.',
            ['src/Original.php'],
            ['Do not change tests.'],
            ['vendor/bin/phpunit --testsuite unit'],
            'planner',
            acceptanceCriteria: ['Existing behavior remains stable.'],
        );
        $contracts->approve('DECISION-REVISION', 'project-owner', 1);
        $contracts->revise(
            'DECISION-REVISION',
            'Update the goal and add regression coverage.',
            ['src/Original.php', 'tests/OriginalTest.php'],
            ['Do not add dependencies.'],
            ['vendor/bin/phpunit'],
            'planner',
            acceptanceCriteria: ['Existing behavior remains stable.', 'The regression is covered.'],
        );

        $json = $this->runBinary(['enter', 'DECISION-REVISION', '--format=json']);
        $payload = $this->json($json['stdout']);
        $delta = $payload['human_decision']['subject']['revision_delta'] ?? null;
        self::assertSame(1, $json['exit'], $json['stderr']);
        self::assertSame(1, $delta['from_revision'] ?? null);
        self::assertSame(2, $delta['to_revision'] ?? null);
        self::assertSame('project-owner', $delta['previous_approved_by'] ?? null);
        self::assertSame('Keep the original goal.', $delta['previous_goal'] ?? null);
        self::assertSame(['tests/OriginalTest.php'], $delta['lists']['Scope']['added'] ?? null);
        self::assertSame(['Do not change tests.'], $delta['lists']['Non-goals']['removed'] ?? null);
        self::assertSame(['Do not add dependencies.'], $delta['lists']['Non-goals']['added'] ?? null);

        $text = $this->runBinary(['enter', 'DECISION-REVISION']);

        self::assertSame(1, $text['exit'], $text['stderr']);
        self::assertStringContainsString('Changes since revision 1 (new revision 2):', $text['stdout']);
        self::assertStringContainsString('Previous revision approved by: project-owner', $text['stdout']);
        self::assertStringContainsString('Previous goal: Keep the original goal.', $text['stdout']);
        self::assertStringContainsString('+ tests/OriginalTest.php', $text['stdout']);
        self::assertStringContainsString('- Do not change tests.', $text['stdout']);
        self::assertStringContainsString('+ Do not add dependencies.', $text['stdout']);
    }

    public function testUnapprovedIntermediateRevisionsCompareToLastApprovedContract(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'DECISION-MULTI',
            'Original approved goal.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );
        $contracts->approve('DECISION-MULTI', 'project-owner', 1);
        $contracts->revise(
            'DECISION-MULTI',
            'First draft.',
            ['src/A.php', 'src/B.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );
        $contracts->revise(
            'DECISION-MULTI',
            'Current draft.',
            ['src/A.php', 'src/B.php', 'tests/CTest.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );

        $result = $this->runBinary(['enter', 'DECISION-MULTI', '--format=json']);
        $payload = $this->json($result['stdout']);
        $delta = $payload['human_decision']['subject']['revision_delta'] ?? null;

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame(1, $delta['from_revision'] ?? null);
        self::assertSame(3, $delta['to_revision'] ?? null);
        self::assertSame('project-owner', $delta['previous_approved_by'] ?? null);
        self::assertSame('Original approved goal.', $delta['previous_goal'] ?? null);
        self::assertSame(['src/B.php', 'tests/CTest.php'], $delta['lists']['Scope']['added'] ?? null);

        $text = $this->runBinary(['enter', 'DECISION-MULTI']);
        self::assertSame(1, $text['exit'], $text['stderr']);
        self::assertStringContainsString('Changes since revision 1 (new revision 3):', $text['stdout']);
    }

    public function testApprovalDeltaShowsChangedOperatingPromptAuthority(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'DECISION-PROMPT-CHANGE';
        $contracts->create(
            $taskId,
            'Approve prompt constraints.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
            behaviorAnchors: ['old-anchor'],
            operatingPromptManifest: 'prompts/old.json',
            operatingPrompts: [['id' => 'safety-check', 'arguments' => ['mode' => 'before']]],
        );
        $contracts->approve($taskId, 'owner', 1);
        $contracts->revise(
            $taskId,
            'Approve prompt constraints.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
            behaviorAnchors: ['new-anchor'],
            operatingPromptManifest: 'prompts/new.json',
            operatingPrompts: [['id' => 'safety-check', 'arguments' => ['mode' => 'after']]],
        );

        $json = $this->runBinary(['enter', $taskId, '--format=json']);
        $delta = $this->json($json['stdout'])['human_decision']['subject']['revision_delta'] ?? null;
        self::assertSame(1, $json['exit'], $json['stderr']);
        self::assertSame(['new-anchor'], $delta['lists']['Behavior anchors']['added'] ?? null);
        self::assertSame(['old-anchor'], $delta['lists']['Behavior anchors']['removed'] ?? null);
        self::assertSame(['prompts/new.json'], $delta['lists']['Operating prompt manifest']['added'] ?? null);
        self::assertSame(['prompts/old.json'], $delta['lists']['Operating prompt manifest']['removed'] ?? null);
        self::assertSame(['safety-check {"mode":"after"}'], $delta['lists']['Operating prompts']['added'] ?? null);
        self::assertSame(['safety-check {"mode":"before"}'], $delta['lists']['Operating prompts']['removed'] ?? null);
        self::assertFalse($delta['empty'] ?? true);

        $text = $this->runBinary(['enter', $taskId]);
        self::assertSame(1, $text['exit'], $text['stderr']);
        self::assertStringContainsString('Operating prompts:', $text['stdout']);
        self::assertStringContainsString('+ safety-check {"mode":"after"}', $text['stdout']);
        self::assertStringContainsString('- safety-check {"mode":"before"}', $text['stdout']);
        self::assertStringContainsString('+ new-anchor', $text['stdout']);
        self::assertStringContainsString('- old-anchor', $text['stdout']);
    }

    public function testPromptReorderingIsVisibleToTheHumanApprover(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'DECISION-PROMPT-ORDER';
        $first = [
            ['id' => 'lint', 'arguments' => []],
            ['id' => 'audit', 'arguments' => []],
        ];
        $contracts->create(
            $taskId,
            'Keep prompt constraints.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
            operatingPromptManifest: 'prompts/catalog.json',
            operatingPrompts: $first,
        );
        $contracts->approve($taskId, 'owner', 1);
        $contracts->revise(
            $taskId,
            'Keep prompt constraints.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
            operatingPromptManifest: 'prompts/catalog.json',
            operatingPrompts: [$first[1], $first[0]],
        );

        $json = $this->runBinary(['enter', $taskId, '--format=json']);
        $delta = $this->json($json['stdout'])['human_decision']['subject']['revision_delta'] ?? null;
        self::assertSame(1, $json['exit'], $json['stderr']);
        self::assertSame(['audit -> lint'], $delta['lists']['Operating prompt order']['added'] ?? null);
        self::assertSame(['lint -> audit'], $delta['lists']['Operating prompt order']['removed'] ?? null);
        self::assertFalse($delta['empty'] ?? true);

        $text = $this->runBinary(['enter', $taskId]);
        self::assertSame(1, $text['exit'], $text['stderr']);
        self::assertStringContainsString('Operating prompt order:', $text['stdout']);
        self::assertStringContainsString('+ audit -> lint', $text['stdout']);
        self::assertStringContainsString('- lint -> audit', $text['stdout']);
    }

    public function testAddedPromptCannotMaskReorderingExistingPrompts(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'DECISION-PROMPT-MIXED-ORDER';
        $first = [
            ['id' => 'lint', 'arguments' => []],
            ['id' => 'audit', 'arguments' => []],
        ];
        $contracts->create(
            $taskId,
            'Keep prompt constraints.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
            operatingPromptManifest: 'prompts/catalog.json',
            operatingPrompts: $first,
        );
        $contracts->approve($taskId, 'owner', 1);
        $contracts->revise(
            $taskId,
            'Keep prompt constraints.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
            operatingPromptManifest: 'prompts/catalog.json',
            operatingPrompts: [
                $first[1],
                $first[0],
                ['id' => 'scan', 'arguments' => []],
            ],
        );

        $json = $this->runBinary(['enter', $taskId, '--format=json']);
        $delta = $this->json($json['stdout'])['human_decision']['subject']['revision_delta'] ?? null;
        self::assertSame(1, $json['exit'], $json['stderr']);
        self::assertSame(['scan'], $delta['lists']['Operating prompts']['added'] ?? null);
        self::assertSame(['audit -> lint -> scan'], $delta['lists']['Operating prompt order']['added'] ?? null);
        self::assertSame(['lint -> audit'], $delta['lists']['Operating prompt order']['removed'] ?? null);

        $text = $this->runBinary(['enter', $taskId]);
        self::assertSame(1, $text['exit'], $text['stderr']);
        self::assertStringContainsString('Operating prompt order:', $text['stdout']);
        self::assertStringContainsString('+ audit -> lint -> scan', $text['stdout']);
    }

    public function testLatestOfTwoNamedApprovalsIsTheRevisionDeltaBaseline(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'DECISION-TWO-APPROVALS';
        $contracts->create($taskId, 'Revision one.', ['src/A.php'], [], ['vendor/bin/phpunit'], 'planner');
        $contracts->approve($taskId, 'first-owner', 1);
        $contracts->revise(
            $taskId,
            'Revision two.',
            ['src/A.php', 'src/B.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );
        $contracts->approve($taskId, 'second-owner', 2);
        $contracts->revise(
            $taskId,
            'Current candidate.',
            ['src/A.php', 'src/B.php', 'tests/CTest.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );

        $result = $this->runBinary(['enter', $taskId, '--format=json']);
        $payload = $this->json($result['stdout']);
        $delta = $payload['human_decision']['subject']['revision_delta'] ?? null;

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame(2, $delta['from_revision'] ?? null);
        self::assertSame(3, $delta['to_revision'] ?? null);
        self::assertSame('second-owner', $delta['previous_approved_by'] ?? null);
        self::assertSame('Revision two.', $delta['previous_goal'] ?? null);
        self::assertSame(['tests/CTest.php'], $delta['lists']['Scope']['added'] ?? null);
    }

    public function testLegacyReservedStandingApprovalDoesNotHideChangesSinceLastNamedApproval(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'DECISION-LEGACY-STANDING';
        $contracts->create(
            $taskId,
            'Original human-approved goal.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );
        $contracts->approve($taskId, 'project-owner', 1);
        $contracts->revise(
            $taskId,
            'Legacy pseudo-approved draft.',
            ['src/A.php', 'src/B.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );

        $this->persistLegacyReservedApproval($contracts, $taskId);

        $contracts->revise(
            $taskId,
            'Current candidate.',
            ['src/A.php', 'src/B.php', 'tests/CTest.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );
        $history = $contracts->supersededRevisions($taskId);
        self::assertCount(2, $history);
        self::assertSame('standing:legacy-actor', $history[1]->approvedBy);

        $result = $this->runBinary(['enter', $taskId, '--format=json']);
        $payload = $this->json($result['stdout']);
        $delta = $payload['human_decision']['subject']['revision_delta'] ?? null;

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame(1, $delta['from_revision'] ?? null);
        self::assertSame(3, $delta['to_revision'] ?? null);
        self::assertTrue($delta['previous_was_approved'] ?? false);
        self::assertSame('project-owner', $delta['previous_approved_by'] ?? null);
        self::assertSame('Original human-approved goal.', $delta['previous_goal'] ?? null);
        self::assertSame(['src/B.php', 'tests/CTest.php'], $delta['lists']['Scope']['added'] ?? null);
    }

    public function testLegacyPseudoApprovalAloneIsNeverPresentedAsApprovedBaseline(): void
    {
        $contracts = new TaskContractStore($this->root);
        $taskId = 'DECISION-LEGACY-ONLY';
        $contracts->create(
            $taskId,
            'First unapproved revision.',
            ['src/A.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );
        $this->persistLegacyReservedApproval($contracts, $taskId);
        $contracts->revise(
            $taskId,
            'Current candidate.',
            ['src/A.php', 'src/B.php'],
            [],
            ['vendor/bin/phpunit'],
            'planner',
        );

        $result = $this->runBinary(['enter', $taskId, '--format=json']);
        $delta = $this->json($result['stdout'])['human_decision']['subject']['revision_delta'] ?? null;

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame(1, $delta['from_revision'] ?? null);
        self::assertFalse($delta['previous_was_approved'] ?? true);
        self::assertNull($delta['previous_approved_by'] ?? null);
        self::assertNull($delta['previous_approved_at'] ?? null);
        self::assertSame('First unapproved revision.', $delta['previous_goal'] ?? null);
        self::assertSame(['src/B.php'], $delta['lists']['Scope']['added'] ?? null);

        $text = $this->runBinary(['enter', $taskId]);
        self::assertSame(1, $text['exit'], $text['stderr']);
        self::assertStringNotContainsString('Previous revision approved by:', $text['stdout']);
    }

    public function testWithoutApprovedRevisionDeltaUsesMostRecentDraft(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('DECISION-DRAFTS', 'First draft.', ['src/A.php'], [], ['vendor/bin/phpunit'], 'planner');
        $contracts->revise('DECISION-DRAFTS', 'Second draft.', ['src/A.php', 'src/B.php'], [], ['vendor/bin/phpunit'], 'planner');
        $contracts->revise('DECISION-DRAFTS', 'Third draft.', ['src/A.php', 'src/B.php', 'src/C.php'], [], ['vendor/bin/phpunit'], 'planner');

        $result = $this->runBinary(['enter', 'DECISION-DRAFTS', '--format=json']);
        $payload = $this->json($result['stdout']);
        $delta = $payload['human_decision']['subject']['revision_delta'] ?? null;

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame(2, $delta['from_revision'] ?? null);
        self::assertSame(3, $delta['to_revision'] ?? null);
        self::assertFalse($delta['previous_was_approved'] ?? true);
        self::assertNull($delta['previous_approved_by'] ?? null);
        self::assertSame(['src/C.php'], $delta['lists']['Scope']['added'] ?? null);
    }

    public function testHtmlPreferenceUsesExistingTaskOverviewWhenControlPlaneIsReady(): void
    {
        $this->writeConfig([
            'interaction' => [
                'decision_presentation' => 'html',
                'control_plane' => ['enabled' => true, 'host' => 'localhost', 'port' => 8123],
            ],
        ]);
        $binaryDirectory = $this->root . '/vendor/bin';
        if (!mkdir($binaryDirectory, 0o775, true) && !is_dir($binaryDirectory)) {
            throw new RuntimeException('Unable to create fake agent-ui binary directory.');
        }
        $binary = $binaryDirectory . '/agent-ui';
        file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' '{\"status\":\"ready\",\"url\":\"http://localhost:8123/\",\"detail\":null}'\n");
        chmod($binary, 0o755);
        (new TaskContractStore($this->root))->create(
            'DECISION-HTML',
            'Render the task approval in the existing task page.',
            ['src/Workflow'],
            ['Do not continue without human approval.'],
            ['vendor/bin/phpunit'],
            'codex',
        );

        $result = $this->runBinary(['enter', 'DECISION-HTML']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertStringContainsString(
            'Decision overview in agent-ui: http://localhost:8123/task/DECISION-HTML',
            $result['stdout'],
        );
        self::assertStringNotContainsString('Decision required: Contract approval', $result['stdout']);
        self::assertStringContainsString('Next: agent-loop workflow approve DECISION-HTML --revision 1 --by <named-actor>', $result['stdout']);
    }

    public function testHtmlPreferenceKeepsGermanDecisionInCliUntilTheViewIsLocalized(): void
    {
        $this->writeConfig([
            'prompting' => ['language' => 'de'],
            'interaction' => [
                'decision_presentation' => 'html',
                'control_plane' => ['enabled' => true, 'host' => 'localhost', 'port' => 8123],
            ],
        ]);
        $binaryDirectory = $this->root . '/vendor/bin';
        if (!mkdir($binaryDirectory, 0o775, true) && !is_dir($binaryDirectory)) {
            throw new RuntimeException('Unable to create fake agent-ui binary directory.');
        }
        $binary = $binaryDirectory . '/agent-ui';
        file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' '{\"status\":\"ready\",\"url\":\"http://localhost:8123/\",\"detail\":null}'\n");
        chmod($binary, 0o755);
        (new TaskContractStore($this->root))->create(
            'DECISION-DE-HTML',
            'Keep the German approval overview in the terminal.',
            ['src/Workflow'],
            [],
            ['vendor/bin/phpunit'],
            'codex',
        );

        $result = $this->runBinary(['enter', 'DECISION-DE-HTML']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Entscheidung erforderlich: Contract-Freigabe', $result['stdout']);
        self::assertStringContainsString('die CLI zeigt die Übersicht auf Deutsch', $result['stdout']);
        self::assertStringContainsString('Optionale englische agent-ui-Ansicht: http://localhost:8123/task/DECISION-DE-HTML', $result['stdout']);
    }

    public function testHtmlPreferenceFallsBackToFullCliDecisionWhenControlPlaneIsUnavailable(): void
    {
        $this->writeConfig(['interaction' => ['decision_presentation' => 'html']]);
        (new TaskContractStore($this->root))->create(
            'DECISION-FALLBACK',
            'Show the approval details when the browser view is missing.',
            ['src/Workflow'],
            [],
            ['vendor/bin/phpunit'],
            'codex',
        );

        $result = $this->runBinary(['enter', 'DECISION-FALLBACK']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Decision required: Contract approval', $result['stdout']);
        self::assertStringContainsString('agent-ui is unavailable; the full decision overview is shown in the CLI.', $result['stdout']);
        self::assertStringContainsString('Request changes:', $result['stdout']);
    }

    public function testUnreachableHtmlDecisionShowsSafeManualLaunchWithoutSkippingApproval(): void
    {
        $this->writeConfig([
            'interaction' => [
                'decision_presentation' => 'html',
                'control_plane' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => 8123],
            ],
        ]);
        $binaryDirectory = $this->root . '/vendor/bin';
        if (!mkdir($binaryDirectory, 0o775, true) && !is_dir($binaryDirectory)) {
            throw new RuntimeException('Unable to create fake agent-ui binary directory.');
        }
        $binary = $binaryDirectory . '/agent-ui';
        file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' '{\"status\":\"unreachable\",\"url\":null,\"detail\":\"not running\"}'\nexit 2\n");
        chmod($binary, 0o755);
        (new TaskContractStore($this->root))->create(
            'DECISION-START',
            'Offer a verified way to start the optional browser overview.',
            ['src/Workflow'],
            ['Do not automatically approve.'],
            ['vendor/bin/phpunit'],
            'codex',
        );

        $result = $this->runBinary(['enter', 'DECISION-START']);
        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertStringContainsString('Decision required: Contract approval', $result['stdout']);
        self::assertStringContainsString('agent-ui is unavailable; the full decision overview is shown in the CLI.', $result['stdout']);
        self::assertStringContainsString('Start agent-ui manually: ', $result['stdout']);
        self::assertStringContainsString(escapeshellarg('--port=8123'), $result['stdout']);
        self::assertStringNotContainsString('Decision overview in agent-ui: http', $result['stdout']);

        $json = $this->runBinary(['enter', 'DECISION-START', '--format=json']);
        $payload = $this->json($json['stdout']);
        self::assertSame('decision_required', $payload['next_action_kind'] ?? null);
        self::assertSame('contract_approval', $payload['human_decision']['type'] ?? null);
        self::assertSame('unreachable', $payload['control_plane_presentation']['status'] ?? null);
        self::assertSame([
            $binary,
            '--root=' . $this->root,
            '--host=127.0.0.1',
            '--port=8123',
        ], $payload['control_plane_presentation']['start_command'] ?? null);
        self::assertNull($payload['control_plane_presentation']['url'] ?? null);
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
        self::assertStringNotContainsString('--learning-reason', $learningPayload['next_action'] ?? '');

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
        $contract = $contracts->approve($taskId, 'lars', $contracts->load($taskId)->revision);
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

    private function persistLegacyReservedApproval(TaskContractStore $contracts, string $taskId): void
    {
        // A pre-reservation artifact must not be created through today's
        // approve() owner, which correctly refuses standing: identities.
        $path = $contracts->path($taskId);
        $legacy = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($legacy);
        $legacy['status'] = 'approved';
        $legacy['approved_by'] = ' standing:legacy-actor ';
        $legacy['approved_at'] = '2026-10-31T12:00:00+00:00';
        self::assertNotFalse(file_put_contents(
            $path,
            json_encode($legacy, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        ));
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

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        if (!mkdir($this->root . '/.agent-loop', 0o775, true) && !is_dir($this->root . '/.agent-loop')) {
            throw new RuntimeException('Unable to create config directory.');
        }
        file_put_contents($this->root . '/.agent-loop/init.json', json_encode($config, JSON_THROW_ON_ERROR));
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
