<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AgentLearningProposalQueueCrossUsageTest extends TestCase
{
    private const string WORDING = 'Keep lifecycle authority in the universal entry point and route conditional detail through the owning skill.';

    private const string SKILL_PATH = 'resources/skills/agent-loop-discipline/SKILL.md';

    private string $projectRoot;

    private string $learningRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/agent-learning-queue-cross-usage-' . bin2hex(random_bytes(8));
        $this->learningRoot = $this->projectRoot . '/.agent-loop/learning';

        foreach (['candidate', 'approved', 'rejected'] as $status) {
            $this->createDirectory($this->learningRoot . '/proposals/' . $status);
        }
        $this->createDirectory($this->learningRoot . '/findings/validated');
        $this->createDirectory($this->learningRoot . '/history');
        $this->createDirectory(dirname($this->projectRoot . '/' . self::SKILL_PATH));
        file_put_contents($this->projectRoot . '/' . self::SKILL_PATH, "# Agent Loop Discipline\n\n" . self::WORDING . "\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testLearnProposalQueueResolvesSemanticSkillTargetThroughItsScopedSkillFile(): void
    {
        $this->writeProposal('proposal.2026-10-24.000001', 'agent-loop-discipline', [self::SKILL_PATH]);

        [$exitCode, $stdout, $stderr] = $this->runProposalQueue();
        $rows = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode, $stderr);
        self::assertCount(1, $rows);
        self::assertSame('proposal.2026-10-24.000001', $rows[0]['id']);
        self::assertNotContains('target_file_missing:agent-loop-discipline', $rows[0]['signals']);
        self::assertNotContains('target_source_unresolved:agent-loop-discipline', $rows[0]['signals']);
        self::assertSame(self::SKILL_PATH, $rows[0]['wording_matches'][0]['file']);
        self::assertTrue($rows[0]['wording_matches'][0]['exact']);
        self::assertSame(100, $rows[0]['wording_matches'][0]['containment_percent']);
    }

    public function testLearnProposalQueueReportsUnmappedSemanticTargetWithoutCallingItAMissingFile(): void
    {
        $this->writeProposal('proposal.2026-10-24.000002', 'skill.unmapped', ['docs/missing-skill.md']);

        [$exitCode, $stdout, $stderr] = $this->runProposalQueue();
        $rows = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode, $stderr);
        self::assertCount(1, $rows);
        self::assertContains('scope_path_missing:docs/missing-skill.md', $rows[0]['signals']);
        self::assertContains('target_source_unresolved:skill.unmapped', $rows[0]['signals']);
        self::assertNotContains('target_file_missing:skill.unmapped', $rows[0]['signals']);
        self::assertSame([], $rows[0]['wording_matches']);
    }

    public function testLearnProposalQueueLeavesAmbiguousSemanticTargetUnresolved(): void
    {
        $secondSkillPath = 'resources/skills/agent-loop-security/SKILL.md';
        $this->createDirectory(dirname($this->projectRoot . '/' . $secondSkillPath));
        file_put_contents($this->projectRoot . '/' . $secondSkillPath, "# Agent Loop Security\n\n" . self::WORDING . "\n");
        $this->writeProposal('proposal.2026-10-24.000003', 'agent-loop-discipline', [self::SKILL_PATH, $secondSkillPath]);

        [$exitCode, $stdout, $stderr] = $this->runProposalQueue();
        $rows = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode, $stderr);
        self::assertCount(1, $rows);
        self::assertContains('target_source_unresolved:agent-loop-discipline', $rows[0]['signals']);
        self::assertNotContains('target_file_missing:agent-loop-discipline', $rows[0]['signals']);
        self::assertSame([], $rows[0]['wording_matches']);
    }

    /** @param list<string> $scope */
    private function writeProposal(string $proposalId, string $target, array $scope): void
    {
        $proposal = [
            'id' => $proposalId,
            'created_at' => '2026-10-24T12:00:00+00:00',
            'source_findings' => ['finding.2026-10-24.000001'],
            'remaining_uncertainty' => [],
            'status' => 'candidate',
            'proposed_by' => 'cross_usage_test',
            'approved_by' => null,
            'approved_at' => null,
            'existing_guidance_id' => null,
            'action' => 'ADD',
            'reason' => 'A repeated behavior needs one reviewable owner rule.',
            'target_type' => 'skill',
            'target' => $target,
            'scope' => $scope,
            'old' => null,
            'new' => self::WORDING,
            'boundary' => 'Keep universal lifecycle authority in the entry point.',
            'validation' => ['composer ci'],
            'scope_justification' => 'The scoped skill file is the physical owner for this semantic target.',
            'learning_decision' => 'UPDATE_SKILL',
            'pattern_key' => 'agent.loop.semantic.skill.target',
            'validation_case' => [
                'given' => 'a semantic skill target with one scoped SKILL.md',
                'when' => 'the proposal review queue is rendered',
                'then' => 'the wording match names that scoped file',
            ],
        ];

        $path = $this->learningRoot . '/proposals/candidate/' . $proposalId . '.json';
        $json = json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Unable to write proposal fixture: ' . $path);
        }
    }

    /** @return array{int, string, string} */
    private function runProposalQueue(): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/agent-loop',
            'learn',
            'proposal-queue',
            '--root',
            $this->learningRoot,
            '--project-root',
            $this->projectRoot,
            '--format',
            'json',
        ];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-loop proposal-queue command.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to read agent-loop proposal-queue output.');
        }

        return [$exitCode, $stdout, $stderr];
    }

    private function createDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $pathname = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                rmdir($pathname);
                continue;
            }

            unlink($pathname);
        }

        rmdir($path);
    }
}
