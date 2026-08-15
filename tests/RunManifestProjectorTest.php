<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class RunManifestProjectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-run-projector-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testIncompleteLegacyRunDoesNotInventMissingIdentity(): void
    {
        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('task:ABC-123:legacy', $manifest->runId);
        self::assertSame('legacy_inferred', $manifest->mode);
        self::assertSame('incomplete', $manifest->state);
        self::assertSame('missing', $manifest->references['session']['state']);
        self::assertSame('missing', $manifest->references['contract']['state']);
        self::assertSame('missing', $manifest->references['recall']['state']);
        self::assertSame([], $manifest->disagreements);
        self::assertStringContainsString('workflow plan ABC-123', $manifest->nextAction);
    }

    public function testMapAndSearchReadinessAreProjectedFromAgentMapOwnerFacts(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        $hash = hash_file('sha256', $this->root . '/src/Foo.php');
        self::assertIsString($hash);
        (new IndexWriter())->write(
            new AgentMapIndex(
                schemaVersion: '2.0',
                root: $this->root,
                backend: 'test',
                files: [new FileEntry('src/Foo.php', 'sha256:' . $hash, '', [])],
                fingerprint: new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:current'),
            ),
            $this->root . '/.agent-loop/map/php-symbols.json',
        );
        $pdo = new PDO(
            'sqlite:' . $this->root . '/.agent-loop/map/search.sqlite',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('CREATE TABLE search_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO search_meta (key, value) VALUES ('map_snapshot', 'sha256:older')");
        unset($pdo);

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('ready', $manifest->references['map']['state']);
        self::assertSame('sha256:current', $manifest->references['map']['snapshot']);
        self::assertSame('stale', $manifest->references['search_index']['state']);
        self::assertSame('sha256:older', $manifest->references['search_index']['snapshot']);

        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class ChangedFoo {}\n");
        $stale = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('stale', $stale->references['map']['state']);
        self::assertSame('src/Foo.php', $stale->references['map']['stale_entries'][0]['path']);
        self::assertSame('unavailable', $stale->references['search_index']['state']);
    }

    public function testMetadataOnlyScaffoldBoardIsLinkedThroughAgentKanbanResolution(): void
    {
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/todo/board.md',
            "# Board Metadata\n\n- **Project prefix:** ABC\n",
        );
        file_put_contents($this->root . '/.agent-loop/todo/cards/ABC-123.md', <<<'MD'
# ABC-123: Metadata-only board

- **Ticket:** ABC-123
- **Lane:** READY
- **Status:** Selected

## Agent Task Brief

Prove that agent-loop uses agent-kanban's canonical board resolution.
MD
            . "\n");

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('linked', $manifest->references['board']['state']);
        self::assertSame('READY', $manifest->references['board']['lane']);
        self::assertSame('Selected', $manifest->references['board']['status']);
        self::assertSame('.agent-loop/todo/cards/ABC-123.md', $manifest->references['board']['source']['path']);
        self::assertSame('metadata', $manifest->references['board']['configuration']['mode']);
        self::assertSame(
            '.agent-loop/todo/board.md',
            $manifest->references['board']['configuration']['source']['path'],
        );
    }

    public function testCompletedRunIsTraceableThroughDurableOwningArtifacts(): void
    {
        [$sessions, $session, $runId] = $this->preparedRun('ok', withReceipt: true);
        $sessions->setStatus($session, SessionStatus::DONE);

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame($runId, $manifest->runId);
        self::assertSame('governed', $manifest->mode);
        self::assertSame('complete', $manifest->state);
        self::assertSame('approved', $manifest->references['contract']['state']);
        self::assertSame('current', $manifest->references['approval']['state']);
        self::assertSame('compiled', $manifest->references['recall']['state']);
        self::assertSame('ok', $manifest->references['review']['state']);
        self::assertSame('decided', $manifest->references['learning']['state']);
        self::assertSame('passed', $manifest->references['verification']['state']);
        self::assertSame('none', $manifest->nextAction);
        self::assertSame([], $manifest->disagreements);
    }

    public function testFailedReviewCannotProduceCompletedRunOrCloseAction(): void
    {
        [, , $runId] = $this->preparedRun('fail', withReceipt: true);

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame($runId, $manifest->runId);
        self::assertSame('blocked', $manifest->state);
        self::assertSame('fail', $manifest->references['review']['state']);
        self::assertSame('agent-loop review blindspots ABC-123', $manifest->nextAction);
    }

    public function testContractRevisionDisagreementBlocksFalseGreenProjection(): void
    {
        $this->preparedRun('ok', withReceipt: false);
        $contracts = new TaskContractStore($this->root);
        $current = $contracts->load('ABC-123');
        $contracts->revise(
            'ABC-123',
            'Changed after Run preparation.',
            $current->scope,
            $current->nonGoals,
            $current->validation,
            'lars',
        );

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('blocked', $manifest->state);
        self::assertSame('candidate', $manifest->references['contract']['state']);
        self::assertSame('run.contract_revision_mismatch', $manifest->disagreements[0]['code']);
        self::assertStringContainsString('workflow manifest ABC-123 --format=json', $manifest->nextAction);
    }

    /** @return array{0: SessionStore, 1: Session, 2: string} */
    private function preparedRun(string $reviewStatus, bool $withReceipt): array
    {
        if (!is_dir($this->root . '/src')) {
            mkdir($this->root . '/src', 0o775, true);
        }
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Prove the completed projection.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'lars',
        );
        $contract = $contracts->approve('ABC-123', 'lars');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-001',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => $reviewStatus], JSON_THROW_ON_ERROR),
        );
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable learning from projector fixture.',
        );

        if ($withReceipt) {
            (new RunVerificationReceiptStore($this->root))->record(
                $run,
                $contract,
                $session,
                $snapshot->digest,
                'satisfied',
                [[
                    'command' => 'vendor/bin/phpunit',
                    'status' => 'passed',
                    'exit_code' => 0,
                    'executed_at' => '2026-08-10T20:00:00+00:00',
                    'recorded_by' => 'lars',
                    'duration_ms' => 10,
                ]],
            );
        }

        return [$sessions, $session, $run->runId];
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
