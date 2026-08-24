<?php

declare(strict_types=1);

/**
 * Installed release-set dogfood gate.
 *
 * The current agent-loop checkout is installed as a real Composer dependency
 * into a fresh consumer. Focused owner packages resolve from published sources
 * by default. Coordinated owner candidates are mounted as path repositories
 * only when the caller names them explicitly with --candidate=<package>.
 * Checkout-directory presence is never discovery: stale build/candidate-* state
 * must not change what release set this gate claims to exercise.
 */

use voku\AgentLoop\Dogfood\MinimumReleasePin;
use voku\AgentLoop\Dogfood\ReleaseSetCandidateSelection;

// Loaded directly rather than through the autoloader, for the same reason as
// the other candidate runner: these gates run against a checkout whose own
// dependencies may not be installed.
require dirname(__DIR__) . '/tools/Dogfood/MinimumReleasePin.php';
require dirname(__DIR__) . '/tools/Dogfood/ReleaseSetCandidateSelection.php';

final class ReleaseSetFailure extends RuntimeException
{
}

final class ReleaseSetDogfood
{
    private string $workspace;
    private string $candidateRoot;
    private string $consumerRoot;
    private string $artifactRoot;
    private string $logRoot;
    private string $reportPath;
    private bool $keep;
    private ReleaseSetCandidateSelection $candidateSelection;

    /** @var list<array<string, mixed>> */
    private array $scenarios = [];

    /** @var list<array<string, mixed>> */
    private array $commands = [];

    /** @var list<array<string, string>> */
    private array $friction = [];

    /** @var list<array<string, mixed>> */
    private array $frontDoorJourney = [];

    /**
     * Recovery results are kept apart from the front-door journey on purpose:
     * frontDoorJourneyReport() reads that list positionally, so appending
     * recovery commands to it would silently redefine the ordinary gate.
     *
     * @var list<array<string, mixed>>
     */
    private array $recoveryScenarios = [];

    private string $scenario = 'bootstrap';
    private int $commandNumber = 0;

    /** @param array{workspace: string, report: string, keep: bool, candidates: list<string>} $options */
    public function __construct(
        private readonly string $repositoryRoot,
        array $options,
    ) {
        $this->workspace = $options['workspace'];
        $this->candidateRoot = $this->workspace . '/candidate-agent-loop';
        $this->consumerRoot = $this->workspace . '/consumer';
        $this->artifactRoot = $this->workspace . '/artifacts';
        $this->logRoot = $this->artifactRoot . '/logs';
        $this->reportPath = $options['report'];
        $this->keep = $options['keep'];
        $this->candidateSelection = new ReleaseSetCandidateSelection($options['candidates']);
    }

    public function run(): int
    {
        $this->reset();
        $failed = false;

        try {
            $this->stageCandidate();
            $this->copyTree($this->repositoryRoot . '/tests/fixtures/release-set-consumer', $this->consumerRoot);
            $this->writeConsumerFiles();

            $this->step('install.resolve', fn () => $this->install());
            $this->step('install.focused-binaries', fn () => $this->focusedPackageBinaries());
            $this->step('workflow.scaffold', fn () => $this->scaffold());
            $this->step('workflow.ephemeral', fn () => $this->ephemeral());
            $this->step('workflow.plan', fn () => $this->plan());
            $this->step('workflow.approve', fn () => $this->approve());
            $this->step('map.optional-capability', fn () => $this->mapOptionalCapability());
            $this->step('workflow.implement', fn () => $this->implement());
            $this->step('workflow.validate', fn () => $this->validate());
            $this->step('workflow.review', fn () => $this->review());
            $this->step('workflow.learn', fn () => $this->learn());
            $this->step('workflow.close', fn () => $this->close());
            $this->step('workflow.prune-replay', fn () => $this->pruneAndReplay());
            $this->step('recovery.absent-discovery', fn () => $this->recoveryAbsentDiscovery());
            $this->step('recovery.failed-validation', fn () => $this->recoveryFailedValidation());
            $this->step('recovery.recall-meta-invalid', fn () => $this->recoveryRecallMetaInvalid());
            $this->step('recovery.review-report-invalid', fn () => $this->recoveryReviewReportInvalid());
            $this->step('recovery.execution-contract-blocked', fn () => $this->recoveryExecutionContractBlocked());
            $this->step('recovery.preparation-refusal', fn () => $this->recoveryPreparationRefusal());
        } catch (Throwable $exception) {
            $failed = true;
            $this->friction[] = [
                'scenario' => $this->scenario,
                'message' => $exception->getMessage(),
            ];
        }

        $this->writeReport($failed);
        echo 'Release-set dogfood: ' . ($failed ? 'FAILED' : 'PASSED') . "\n";
        echo 'Report: ' . $this->reportPath . "\n";
        if ($this->keep) {
            echo 'Workspace retained: ' . $this->workspace . "\n";
        } elseif (!$failed) {
            $this->removeTree($this->workspace);
        }

        return $failed ? 1 : 0;
    }

    private function install(): void
    {
        $this->mustRun(['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-ansi']);
        $this->mustRun(['git', 'init', '--initial-branch=main']);
        $this->mustRun(['git', 'config', 'user.name', 'Release Set Gate']);
        $this->mustRun(['git', 'config', 'user.email', 'release-set@example.invalid']);
        $this->mustRun(['git', 'add', 'composer.json', 'composer.lock', '.gitignore', 'src', 'tests', 'tools']);
        $this->mustRun(['git', 'commit', '-m', 'fixture: initial consumer state']);

        $packages = $this->resolvedPackages();
        foreach ([
            'voku/agent-loop',
            'voku/agent-session',
            'voku/agent-recall-compiler',
            'voku/agent-learning',
            'voku/agent-map',
            'voku/simple-php-code-parser',
        ] as $package) {
            if (!isset($packages[$package])) {
                throw new ReleaseSetFailure('Resolved consumer is missing ' . $package . '.');
            }
        }
        foreach ($this->candidateSelection->packages() as $package) {
            if (($packages[$package]['source_type'] ?? null) !== 'path') {
                throw new ReleaseSetFailure('Requested owner candidate did not resolve from a path repository: ' . $package);
            }
        }
        foreach ([
            'voku/agent-session',
            'voku/agent-recall-compiler',
            'voku/agent-learning',
        ] as $package) {
            if (!$this->candidateSelection->includes($package) && ($packages[$package]['source_type'] ?? null) === 'path') {
                throw new ReleaseSetFailure('Published-owner release-set run unexpectedly resolved a path candidate: ' . $package);
            }
        }
        $this->artifact($this->consumerRoot . '/composer.lock');
    }

    private function focusedPackageBinaries(): void
    {
        foreach ([
            'agent-kanban',
            'agent-session',
            'agent-map',
            'agent-recall-compiler',
            'agent-learning',
            'agent-loop',
        ] as $binary) {
            $this->mustRun(['vendor/bin/' . $binary, 'help']);
        }
    }

    /**
     * agent-map capability, exercised on the snapshot `enter` produced.
     *
     * This used to run before the lifecycle and build the Map itself, which is
     * why the earlier measurement could report zero manual preparation while
     * the consumer had in fact prepared discovery by hand. The lifecycle now
     * reconciles Map readiness, so this step asserts optional capability only.
     */
    private function mapOptionalCapability(): void
    {
        if (!is_file($this->consumerRoot . '/.agent-loop/map/php-symbols.json')) {
            throw new ReleaseSetFailure('enter did not reconcile Map discovery; the host should not have to build it.');
        }
        $this->mustRun([
            'vendor/bin/agent-map', 'search-index', 'build', '--root=.', '--index=.agent-loop/map/php-symbols.json', '--database=.agent-loop/map/search.sqlite',
        ]);
        $exact = $this->mustRun([
            'vendor/bin/agent-map', 'scope', 'Fixture\\RetryPolicy::delayMilliseconds', '--index=.agent-loop/map/php-symbols.json', '--format=json',
        ]);
        $decoded = $this->json($exact['stdout'], 'agent-map exact scope');
        $target = $decoded['target'] ?? null;
        if (!is_array($target) || ($target['label'] ?? null) !== 'Fixture\\RetryPolicy::delayMilliseconds') {
            throw new ReleaseSetFailure('agent-map did not resolve the consumer parser target.');
        }

        foreach ([
            'How is the delay before retrying a timed out request calculated?',
            'Wie wird die Wartezeit vor einem erneuten Versuch nach einer Zeitüberschreitung berechnet?',
        ] as $query) {
            $search = $this->mustRun([
                'vendor/bin/agent-map', 'search', $query,
                '--root=.', '--index=.agent-loop/map/php-symbols.json', '--database=.agent-loop/map/search.sqlite', '--format=json', '--limit=5',
            ]);
            if (!str_contains($search['stdout'], 'RetryPolicy')) {
                throw new ReleaseSetFailure('agent-map behavior search did not find RetryPolicy for query: ' . $query);
            }
        }
        $this->artifact($this->consumerRoot . '/.agent-loop/map/php-symbols.json');
    }

    private function scaffold(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'init', 'scaffold', '--demo', '--agent=codex']);
        $this->assertFile($this->consumerRoot . '/.agent-loop/tasks/DEMO-1.md');
        $this->assertFile($this->consumerRoot . '/.agent-loop/todo/cards/DEMO-1.md');
        $this->assertProjectedHostInstructions();

        $status = $this->status('DEMO-1');
        $this->assertReference($status, 'board', 'linked');
        $this->mustRun([
            'vendor/bin/agent-loop', 'workflow', 'status', 'DEMO-1',
            '--format=json', '--expect=complete',
        ], [1]);

        $entry = $this->frontDoor('unprepared', ['enter', 'DEMO-1', '--format=json'], [1]);
        if (($entry['mutation_ready'] ?? null) !== false) {
            throw new ReleaseSetFailure('Unprepared host front door did not fail closed.');
        }
        $nextAction = $entry['next_action'] ?? null;
        if (!is_string($nextAction) || !str_contains($nextAction, 'workflow plan DEMO-1')) {
            throw new ReleaseSetFailure('Unprepared host front door did not return the canonical plan prerequisite.');
        }
    }

    private function ephemeral(): void
    {
        $started = $this->mustRun([
            'vendor/bin/agent-loop', 'session', 'start', '--task', 'EXP-1', '--by', 'release-set-gate', '--ephemeral',
        ]);
        if (preg_match('/Started session:\s+(\S+)/', $started['stdout'], $match) !== 1) {
            throw new ReleaseSetFailure('Ephemeral Session id was not reported.');
        }
        $status = $this->status('EXP-1');
        if (($status['manifest']['mode'] ?? null) !== 'ephemeral') {
            throw new ReleaseSetFailure('Ephemeral Session was not projected as ephemeral.');
        }
        $this->mustRun(['vendor/bin/agent-loop', 'session', 'close', $match[1], '--status', 'dropped']);
    }

    private function plan(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop', 'workflow', 'plan', 'DEMO-1',
            '--by', 'release-set-gate',
            '--file', 'src/RetryPolicy.php',
            '--goal', 'Double the deterministic retry delay.',
            '--behavior-anchor', 'request timeout -> RetryPolicy delay -> caller-observed wait',
            '--validation', 'composer test',
        ]);
        $contract = $this->jsonFile($this->consumerRoot . '/.agent-loop/contracts/DEMO-1/contract.json');
        if (($contract['status'] ?? null) !== 'candidate' || ($contract['revision'] ?? null) !== 1) {
            throw new ReleaseSetFailure('PLAN did not persist candidate Contract revision 1.');
        }
        foreach (glob($this->consumerRoot . '/.agent-loop/sessions/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $sessionFile = $directory . '/session.json';
            if (is_file($sessionFile) && ($this->jsonFile($sessionFile)['task_id'] ?? null) === 'DEMO-1') {
                throw new ReleaseSetFailure('PLAN created pruneable Session state before approval.');
            }
        }
        $this->artifact($this->consumerRoot . '/.agent-loop/contracts/DEMO-1/contract.json');
    }

    private function approve(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'release-set-gate']);
        $status = $this->status('DEMO-1');
        $this->assertReference($status, 'approval', 'current');
        $this->assertReference($status, 'recall', 'missing');
        if (is_file($this->consumerRoot . '/.agent-loop/runs/DEMO-1/run.json')) {
            throw new ReleaseSetFailure('APPROVE allocated durable Run identity before enter.');
        }
        foreach (glob($this->consumerRoot . '/.agent-loop/sessions/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $sessionFile = $directory . '/session.json';
            if (is_file($sessionFile) && ($this->jsonFile($sessionFile)['task_id'] ?? null) === 'DEMO-1') {
                throw new ReleaseSetFailure('APPROVE allocated pruneable DEMO-1 Session state before enter.');
            }
        }

        $entry = $this->frontDoor(
            'governed_ready',
            ['enter', 'DEMO-1', '--format=json', '--max-lines=40', '--max-bytes=4096'],
        );
        if (($entry['mutation_ready'] ?? null) !== true) {
            throw new ReleaseSetFailure('Governed host front door did not report mutation readiness.');
        }
        if (($entry['context_lines'] ?? 0) < 1 || ($entry['context_lines'] ?? 0) > 40 || ($entry['context_bytes'] ?? 0) > 4096) {
            throw new ReleaseSetFailure('Governed host front door exceeded or omitted its bounded context.');
        }

        $status = $this->status('DEMO-1');
        $this->assertReference($status, 'recall', 'compiled');
        $this->assertReference($status, 'session', 'active');
        $runId = $status['manifest']['run_id'] ?? null;
        if (!is_string($runId) || !str_starts_with($runId, 'run:')) {
            throw new ReleaseSetFailure('ENTER did not create durable Run identity.');
        }
        $this->writeJson($this->artifactRoot . '/run-before-close.json', ['run_id' => $runId]);
        $this->artifact($this->consumerRoot . '/.agent-loop/runs/DEMO-1/run.json');

        foreach (glob($this->consumerRoot . '/.agent-loop/sessions/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (is_file($directory . '/work-brief.json') || is_file($directory . '/approval.json') || is_file($directory . '/learning-decision.json')) {
                throw new ReleaseSetFailure('Session contains removed durable authority artifacts.');
            }
        }
    }

    private function implement(): void
    {
        $before = $this->status('DEMO-1')['manifest']['run_id'] ?? null;
        $this->mustRun([PHP_BINARY, 'tools/apply-change.php']);
        $this->mustRun([
            'vendor/bin/agent-map', 'refresh', '--root=.', '--index=.agent-loop/map/php-symbols.json', '--out=.agent-loop/map/php-symbols.json',
        ]);
        $this->mustRun([
            'vendor/bin/agent-map', 'search-index', 'refresh', '--root=.', '--index=.agent-loop/map/php-symbols.json', '--database=.agent-loop/map/search.sqlite',
        ]);
        $after = $this->status('DEMO-1')['manifest']['run_id'] ?? null;
        if (!is_string($before) || $after !== $before) {
            throw new ReleaseSetFailure('Implementation or Map refresh changed durable Run identity.');
        }
        $this->artifact($this->consumerRoot . '/src/RetryPolicy.php');
    }

    private function validate(): void
    {
        $this->mustRun(['composer', 'test']);
        $this->mustRun([
            'vendor/bin/agent-loop', 'session', 'validation', 'record', 'DEMO-1',
            '--contract-revision', '1',
            '--command', 'composer test',
            '--status', 'passed',
            '--exit-code', '0',
            '--duration-ms', '0',
            '--by', 'release-set-gate',
        ]);
    }

    private function review(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1'], [0, 1]);
        $this->mustRun([
            'vendor/bin/agent-loop', 'session', 'checkpoint', 'DEMO-1',
            '--title', 'Release-set review',
            '--body', 'The deterministic blind-spot report was inspected by the release-set gate.',
        ]);
        $this->mustRun(['vendor/bin/agent-loop', 'review', 'blindspots', 'DEMO-1']);
    }

    private function learn(): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop', 'workflow', 'learn', 'DEMO-1',
            '--status', 'no_durable_learning',
            '--by', 'release-set-gate',
            '--reason', 'The installed release-set proof produced no reusable guidance.',
        ]);
        $status = $this->status('DEMO-1');
        $this->assertReference($status, 'learning', 'decided');
    }

    private function close(): void
    {
        $this->mustRun(['vendor/bin/agent-loop', 'verify', '--task-id=DEMO-1']);

        // The deterministic report exists, but acknowledging it is an
        // authority-bearing decision. Until an actor names its exact identity,
        // the front door reopens neither mutation nor completion.
        $reviewDigest = $this->status('DEMO-1')['manifest']['references']['review']['source']['sha256'] ?? null;
        if (!is_string($reviewDigest) || !str_starts_with($reviewDigest, 'sha256:')) {
            throw new ReleaseSetFailure('Verified Run did not expose an exact review-report identity.');
        }
        $acknowledgeAction = 'agent-loop finish DEMO-1 --reviewed-report-sha256 ' . $reviewDigest . ' --by <actor>';

        $verified = $this->frontDoor('verified_no_mutation', ['enter', 'DEMO-1', '--format=json'], [1]);
        if (($verified['mutation_ready'] ?? null) !== false) {
            throw new ReleaseSetFailure('Verified host front door reopened mutation instead of asking for review acknowledgement.');
        }
        if (($verified['next_action'] ?? null) !== $acknowledgeAction) {
            throw new ReleaseSetFailure('Verified host front door did not return the canonical acknowledgement action.');
        }

        $premature = $this->frontDoor('unacknowledged_review', ['finish', 'DEMO-1', '--format=json'], [1]);
        if (($premature['complete'] ?? null) !== false) {
            throw new ReleaseSetFailure('Host front door accepted completion before the review report was acknowledged.');
        }
        if (($premature['next_action'] ?? null) !== $acknowledgeAction) {
            throw new ReleaseSetFailure('Premature finish did not return the canonical acknowledgement action.');
        }

        $this->mustRun([
            'vendor/bin/agent-loop', 'finish', 'DEMO-1',
            '--reviewed-report-sha256', $reviewDigest,
            '--by', 'release-set-gate',
        ]);
        $status = $this->status('DEMO-1', 'complete');
        if (($status['manifest']['state'] ?? null) !== 'complete') {
            throw new ReleaseSetFailure('CLOSE did not produce complete durable Run state.');
        }
        $this->assertReference($status, 'verification', 'passed');
        $this->assertReference($status, 'session', 'done');

        $complete = $this->frontDoor('complete', ['finish', 'DEMO-1', '--format=json']);
        if (($complete['complete'] ?? null) !== true || ($complete['state'] ?? null) !== 'complete') {
            throw new ReleaseSetFailure('Host front door did not accept the completed Run.');
        }
        if (($complete['next_action'] ?? null) !== 'none') {
            throw new ReleaseSetFailure('Completed finish exposed another action.');
        }

        $this->artifact($this->consumerRoot . '/.agent-loop/runs/DEMO-1/verification.json');
    }

    private function pruneAndReplay(): void
    {
        $before = $this->jsonFile($this->artifactRoot . '/run-before-close.json')['run_id'] ?? null;
        $this->mustRun(['vendor/bin/agent-loop', 'session', 'prune', '--keep-days', '0', '--status', 'done']);

        $status = $this->status('DEMO-1', 'complete');
        $after = $status['manifest']['run_id'] ?? null;
        if (!is_string($before) || $after !== $before) {
            throw new ReleaseSetFailure('Pruning Session working memory changed Run identity.');
        }
        if (($status['manifest']['state'] ?? null) !== 'complete') {
            throw new ReleaseSetFailure('Completed Run stopped being complete after Session pruning.');
        }
        $this->assertReference($status, 'session', 'missing');
        $this->assertReference($status, 'verification', 'passed');
        $this->assertReference($status, 'learning', 'decided');

        $report = $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'report', 'DEMO-1', '--format', 'json']);
        $decoded = $this->json($report['stdout'], 'post-prune workflow report');
        if (($decoded['validation'][0]['source'] ?? null) !== 'verification_receipt') {
            throw new ReleaseSetFailure('Post-prune report did not replay validation from durable Verification Receipt.');
        }
        $this->writeJson($this->artifactRoot . '/post-prune-status.json', $status);
        $this->writeJson($this->artifactRoot . '/post-prune-report.json', $decoded);
    }

    /**
     * @param list<string> $arguments
     * @param list<int> $allowedExitCodes
     * @return array<string, mixed>
     */
    private const string RECOVERY_L2_PROMPT_MANIFEST =
        'vendor/voku/agent-recall-compiler/skills/agent-recall-consumer/operating-prompts.json';

    /** @param list<string> $extra */
    private function recoveryPlan(string $root, string $task, array $extra = []): void
    {
        $this->mustRun([
            'vendor/bin/agent-loop', 'workflow', 'plan', $task,
            '--by', 'release-set-gate',
            '--file', 'src/Greeter.php',
            '--goal', 'Punctuate the greeting.',
            '--validation', 'php -l src/Greeter.php',
            ...$extra,
        ], [0], $root);
    }

    /** @param list<string> $extra */
    private function recoveryApproved(string $root, string $task, array $extra = []): void
    {
        $this->recoveryPlan($root, $task, $extra);
        // No manual Map build: enter reconciles discovery. If that regresses,
        // every scenario below fails rather than quietly paying for it here.
        $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'approve', $task, '--by', 'release-set-gate'], [0], $root);
        $this->mustRun(['vendor/bin/agent-loop', 'enter', $task], [0, 1, 2], $root);
    }

    private function recoveryImplement(string $root): void
    {
        $path = $root . '/src/Greeter.php';
        file_put_contents($path, str_replace("'Hello ' . \$name;", "'Hello ' . \$name . '!';", (string) file_get_contents($path)));
    }

    /**
     * Scenario 1: absent discovery is the lifecycle's problem, not the host's.
     *
     * This previously asserted the opposite - that the canonical action names
     * `map build` - which was correct while approval refused without a Map.
     * Map readiness needs no human decision, so the only thing left to ask for
     * here is the approval itself, and `enter` reconciles discovery.
     */
    private function recoveryAbsentDiscovery(): void
    {
        $scenario = 'recovery.absent-discovery';
        $root = $this->recoveryConsumer('absent-discovery');
        $this->recoveryPlan($root, 'REC-1');

        $step = $this->recoveryStep($this->recoveryManifest($root, ['enter', 'REC-1']));
        $this->expectKind($scenario, 'decision_required', $step['kind']);
        $this->expectContains($scenario, 'workflow approve', $step['action']);
        $this->expectNotContains($scenario, 'map build', $step['action']);
        $this->expectNotContains($scenario, 'map refresh', $step['action']);

        // Supply the one irreducible decision, then discovery must resolve itself.
        $this->mustRun(['vendor/bin/agent-loop', 'workflow', 'approve', 'REC-1', '--by', 'release-set-gate'], [0], $root);
        $this->mustRun(['vendor/bin/agent-loop', 'enter', 'REC-1'], [0, 1, 2], $root);
        if (!is_file($root . '/.agent-loop/map/php-symbols.json')) {
            throw new ReleaseSetFailure($scenario . ': enter did not reconcile Map discovery for an approved PHP scope.');
        }
        $this->recordRecovery($scenario, ['kind' => $step['kind'], 'action' => $step['action'], 'obeyed' => false]);
    }

    /** Scenario 2: a failed validation obligation is irreducible host work, not a command. */
    private function recoveryFailedValidation(): void
    {
        $scenario = 'recovery.failed-validation';
        $root = $this->recoveryConsumer('failed-validation');
        $this->recoveryApproved($root, 'REC-2');
        file_put_contents($root . '/src/Greeter.php', "<?php\n\nthis is not valid php\n");
        $this->mustRun(['vendor/bin/agent-loop', 'finish', 'REC-2'], [0, 1, 2], $root);

        $step = $this->recoveryStep($this->recoveryManifest($root, ['finish', 'REC-2']));
        $this->expectKind($scenario, 'host_work', $step['kind']);
        $this->expectContains($scenario, 'validation', $step['action']);
        // Host work must not be spelled as a command the host could "run".
        $this->expectNotContains($scenario, 'agent-loop', $step['action']);
        $this->recordRecovery($scenario, ['kind' => $step['kind'], 'action' => $step['action'], 'obeyed' => false]);
    }

    /** Scenario 3: an unreadable owner artifact must not advertise a read-only diagnostic as its repair. */
    private function recoveryRecallMetaInvalid(): void
    {
        $scenario = 'recovery.recall-meta-invalid';
        $root = $this->recoveryConsumer('recall-meta-invalid');
        $this->recoveryApproved($root, 'REC-3');
        $meta = $root . '/.agent-loop/recall/REC-3/meta.json';
        $this->assertFile($meta);
        file_put_contents($meta, '{');

        $step = $this->recoveryStep($this->recoveryManifest($root, ['enter', 'REC-3']));
        $this->expectKind($scenario, 'host_work', $step['kind']);
        $this->expectContains($scenario, 'agent-recall-compiler', $step['action']);
        $this->expectNotContains($scenario, 'workflow manifest', $step['action']);
        $this->recordRecovery($scenario, ['kind' => $step['kind'], 'action' => $step['action'], 'obeyed' => false]);
    }

    /** Scenario 4: an invalid review report converges on the owner's own repair command. */
    private function recoveryReviewReportInvalid(): void
    {
        $scenario = 'recovery.review-report-invalid';
        $root = $this->recoveryConsumer('review-report-invalid');
        $this->recoveryApproved($root, 'REC-4');
        $this->recoveryImplement($root);
        $this->mustRun(['vendor/bin/agent-loop', 'finish', 'REC-4'], [0, 1, 2], $root);

        $reports = glob($root . '/.agent-loop/recall/REC-4/reviews/*.json') ?: [];
        if ($reports === []) {
            throw new ReleaseSetFailure($scenario . ': validation did not produce a blind-spot report to invalidate.');
        }
        foreach ($reports as $report) {
            $document = $this->jsonFile($report);
            $document['status'] = 'fail';
            $document['findings'] = [['id' => 'F1', 'summary' => 'blocking']];
            $this->writeJson($report, $document);
        }

        $manifest = $this->recoveryManifest($root, ['finish', 'REC-4']);
        $step = $this->recoveryStep($manifest);
        $this->expectKind($scenario, 'command', $step['kind']);
        $this->expectContains($scenario, 'review blindspots', $step['action']);
        $this->obeyAndProveProgress($scenario, $root, $manifest, ['finish', 'REC-4']);
        $this->recordRecovery($scenario, ['kind' => $step['kind'], 'action' => $step['action'], 'obeyed' => true]);
    }

    /** Scenario 5: a stopped execution contract stays a governed decision, never a silent rewrite. */
    private function recoveryExecutionContractBlocked(): void
    {
        $scenario = 'recovery.execution-contract-blocked';
        $root = $this->recoveryConsumer('execution-contract-blocked');
        $this->recoveryApproved($root, 'REC-5', [
            '--operating-prompt-manifest', self::RECOVERY_L2_PROMPT_MANIFEST,
            '--operating-prompt', '{"id":"adversarial-review","arguments":{"minimum_failure_modes":3}}',
        ]);
        $this->mustRun([
            'vendor/bin/agent-loop', 'workflow', 'contract', 'REC-5',
            '--status', 'blocked', '--by', 'release-set-gate',
            '--reason', 'Selected L2 policy cannot be satisfied by the approved scope.',
            '--evidence', 'src/Greeter.php',
            '--minimum-change', 'Expand the governed scope to the caller.',
        ], [0], $root);

        $step = $this->recoveryStep($this->recoveryManifest($root, ['enter', 'REC-5']));
        $this->expectKind($scenario, 'decision_required', $step['kind']);
        $this->expectContains($scenario, 'Expand the governed scope to the caller.', $step['action']);
        $this->expectContains($scenario, '--supersede', $step['action']);
        $this->recordRecovery($scenario, ['kind' => $step['kind'], 'action' => $step['action'], 'obeyed' => false]);
    }

    /** Scenario 6: a deterministic preparation refusal must never name the command that just refused. */
    private function recoveryPreparationRefusal(): void
    {
        $scenario = 'recovery.preparation-refusal';
        $root = $this->recoveryConsumer('preparation-refusal');
        $this->recoveryApproved($root, 'REC-6', [
            '--operating-prompt-manifest', self::RECOVERY_L2_PROMPT_MANIFEST,
            '--operating-prompt', '{"id":"release-set-gate-no-such-capability","arguments":{}}',
        ]);

        $step = $this->recoveryStep($this->recoveryManifest($root, ['enter', 'REC-6']));
        $this->expectKind($scenario, 'host_work', $step['kind']);
        // The owning package's own cause must survive into the lifecycle result.
        $this->expectContains($scenario, 'release-set-gate-no-such-capability', $step['action']);
        $this->expectNotContains($scenario, 'agent-loop enter REC-6', $step['action']);
        $this->recordRecovery($scenario, ['kind' => $step['kind'], 'action' => $step['action'], 'obeyed' => false]);
    }

    /**
     * Phase-F recovery convergence, re-proven on every run.
     *
     * #233 proved these six shapes once, on a consumer that installed
     * agent-loop as a real Composer dependency. A proof that only ran once is
     * history; these steps make it an invariant. Each scenario gets its own
     * consumer root so a deliberately broken state cannot leak into the
     * ordinary path, while the installed release set stays the single authority.
     */
    private function recoveryConsumer(string $name): string
    {
        $root = $this->workspace . '/recovery-' . $name;
        $this->removeTree($root);
        $this->mkdir($root . '/src');
        file_put_contents(
            $root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Greeter\n{\n"
            . "    public function greet(string \$name): string\n    {\n        return 'Hello ' . \$name;\n    }\n}\n",
        );
        // The installed release set is reused rather than reinstalled: this
        // gate must exercise the packages the ordinary path resolved, and a
        // second `composer update` could resolve something else entirely.
        if (!symlink($this->consumerRoot . '/vendor', $root . '/vendor')) {
            throw new ReleaseSetFailure('Unable to reuse the installed release set for recovery consumer ' . $name . '.');
        }

        return $root;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function recoveryManifest(string $root, array $arguments): array
    {
        $result = $this->mustRun(
            ['vendor/bin/agent-loop', ...$arguments, '--format=json'],
            [0, 1, 2],
            $root,
        );
        $payload = $this->json($result['stdout'], 'recovery ' . implode(' ', $arguments));
        $manifest = $payload['manifest'] ?? null;
        if (!is_array($manifest)) {
            throw new ReleaseSetFailure('Recovery projection returned no manifest for: ' . implode(' ', $arguments));
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{kind: string, action: string}
     */
    private function recoveryStep(array $manifest): array
    {
        $kind = $manifest['next_action_kind'] ?? null;
        $action = $manifest['next_action'] ?? null;
        if (!is_string($kind) || !is_string($action)) {
            throw new ReleaseSetFailure('Recovery projection exposed no canonical next step.');
        }

        return ['kind' => $kind, 'action' => $action];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{0: string, 1: string, 2: string}
     */
    private function recoverySignature(array $manifest): array
    {
        $step = $this->recoveryStep($manifest);

        return [(string) ($manifest['state'] ?? ''), $step['action'], $step['kind']];
    }

    private function expectKind(string $scenario, string $expected, string $actual): void
    {
        if ($actual !== $expected) {
            throw new ReleaseSetFailure(sprintf(
                '%s: expected next_action_kind %s, got %s.',
                $scenario,
                $expected,
                $actual,
            ));
        }
    }

    private function expectContains(string $scenario, string $needle, string $haystack): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new ReleaseSetFailure(sprintf('%s: expected canonical action to contain "%s", got: %s', $scenario, $needle, $haystack));
        }
    }

    private function expectNotContains(string $scenario, string $needle, string $haystack): void
    {
        if (str_contains($haystack, $needle)) {
            throw new ReleaseSetFailure(sprintf('%s: canonical action must not contain "%s", got: %s', $scenario, $needle, $haystack));
        }
    }

    /**
     * Obey the canonical command and prove the blocker actually moved.
     *
     * This is the assertion the whole gate exists for: a command-shaped
     * canonical action that cannot change the state that produced it is a
     * defect, not guidance.
     *
     * @param list<string> $projection
     * @param array<string, mixed> $manifest
     */
    private function obeyAndProveProgress(string $scenario, string $root, array $manifest, array $projection): void
    {
        $before = $this->recoverySignature($manifest);
        $action = $this->recoveryStep($manifest)['action'];
        $tokens = array_values(array_filter(explode(' ', $action), static fn (string $token): bool => $token !== ''));
        if (($tokens[0] ?? null) !== 'agent-loop') {
            throw new ReleaseSetFailure($scenario . ': canonical command did not name the agent-loop binary: ' . $action);
        }
        $this->mustRun(['vendor/bin/agent-loop', ...array_slice($tokens, 1)], [0, 1, 2], $root);

        $after = $this->recoverySignature($this->recoveryManifest($root, $projection));
        if ($after === $before) {
            throw new ReleaseSetFailure(sprintf(
                '%s: obeying the canonical command left state, action and kind identical (%s). '
                . 'A command that cannot change its own blocker is non-convergent.',
                $scenario,
                json_encode($before, JSON_THROW_ON_ERROR),
            ));
        }
    }

    /** @param array<string, mixed> $observed */
    private function recordRecovery(string $scenario, array $observed): void
    {
        $this->recoveryScenarios[] = ['scenario' => $scenario] + $observed;
    }

    private function frontDoor(string $phase, array $arguments, array $allowedExitCodes = [0]): array
    {
        $result = $this->mustRun(['vendor/bin/agent-loop', ...$arguments], $allowedExitCodes);
        $payload = $this->json($result['stdout'], 'host front door ' . $phase);
        $contextLines = is_array($payload['context']['lines'] ?? null) ? $payload['context']['lines'] : [];
        $entry = [
            'command' => $arguments[0] ?? 'unknown',
            'phase' => $phase,
            'exit' => $result['exit'],
            'state' => $payload['manifest']['state'] ?? null,
            'mutation_ready' => $payload['mutation_ready'] ?? null,
            'complete' => $payload['complete'] ?? null,
            'next_action' => $payload['next_action'] ?? null,
            'context_lines' => count($contextLines),
            'context_bytes' => array_sum(array_map(
                static fn (mixed $line): int => strlen((string) $line) + 1,
                $contextLines,
            )),
        ];
        $this->frontDoorJourney[] = $entry;

        return $entry;
    }

    /** @return array<string, mixed> */
    private function frontDoorJourneyReport(): array
    {
        $signatures = array_map(
            static fn (array $entry): string => json_encode([
                $entry['command'] ?? null,
                $entry['state'] ?? null,
            ], JSON_THROW_ON_ERROR),
            $this->frontDoorJourney,
        );
        $mutationReadyTransition = array_values(array_map(
            static fn (array $entry): bool => $entry['mutation_ready'],
            array_filter(
                $this->frontDoorJourney,
                static fn (array $entry): bool => is_bool($entry['mutation_ready'] ?? null),
            ),
        ));
        $ready = $this->frontDoorJourney[1] ?? [];
        $premature = $this->frontDoorJourney[3] ?? [];
        $complete = $this->frontDoorJourney[4] ?? [];

        return [
            'front_door_commands' => count($this->frontDoorJourney),
            'failed_front_door_commands' => count(array_filter(
                $this->frontDoorJourney,
                static fn (array $entry): bool => ($entry['exit'] ?? 0) !== 0,
            )),
            'repeated_same_state_commands' => count($signatures) - count(array_unique($signatures)),
            'context_lines' => $ready['context_lines'] ?? 0,
            'context_bytes' => $ready['context_bytes'] ?? 0,
            'mutation_ready_transition' => $mutationReadyTransition,
            'premature_done_caught' => ($premature['exit'] ?? null) === 1 && ($premature['complete'] ?? null) === false,
            'final_complete' => ($complete['exit'] ?? null) === 0 && ($complete['complete'] ?? null) === true,
            'commands' => $this->frontDoorJourney,
        ];
    }

    /** @return array<string, mixed> */
    private function status(string $taskId, ?string $expectedState = null): array
    {
        $command = ['vendor/bin/agent-loop', 'workflow', 'status', $taskId, '--format=json'];
        if ($expectedState !== null) {
            $command[] = '--expect=' . $expectedState;
        }
        $result = $this->mustRun($command);

        return $this->json($result['stdout'], 'workflow status ' . $taskId);
    }

    /** @param array<string, mixed> $status */
    private function assertReference(array $status, string $name, string $expected): void
    {
        $actual = $status['manifest']['references'][$name]['state'] ?? null;
        if ($actual !== $expected) {
            throw new ReleaseSetFailure(sprintf(
                'Expected %s state %s, got %s.',
                $name,
                $expected,
                is_scalar($actual) ? (string) $actual : get_debug_type($actual),
            ));
        }
    }

    private function writeConsumerFiles(): void
    {
        $this->mkdir($this->artifactRoot);
        $this->mkdir($this->logRoot);

        $repositories = [[
            'type' => 'path',
            'url' => str_replace('\\', '/', $this->candidateRoot),
            'options' => ['symlink' => false, 'versions' => ['voku/agent-loop' => 'dev-main']],
        ]];
        foreach ($this->candidateSelection->paths() as $package => $relative) {
            $path = $this->repositoryRoot . '/' . $relative;
            if (!is_dir($path) || !is_file($path . '/composer.json')) {
                throw new ReleaseSetFailure(sprintf(
                    'Requested release-set candidate %s is missing a checkout with composer.json at %s.',
                    $package,
                    $path,
                ));
            }
            $version = MinimumReleasePin::pathRepositoryVersion(
                MinimumReleasePin::declaredConstraint($this->repositoryRoot . '/composer.json', $package),
            );
            $repositories[] = [
                'type' => 'path',
                'url' => str_replace('\\', '/', $path),
                'options' => ['symlink' => false, 'versions' => [$package => $version]],
            ];
        }

        $this->writeJson($this->consumerRoot . '/composer.json', [
            'name' => 'voku/release-set-consumer-fixture',
            'type' => 'project',
            'require-dev' => ['voku/agent-loop' => 'dev-main'],
            'repositories' => $repositories,
            'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
            'scripts' => ['test' => 'php tests/run.php'],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => ['allow-plugins' => false, 'sort-packages' => true],
        ]);
        file_put_contents(
            $this->consumerRoot . '/.gitignore',
            "/vendor/\n/.agent-loop/\n",
        );
    }

    private function stageCandidate(): void
    {
        $this->mkdir($this->candidateRoot);
        foreach (['composer.json', 'LICENSE'] as $file) {
            $source = $this->repositoryRoot . '/' . $file;
            if (is_file($source)) {
                copy($source, $this->candidateRoot . '/' . $file);
            }
        }
        foreach (['src', 'bin', 'docs/agents'] as $directory) {
            $this->copyTree($this->repositoryRoot . '/' . $directory, $this->candidateRoot . '/' . $directory);
        }
    }

    /** @return array<string, array{version: string, source_type: string, source: string}> */
    private function resolvedPackages(): array
    {
        $lock = $this->jsonFile($this->consumerRoot . '/composer.lock');
        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock[$section] ?? null) ? $lock[$section] : [] as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) {
                    continue;
                }
                $dist = is_array($row['dist'] ?? null) ? $row['dist'] : [];
                $source = is_array($row['source'] ?? null) ? $row['source'] : [];
                $sourceType = is_string($dist['type'] ?? null)
                    ? $dist['type']
                    : (is_string($source['type'] ?? null) ? $source['type'] : 'unknown');
                $sourceUrl = is_string($dist['url'] ?? null)
                    ? $dist['url']
                    : (is_string($source['url'] ?? null) ? $source['url'] : 'unknown');
                $packages[$row['name']] = [
                    'version' => is_string($row['version'] ?? null) ? $row['version'] : 'unknown',
                    'source_type' => $sourceType,
                    'source' => $sourceUrl,
                ];
            }
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    private function step(string $id, callable $callback): void
    {
        $this->scenario = $id;
        $before = count($this->commands);
        try {
            $callback();
            $this->scenarios[] = [
                'id' => $id,
                'status' => 'passed',
                'commands' => array_slice($this->commands, $before),
            ];
        } catch (Throwable $exception) {
            $this->scenarios[] = [
                'id' => $id,
                'status' => 'failed',
                'commands' => array_slice($this->commands, $before),
                'failure' => $exception->getMessage(),
            ];
            throw $exception;
        }
    }

    /** @param list<string> $command @param list<int> $allowedExitCodes @return array{exit: int, stdout: string, stderr: string} */
    private function mustRun(array $command, array $allowedExitCodes = [0], ?string $cwd = null): array
    {
        $result = $this->runCommand($command, $cwd);
        if (!in_array($result['exit'], $allowedExitCodes, true)) {
            throw new ReleaseSetFailure(sprintf(
                'Command failed with exit %d: %s. See %s and %s.',
                $result['exit'],
                implode(' ', $command),
                $result['stdout_log'],
                $result['stderr_log'],
            ));
        }

        return $result;
    }

    /** @param list<string> $command @return array{exit: int, stdout: string, stderr: string, stdout_log: string, stderr_log: string} */
    private function runCommand(array $command, ?string $cwd = null): array
    {
        ++$this->commandNumber;
        $base = sprintf('%03d-%s', $this->commandNumber, preg_replace('/[^A-Za-z0-9_.-]+/', '-', $this->scenario) ?? 'command');
        $stdoutPath = $this->logRoot . '/' . $base . '.stdout.log';
        $stderrPath = $this->logRoot . '/' . $base . '.stderr.log';
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['COMPOSER_NO_INTERACTION'] = '1';
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
            $pipes,
            $cwd ?? $this->consumerRoot,
            $environment,
        );
        if (!is_resource($process)) {
            throw new ReleaseSetFailure('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $exit = proc_close($process);
        $stdout = (string) file_get_contents($stdoutPath);
        $stderr = (string) file_get_contents($stderrPath);
        $result = [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdout_log' => $this->relativeWorkspace($stdoutPath),
            'stderr_log' => $this->relativeWorkspace($stderrPath),
        ];
        $this->commands[] = [
            'display' => implode(' ', $command),
            'exit_code' => $exit,
            'stdout_sha256' => 'sha256:' . hash('sha256', $stdout),
            'stderr_sha256' => 'sha256:' . hash('sha256', $stderr),
            'stdout_log' => $result['stdout_log'],
            'stderr_log' => $result['stderr_log'],
        ];

        return $result;
    }

    private function writeReport(bool $failed): void
    {
        $this->mkdir(dirname($this->reportPath));
        $this->writeJson($this->reportPath, [
            'schema_version' => '2.0',
            'result' => $failed ? 'failed' : 'passed',
            'candidate_packages' => $this->candidateSelection->packages(),
            'release_set' => is_file($this->consumerRoot . '/composer.lock') ? $this->resolvedPackages() : [],
            'scenarios' => $this->scenarios,
            'front_door_journey' => $this->frontDoorJourneyReport(),
            'recovery_convergence' => $this->recoveryScenarios,
            'friction' => $this->friction,
            'platform' => [
                'php' => PHP_VERSION,
                'os_family' => PHP_OS_FAMILY,
            ],
        ]);
    }

    private function artifact(string $path): void
    {
        $this->assertFile($path);
        $target = $this->artifactRoot . '/evidence/' . basename($path);
        $this->mkdir(dirname($target));
        copy($path, $target);
    }

    private function assertProjectedHostInstructions(): void
    {
        $path = $this->consumerRoot . '/AGENTS.md';
        $this->assertFile($path);
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new ReleaseSetFailure('Projected host instructions are unreadable: ' . $path);
        }

        $beginMarker = '<!-- agent-loop:project-instructions:begin -->';
        $endMarker = '<!-- agent-loop:project-instructions:end -->';
        $begin = strpos($content, $beginMarker);
        $end = $begin === false
            ? false
            : strpos($content, $endMarker, $begin + strlen($beginMarker));
        if ($begin === false || $end === false) {
            throw new ReleaseSetFailure('Projected host instructions have invalid managed markers: ' . $path);
        }
        $managedContent = substr($content, $begin, ($end + strlen($endMarker)) - $begin);

        foreach ([
            'vendor/bin/agent-loop init status',
            'init sync-instructions',
        ] as $expected) {
            if (!str_contains($managedContent, $expected)) {
                throw new ReleaseSetFailure('Projected host instructions are missing release route: ' . $expected);
            }
        }

        $this->mustRun(['vendor/bin/agent-loop', 'init', 'status']);
        $this->mustRun([
            'vendor/bin/agent-loop', 'init', 'sync-instructions', '--agent=codex', '--dry-run',
        ]);
        $this->artifact($path);

        $manifestPath = $this->consumerRoot . '/.codex/skills/.agent-loop-manifest.json';
        $this->assertFile($manifestPath);
        $manifestEvidencePath = $this->artifactRoot . '/evidence/projected-codex-skills-manifest.json';
        if (!copy($manifestPath, $manifestEvidencePath)) {
            throw new ReleaseSetFailure('Unable to retain projected Codex skills manifest evidence.');
        }
    }

    private function assertFile(string $path): void
    {
        if (!is_file($path)) {
            throw new ReleaseSetFailure('Expected file is missing: ' . $path);
        }
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $this->assertFile($path);

        return $this->json((string) file_get_contents($path), $path);
    }

    /** @return array<string, mixed> */
    private function json(string $content, string $label): array
    {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ReleaseSetFailure($label . ' did not decode to an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->mkdir(dirname($path));
        file_put_contents($path, json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n");
    }

    private function reset(): void
    {
        $this->removeTree($this->workspace);
        $this->mkdir($this->workspace);
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new ReleaseSetFailure('Fixture directory is missing: ' . $source);
        }

        $source = rtrim($source, '/\\');
        $destination = rtrim($destination, '/\\');
        $this->mkdir($destination);
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relativePath;
            if ($item->isDir()) {
                $this->mkdir($target);
                continue;
            }

            $this->mkdir(dirname($target));
            if (!copy($item->getPathname(), $target)) {
                throw new ReleaseSetFailure('Unable to copy fixture path: ' . $item->getPathname());
            }
        }
    }

    private function removeTree(string $path): void
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

    private function mkdir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new ReleaseSetFailure('Unable to create directory: ' . $path);
        }
    }

    private function relativeWorkspace(string $path): string
    {
        return str_starts_with($path, $this->workspace . '/')
            ? substr($path, strlen($this->workspace) + 1)
            : $path;
    }
}

/** @return array{workspace: string, report: string, keep: bool, candidates: list<string>} */
function releaseSetOptions(array $argv): array
{
    $workspace = sys_get_temp_dir() . '/agent-loop-release-set-' . bin2hex(random_bytes(4));
    $report = getcwd() . '/build/release-set-report.json';
    $keep = false;
    $candidates = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--keep') {
            $keep = true;
            continue;
        }
        if (str_starts_with($argument, '--workspace=')) {
            $workspace = substr($argument, strlen('--workspace='));
            continue;
        }
        if (str_starts_with($argument, '--report=')) {
            $report = substr($argument, strlen('--report='));
            continue;
        }
        if (str_starts_with($argument, '--candidate=')) {
            $package = trim(substr($argument, strlen('--candidate=')));
            if ($package === '') {
                throw new InvalidArgumentException('--candidate requires a package name.');
            }
            $candidates[] = $package;
            continue;
        }
        throw new InvalidArgumentException('Unknown option: ' . $argument);
    }

    return [
        'workspace' => rtrim($workspace, '/'),
        'report' => $report,
        'keep' => $keep,
        'candidates' => $candidates,
    ];
}

$repositoryRoot = dirname(__DIR__);
try {
    exit((new ReleaseSetDogfood($repositoryRoot, releaseSetOptions($argv)))->run());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release-set dogfood bootstrap failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
