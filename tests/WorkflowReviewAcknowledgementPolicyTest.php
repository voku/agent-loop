<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunPolicyEvaluator;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCloseCommand;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowReviewAcknowledgementPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-review-ack-policy-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRegeneratedReportInvalidatesOldAcknowledgementForSameImplementation(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ACK-1',
            'Bind review completion to exact report identity.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contract = $contracts->approve('ACK-1', 'fixture-approver');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ACK-1', by: 'fixture-agent');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -r "exit(0);"',
            ValidationStatus::PASSED,
            0,
            1,
            'fixture-agent',
            implementationSnapshot: $snapshot->digest,
        );
        $this->writeRecallMeta();
        $this->writeReview($contract->revision, $snapshot->digest, []);

        $reader = new WorkflowReviewReportReader($this->root);
        $first = $reader->read('ACK-1');
        self::assertSame('unacknowledged', $first['status']);
        self::assertNotNull($first['sha256']);

        (new ReviewAcknowledgementStore($this->root))->record(
            $run,
            $contract,
            $snapshot,
            $first['sha256'],
            'fixture-reviewer',
        );
        self::assertSame('ok', $reader->read('ACK-1')['status']);

        $this->writeReview($contract->revision, $snapshot->digest, [[
            'id' => 'new_information',
            'severity' => 'INFO',
            'message' => 'The regenerated report contains new persisted information.',
            'evidence' => ['Same implementation, different report bytes.'],
        ]]);

        $second = $reader->read('ACK-1');
        self::assertSame('unacknowledged', $second['status']);
        self::assertNotNull($second['sha256']);
        self::assertNotSame($first['sha256'], $second['sha256']);

        $manifest = (new RunManifestProjector($this->root))->project('ACK-1');
        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
        self::assertFalse($policy->mutationAllowed);
        self::assertSame(
            'agent-loop finish ACK-1 --reviewed-report-sha256 ' . $second['sha256'] . ' --by <actor>',
            $policy->nextAction,
        );

        ob_start();
        $closeExit = (new WorkflowCloseCommand($this->root))->run(['ACK-1', '--status', 'done']);
        $closeOutput = (string) ob_get_clean();
        self::assertSame(1, $closeExit);
        self::assertStringContainsString('not been acknowledged by exact current report identity', $closeOutput);
        self::assertStringContainsString((string) $second['sha256'], $closeOutput);
    }

    private function writeRecallMeta(): void
    {
        $directory = $this->root . '/.agent-loop/recall/ACK-1';
        mkdir($directory, 0o775, true);
        file_put_contents($directory . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => 'ACK-1',
            'compilation_id' => 'ack-1-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<array{id:string,severity:string,message:string,evidence:list<string>}> $findings */
    private function writeReview(int $contractRevision, string $implementationSnapshot, array $findings): void
    {
        $directory = $this->root . '/.agent-loop/recall/ACK-1/reviews';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review fixture directory.');
        }
        file_put_contents($directory . '/ACK-1.blindspots.json', json_encode([
            'version' => 2,
            'task_id' => 'ACK-1',
            'status' => 'ok',
            'contract_revision' => $contractRevision,
            'implementation_snapshot' => $implementationSnapshot,
            'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
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
