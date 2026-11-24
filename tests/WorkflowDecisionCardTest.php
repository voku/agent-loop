<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowDecisionCardFactory;
use voku\AgentLoop\Workflow\WorkflowInteractionCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/**
 * A real host once asked for approval without the decision card because the
 * skill that describes it was not loaded (#1114). The owner now projects the
 * card itself, from owner state only, for every decision_required gate.
 *
 * @internal
 */
final class WorkflowDecisionCardTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        // A host session language must not leak into these English assertions.
        foreach (['AGENT_LOOP_INTERACTION_DIR', 'AGENT_LOOP_HOST_SESSION_ID'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
        $this->root = sys_get_temp_dir() . '/agent-loop-card-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $git = 'git -C ' . escapeshellarg($this->root);
        exec("$git init -q && $git config user.email tests@example.test && $git config user.name Tests && $git add -A && $git commit -qm base", $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if (!is_dir($this->root)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testContractApprovalCardComesFromOwnerStateAndClaimsNoProof(): void
    {
        $this->plan(['--file', 'src/Foo.php']);
        $card = $this->enterJson()['human_decision']['card'];

        self::assertSame('contract_approval', $card['type']);
        self::assertSame('DEMO-1', $card['subject']['task_id']);
        self::assertSame(1, $card['subject']['contract_revision']);
        self::assertSame('first_revision', $card['why_now']['kind']);
        self::assertSame(['src/Foo.php'], $card['may_change']);
        self::assertSame(['No other class changes.'], $card['out_of_scope']);
        self::assertSame(WorkflowDecisionCardFactory::NOT_RUN, $card['proof']['validation']['state']);
        self::assertSame(['php -l src/Foo.php'], $card['proof']['validation']['planned']);
        self::assertSame(WorkflowDecisionCardFactory::NOT_RUN, $card['proof']['review']['state']);
        self::assertSame(['approve', 'request_changes', 'reject'], array_column($card['choices'], 'id'));
        self::assertSame('agent-loop workflow approve DEMO-1 --revision 1 --by <named-actor>', $card['choices'][0]['command']);
        self::assertStringContainsString('defaults to standard', $card['choices'][0]['effect']);
        self::assertStringContainsString('Surgical has one fresh review', $card['choices'][0]['effect']);
        self::assertStringContainsString('before the first enter', $card['choices'][0]['effect']);
        self::assertNull($card['choices'][1]['command']);
        self::assertNotSame([], $card['unknown']);
        self::assertStringNotContainsString('passed', (string) json_encode($card['proof']));
    }

    public function testSupersededRevisionCardNamesTheMechanicalChange(): void
    {
        $this->plan(['--file', 'src/Foo.php']);
        $this->plan(['--supersede', '--file', 'src/Foo.php', '--file', 'src/Bar.php']);
        $card = $this->enterJson()['human_decision']['card'];

        self::assertSame('supersedes_draft', $card['why_now']['kind']);
        self::assertSame(['Scope: + src/Bar.php'], $card['why_now']['changes']);
        self::assertSame(2, $card['subject']['contract_revision']);
    }

    public function testTextOutputPrintsTheCardBeforeTheContractDetails(): void
    {
        $this->plan(['--file', 'src/Foo.php']);
        ob_start();
        (new HostFrontDoorCommand($this->root))->run('enter', ['DEMO-1']);
        $text = (string) ob_get_clean();

        $card = strpos($text, 'Decision required: Contract approval');
        $proof = strpos($text, 'Proof:');
        $choices = strpos($text, 'Available choices:');
        $details = strpos($text, 'Contract details:');
        self::assertNotFalse($card);
        self::assertNotFalse($proof);
        self::assertNotFalse($choices);
        self::assertNotFalse($details);
        self::assertLessThan($proof, $card);
        self::assertLessThan($choices, $proof);
        self::assertLessThan($details, $choices);
        self::assertStringContainsString('What approval authorizes', $text);
        self::assertStringContainsString('Work may begin within the described scope.', $text);
    }

    public function testEveryOtherDecisionTypeGetsACardWithoutInventedProof(): void
    {
        $this->plan(['--file', 'src/Foo.php']);
        $factory = WorkflowDecisionCardFactory::forRoot($this->root);
        $subjects = [
            'review_acknowledgement' => ['report_status' => 'ok', 'findings' => []],
            'learning_disposition' => ['allowed_dispositions' => ['findings_recorded', 'follow_up_required'], 'review_observations' => ['Budget 0 admits a target.']],
            'risk_acceptance' => ['blockers' => [['code' => 'x', 'owner' => 'agent-loop', 'message' => 'Validation is stale.']]],
            'contract_supersession' => ['reason' => null, 'scope' => ['src/Foo.php'], 'blockers' => []],
            'human_authority' => ['blockers' => []],
        ];
        foreach ($subjects as $type => $subject) {
            $card = $factory->forDecision($type, 'DEMO-1', 'agent-loop do-something DEMO-1', $subject)->toArray();
            self::assertSame($type, $card['type']);
            self::assertNotSame('', $card['why_now']['summary'], $type);
            self::assertNotSame([], $card['choices'], $type);
            self::assertNotSame('passed', $card['proof']['validation']['state'], $type);
        }

        // The gate action here is prose from another owner; it is never offered as a command.
        $gate = 'revise the approved Contract, then run agent-loop workflow plan DEMO-1 --supersede';
        $learning = $factory->forDecision('learning_disposition', 'DEMO-1', $gate, $subjects['learning_disposition'])->toArray();
        self::assertSame(['findings_recorded', 'follow_up_required', 'gate_action'], array_column($learning['choices'], 'id'));
        self::assertSame('agent-loop finish DEMO-1 --learning findings_recorded --by <actor> --finding <finding-id>', $learning['choices'][0]['command']);
        self::assertSame('agent-loop finish DEMO-1 --by <actor> --follow-up-ref <follow-up-ref>', $learning['choices'][1]['command']);
        self::assertNull($learning['choices'][2]['command']);
        self::assertNotSame([], $learning['unknown']);
        self::assertSame(['Budget 0 admits a target.'], $learning['why_now']['changes']);

        $review = $factory->forDecision('review_acknowledgement', 'DEMO-1', $gate, ['review_sha256' => 'sha256:' . str_repeat('a', 64), 'report_status' => 'ok', 'findings' => []])->toArray();
        self::assertSame('agent-loop finish DEMO-1 --reviewed-report-sha256 sha256:' . str_repeat('a', 64) . ' --by <actor>', $review['choices'][0]['command']);
        self::assertSame('agent-loop workflow review DEMO-1', $review['choices'][1]['command']);

        $risk = $factory->forDecision('risk_acceptance', 'DEMO-1', 'agent-loop workflow close DEMO-1 --accept-risk', $subjects['risk_acceptance'])->toArray();
        self::assertSame(['Validation is stale.'], $risk['why_now']['changes']);

        $supersession = $factory->forDecision('contract_supersession', 'DEMO-1', 'agent-loop workflow plan DEMO-1 --supersede', $subjects['contract_supersession'])->toArray();
        self::assertNotSame([], $supersession['unknown'], 'A missing owner reason is reported as unknown.');
    }

    public function testAnUnreadableRunDoesNotBreakPlanningOrTheCard(): void
    {
        $this->plan(['--file', 'src/Foo.php']);
        mkdir($this->root . '/.agent-loop/runs/DEMO-1', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/runs/DEMO-1/run.json', '{"schema_version":"0.1"}');

        $text = $this->plan(['--supersede', '--file', 'src/Foo.php', '--file', 'src/Bar.php']);
        self::assertStringContainsString('Validation: not_run', $text);
        self::assertStringContainsString('Decision required: Contract approval', $text);
    }

    public function testCardReportsTheLanguageItIsWrittenInNotOnlyTheRequestedOne(): void
    {
        $this->plan(['--file', 'src/Foo.php']);
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->root . '/interaction');
        putenv('AGENT_LOOP_HOST_SESSION_ID=sess-fr');
        ob_start();
        (new WorkflowInteractionCommand())->run(['sess-fr', 'collaborative']);
        (new WorkflowInteractionCommand())->run(['sess-fr', '--language=fr-FR']);
        ob_end_clean();

        $card = $this->enterJson()['human_decision']['card'];

        self::assertSame('en', $card['language']);
        self::assertSame('fr-fr', $card['requested_language']);
        self::assertSame('Decision required: Contract approval', $card['title']);
        self::assertStringContainsString('fr-fr was requested', implode("\n", $card['unknown']));
    }

    /** @param list<string> $arguments */
    private function plan(array $arguments): string
    {
        ob_start();
        $exit = (new WorkflowPlanCommand($this->root))->run([
            'DEMO-1', '--by', 'lars', ...$arguments,
            '--goal', 'Add a bar() method to Foo.',
            '--non-goal', 'No other class changes.',
            '--validation', 'php -l src/Foo.php',
        ]);
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit, $output);

        return $output;
    }

    /** @return array<string, mixed> */
    private function enterJson(): array
    {
        ob_start();
        (new HostFrontDoorApplication($this->root))->run('enter', ['DEMO-1', '--format=json']);
        $decoded = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
