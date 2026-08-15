<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\PostExecutionEvidenceBoundary;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

/**
 * A completed Run must stay explainable after the project moves.
 *
 * Checkouts live at different absolute paths on every machine, CI runner and
 * container. Anything a Run persists about *where* things are is therefore only
 * durable if it is expressed relative to the project, or resolved through the
 * project's own configuration at read time. An absolute path baked into
 * run.json is machine-local truth masquerading as durable truth: it survives
 * Session pruning and then breaks the moment the repository is cloned
 * somewhere else.
 */
final class GovernedRunPortabilityTest extends TestCase
{
    private string $root;

    private string $relocated;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/agent-loop-origin-' . $suffix;
        $this->relocated = sys_get_temp_dir() . '/agent-loop-moved-' . $suffix;
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
        $this->rm($this->relocated);
    }

    public function testCompletedRunStaysCompleteAfterTheProjectIsRelocated(): void
    {
        $this->writeConfig(['learning_root' => 'learning-root']);
        $this->completeGovernedRun('learning-root');
        $expected = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($expected);

        $this->relocate();

        $moved = (new GovernedRunStore($this->relocated))->find('ABC-123');
        self::assertNotNull($moved, 'The Run artifact did not survive relocation.');
        self::assertSame($expected->runId, $moved->runId, 'Relocation must not change Run identity.');

        $projected = (new RunManifestProjector($this->relocated))->project('ABC-123');

        self::assertSame($expected->runId, $projected->runId);
        self::assertSame([], $projected->disagreements, 'Relocation introduced an unexplained disagreement.');
        self::assertSame('current', $projected->references['approval']['state'] ?? null);
        self::assertSame('passed', $projected->references['verification']['state'] ?? null);
        self::assertSame(
            'decided',
            $projected->references['learning']['state'] ?? null,
            'The durable Learning close-out became unresolvable after the project moved.',
        );
        self::assertSame('missing', $projected->references['session']['state'] ?? null);
        self::assertSame('complete', $projected->state, 'A completed Run must stay complete after relocation.');
        self::assertSame('none', $projected->nextAction);
    }

    public function testRunNeverPersistsAnAbsoluteMachineLocalLearningRoot(): void
    {
        $external = sys_get_temp_dir() . '/agent-loop-external-learning-' . bin2hex(random_bytes(4));
        mkdir($external, 0o775, true);

        try {
            $this->writeConfig(['learning_root' => $external]);
            $this->completeGovernedRun($external, configured: true);

            $raw = json_decode(
                (string) file_get_contents($this->root . '/.agent-loop/runs/ABC-123/run.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($raw);
            self::assertArrayHasKey('learning_root', $raw);
            self::assertNull(
                $raw['learning_root'],
                'run.json persisted an absolute machine-local Learning path as durable Run truth.',
            );

            $this->relocate();
            $this->writeConfig(['learning_root' => $external], $this->relocated);

            $projected = (new RunManifestProjector($this->relocated))->project('ABC-123');
            self::assertSame('decided', $projected->references['learning']['state'] ?? null);
            self::assertSame('complete', $projected->state);
        } finally {
            $this->rm($external);
        }
    }

    private function completeGovernedRun(string $learningRoot, bool $configured = false): void
    {
        $absoluteLearningRoot = $configured ? $learningRoot : $this->root . '/' . $learningRoot;
        if (!is_dir($absoluteLearningRoot)) {
            mkdir($absoluteLearningRoot, 0o775, true);
        }

        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Survive relocation.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');

        $approve = new WorkflowApproveCommand($this->root, function (array $argv): int {
            $this->writeRecallMeta();

            return 0;
        });
        ob_start();
        self::assertSame(0, $approve->run(['ABC-123', '--by', 'lars']));
        ob_end_clean();

        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");

        $contract = $contracts->load('ABC-123');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $session = (new SessionStore())->all($this->root . '/.agent-loop/sessions')[0];
        $run = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($run);

        (new ValidationEvidenceStore())->record(
            $session,
            1,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            11,
            'lars',
            implementationSnapshot: $snapshot->digest,
        );
        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode([
                'status' => 'ok',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
            ], JSON_THROW_ON_ERROR),
        );
        $boundary = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationSha256 = $boundary->validationEvidenceSha256();
        $reviewSha256 = $boundary->reviewEvidenceSha256();
        self::assertNotNull($validationSha256);
        self::assertNotNull($reviewSha256);
        (new RunLearningDecisionStore($absoluteLearningRoot))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'Relocation proof.',
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
            validationEvidenceSha256: $validationSha256,
            reviewEvidenceSha256: $reviewSha256,
        );

        $cli = new WorkflowCli($this->root, static fn (array $argv): int => 0);
        ob_start();
        $exit = $cli->run(['close', 'ABC-123', '--status', 'done']);
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit, $output);

        (new SessionStore())->prune($this->root . '/.agent-loop/sessions', 0, [SessionStatus::DONE]);
    }

    /** Copies the whole project to a different absolute path, as a clone would. */
    private function relocate(): void
    {
        $this->copyTree($this->root, $this->relocated);
        $this->rm($this->root);
        self::assertDirectoryDoesNotExist($this->root);
    }

    /** @param array<string, string> $paths */
    private function writeConfig(array $paths, ?string $root = null): void
    {
        $root ??= $this->root;
        if (!is_dir($root . '/.agent-loop')) {
            mkdir($root . '/.agent-loop', 0o775, true);
        }
        file_put_contents(
            $root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'paths' => $paths], JSON_THROW_ON_ERROR),
        );
    }

    private function writeRecallMeta(): void
    {
        mkdir($this->root . '/.agent-loop/recall/ABC-123', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-relocation',
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function copyTree(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
            self::fail('Unable to create relocation target: ' . $target);
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $target . '/' . $entry;
            is_dir($from) ? $this->copyTree($from, $to) : copy($from, $to);
        }
    }

    private function rm(string $path): void
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
