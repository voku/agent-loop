<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentMap\Search\SearchMaintenanceRequest;
use voku\AgentMap\Search\SearchMaintenanceService;

final class WorkflowPrePlanDiscoveryProjectionTest extends TestCase
{
    private const string REQUEST = 'Project deterministic discovery evidence before PLAN';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-pre-plan-discovery-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create test root: ' . $this->root);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testEnterProjectsFreshBoundedUnverifiedEvidenceWithoutChangingPlanOrWorkflowState(): void
    {
        [$mapSnapshot, $searchSnapshot] = $this->prepareMapAndSearch();
        $before = $this->snapshotFiles();

        $baseline = $this->enter('PREPLAN-QUERY');
        $withRequest = $this->enter('PREPLAN-QUERY', ['--request', self::REQUEST]);

        self::assertSame(1, $baseline['exit']);
        self::assertSame(1, $withRequest['exit']);
        self::assertSame($baseline['payload']['next_action'], $withRequest['payload']['next_action']);
        self::assertSame('command_template', $withRequest['payload']['next_action_kind']);
        self::assertFalse($withRequest['payload']['mutation_ready']);
        self::assertArrayNotHasKey('references', $baseline['payload']);

        $discovery = $withRequest['payload']['references']['discovery'] ?? null;
        self::assertIsArray($discovery);
        self::assertSame('agent-map', $discovery['owner'] ?? null);
        self::assertSame('ready', $discovery['status'] ?? null);
        self::assertSame('current_navigation_evidence', $discovery['evidence_status'] ?? null);
        self::assertSame('none', $discovery['authority'] ?? null);
        self::assertSame($mapSnapshot, $discovery['map_snapshot'] ?? null);
        self::assertSame($searchSnapshot, $discovery['search_snapshot'] ?? null);
        self::assertLessThanOrEqual(5, count($discovery['candidates'] ?? []));
        self::assertNotEmpty($discovery['candidates'] ?? []);
        foreach ($discovery['candidates'] as $candidate) {
            self::assertSame('ranked_unverified', $candidate['status'] ?? null);
        }
        self::assertLessThanOrEqual(12, count($discovery['structural_context'] ?? []));
        self::assertArrayNotHasKey('scope', $discovery);
        self::assertNull((new TaskContractStore($this->root))->find('PREPLAN-QUERY'));
        self::assertSame($before, $this->snapshotFiles(), 'enter must not create or change workflow state.');
    }

    public function testMissingMapAbstainsAndKeepsTheCanonicalPlanAction(): void
    {
        $baseline = $this->enter('PREPLAN-MISSING');
        $withRequest = $this->enter('PREPLAN-MISSING', ['--request', self::REQUEST]);
        $discovery = $withRequest['payload']['references']['discovery'] ?? [];

        self::assertSame($baseline['payload']['next_action'], $withRequest['payload']['next_action']);
        self::assertSame('abstained', $discovery['status'] ?? null);
        self::assertSame('map_not_current', $discovery['reason'] ?? null);
        self::assertSame('missing', $discovery['map_state'] ?? null);
        self::assertNull((new TaskContractStore($this->root))->find('PREPLAN-MISSING'));
    }

    public function testStaleMapAndMissingOrStaleSearchCannotBePresentedAsCurrent(): void
    {
        $this->prepareMapAndSearch();
        file_put_contents($this->root . '/src/DiscoveryFixture.php', "\n// changed after map build\n", FILE_APPEND);
        $staleMap = $this->enter('PREPLAN-STALE-MAP', ['--request', self::REQUEST])['payload']['references']['discovery'] ?? [];
        self::assertSame('abstained', $staleMap['status'] ?? null);
        self::assertSame('map_not_current', $staleMap['reason'] ?? null);
        self::assertSame('stale', $staleMap['map_state'] ?? null);

        $this->removeTree($this->root);
        $this->root = sys_get_temp_dir() . '/agent-loop-pre-plan-discovery-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to recreate test root: ' . $this->root);
        }
        $this->prepareMap();
        $missingSearch = $this->enter('PREPLAN-MISSING-SEARCH', ['--request', self::REQUEST])['payload']['references']['discovery'] ?? [];
        self::assertSame('abstained', $missingSearch['status'] ?? null);
        self::assertSame('search_not_current', $missingSearch['reason'] ?? null);
        self::assertSame('missing', $missingSearch['search_state'] ?? null);

        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        $search = new SearchIndexStore($artifacts->searchDatabase());
        $search->setMeta('map_snapshot', 'sha256:deliberately-stale');
        $staleSearch = $this->enter('PREPLAN-STALE-SEARCH', ['--request', self::REQUEST])['payload']['references']['discovery'] ?? [];
        self::assertSame('abstained', $staleSearch['status'] ?? null);
        self::assertSame('search_not_current', $staleSearch['reason'] ?? null);
        self::assertSame('stale', $staleSearch['search_state'] ?? null);
    }

    public function testInvalidOwnerArtifactsAndEmptySearchResultsAbstain(): void
    {
        $this->prepareMapAndSearch();
        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        file_put_contents($artifacts->searchDatabase(), 'not a sqlite database');
        $invalidSearch = $this->enter('PREPLAN-INVALID-SEARCH', ['--request', self::REQUEST])['payload']['references']['discovery'] ?? [];
        self::assertSame('abstained', $invalidSearch['status'] ?? null);
        self::assertSame('search_not_current', $invalidSearch['reason'] ?? null);
        self::assertSame('invalid', $invalidSearch['search_state'] ?? null);

        $this->removeTree($this->root);
        $this->root = sys_get_temp_dir() . '/agent-loop-pre-plan-discovery-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to recreate test root: ' . $this->root);
        }
        $this->prepareMapAndSearch();
        $empty = $this->enter('PREPLAN-EMPTY-RESULTS', ['--request', 'unmatchable zzemptytoken qxvplm'])['payload']['references']['discovery'] ?? [];
        self::assertSame('abstained', $empty['status'] ?? null);
        self::assertSame('no_ranked_candidates', $empty['reason'] ?? null);

        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        file_put_contents($artifacts->indexJson(), '{');
        $invalidMap = $this->enter('PREPLAN-INVALID-MAP', ['--request', self::REQUEST])['payload']['references']['discovery'] ?? [];
        self::assertSame('abstained', $invalidMap['status'] ?? null);
        self::assertSame('map_not_current', $invalidMap['reason'] ?? null);
        self::assertSame('invalid', $invalidMap['map_state'] ?? null);
    }

    public function testEmptyRequestAbstainsAndNonPlanLifecycleActionsDoNotReceiveDiscovery(): void
    {
        $empty = $this->enter('PREPLAN-EMPTY', ['--request', '   ']);
        self::assertSame(1, $empty['exit']);
        self::assertSame('abstained', $empty['payload']['references']['discovery']['status'] ?? null);
        self::assertSame('request_empty', $empty['payload']['references']['discovery']['reason'] ?? null);

        // A task with a candidate Contract has an approval action, not the first
        // PLAN template, so discovery must not be attached to that authority gate.
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'PREPLAN-DECISION',
            'Unrelated approved-boundary fixture.',
            ['src/DiscoveryFixture.php'],
            [],
            ['php -l src/DiscoveryFixture.php'],
            'fixture-planner',
        );
        $decision = $this->enter('PREPLAN-DECISION', ['--request', self::REQUEST])['payload'];
        self::assertSame('decision_required', $decision['next_action_kind']);
        self::assertArrayNotHasKey('references', $decision);
    }

    /** @return array{0: string, 1: string} */
    private function prepareMapAndSearch(): array
    {
        [$index, $artifacts] = $this->prepareMap();
        new SearchIndexStore($artifacts->searchDatabase());
        $maintenance = (new SearchMaintenanceService())->refreshIfPresent(new SearchMaintenanceRequest($index, $artifacts));
        self::assertContains($maintenance->state, ['current', 'refreshed']);
        $fingerprint = $index->fingerprint;
        if ($fingerprint === null) {
            throw new RuntimeException('Prepared test map has no source fingerprint.');
        }

        return [
            $fingerprint->sourceDigest,
            $maintenance->searchSnapshot ?? '',
        ];
    }

    /** @return array{0: \voku\AgentMap\Index\AgentMapIndex, 1: MapArtifactPaths} */
    private function prepareMap(): array
    {
        $source = $this->root . '/src';
        if (!is_dir($source) && !mkdir($source, 0o775, true) && !is_dir($source)) {
            throw new RuntimeException('Unable to create test source directory.');
        }
        file_put_contents($source . '/DiscoveryFixture.php', <<<'PHP'
<?php

declare(strict_types=1);

/** Deterministic discovery evidence for the pre plan scope projection fixture. */
final class DiscoveryFixture
{
    /** Project deterministic discovery evidence before PLAN. */
    public function projectPrePlanDiscoveryEvidence(): string
    {
        return 'deterministic discovery evidence';
    }
}
PHP);

        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        $prepared = (new MapPreparationService())->prepare(new MapPreparationRequest(
            root: $this->root,
            indexPath: $artifacts->indexJson(),
            outputPath: $artifacts->indexJson(),
            format: 'json',
            paths: ['src'],
            pathsProvided: true,
            scanPaths: [],
            scanPathsProvided: false,
            excludes: [],
            excludesProvided: false,
            backend: 'structural',
            phpStanConfig: null,
            phpStanMemoryLimit: null,
            artifacts: $artifacts,
        ));

        return [$prepared->index, $artifacts];
    }

    /** @param list<string> $options
     * @return array{exit: int, stdout: string, stderr: string, payload: array<string, mixed>}
     */
    private function enter(string $taskId, array $options = []): array
    {
        ob_start();
        $exit = (new HostFrontDoorCommand($this->root))->run('enter', [$taskId, ...$options, '--format=json']);
        $stdout = ob_get_contents();
        ob_end_clean();
        if (!is_string($stdout)) {
            throw new RuntimeException('Unable to read enter command output.');
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Enter command output is not a JSON object.');
        }

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => '',
            'payload' => $payload,
        ];
    }

    /** @return array<string, string> */
    private function snapshotFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = PathResolver::relativeTo($this->root, $file->getPathname());
            $files[$path] = hash_file('sha256', $file->getPathname()) ?: '';
        }
        ksort($files);

        return $files;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($path);
    }
}
