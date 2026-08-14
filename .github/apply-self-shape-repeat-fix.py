from pathlib import Path


def replace(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one replacement, got {count}')
    p.write_text(text.replace(old, new))


replace(
    'src/Workflow/WorkflowPlanCommand.php',
    """            if ($existing !== null && $this->activeSession($taskId->value) !== null) {
                throw new RuntimeException(
                    'Cannot revise Contract for ' . $taskId->value
                    . ' while a governed Session is active. Finish or drop that Run before changing durable intent.',
                );
            }
""",
    """            $activeSession = $existing !== null ? $this->activeSession($taskId->value) : null;
            if ($activeSession !== null) {
                throw new RuntimeException(
                    'Cannot revise Contract for ' . $taskId->value . ' while governed Session ' . $activeSession->id
                    . ' is active. Continue the existing Run, or if it is abandoned run `agent-loop session close '
                    . $activeSession->id . ' --status dropped` before changing durable intent.',
                );
            }
""",
)

replace(
    'src/Workflow/WorkflowApproveCommand.php',
    """        return (new SessionStore())->create(
            (new ProjectLayout($this->rootPath))->sessionsRoot(),
            $contract->taskId,
            null,
            $contract->plannedBy,
            $contract->baseCommit,
        );
""",
    """        return (new SessionStore())->create(
            (new ProjectLayout($this->rootPath))->sessionsRoot(),
            $contract->taskId,
            sprintf('%s-r%d-%s', $contract->taskId, $contract->revision, bin2hex(random_bytes(4))),
            $contract->plannedBy,
            $contract->baseCommit,
        );
""",
)

replace(
    'tools/self-shape-dogfood.php',
    "use voku\\AgentLoop\\Dogfood\\SelfShapeEvidence;\n",
    "use voku\\AgentLoop\\Dogfood\\SelfShapeEvidence;\nuse voku\\AgentLoop\\Dogfood\\SelfShapeRunRecovery;\nuse voku\\AgentLoop\\ProjectLayout;\nuse voku\\AgentSession\\SessionStore;\n",
)

replace(
    'tools/self-shape-dogfood.php',
    "$loop = static fn (array $arguments): array => $runner->mustRun([PHP_BINARY, 'bin/agent-loop', ...$arguments]);\n\n$base = $git(['merge-base', 'HEAD', 'origin/' . $options['base-ref']]);\n",
    "$loop = static fn (array $arguments): array => $runner->mustRun([PHP_BINARY, 'bin/agent-loop', ...$arguments]);\n\n$sessionToDrop = (new SelfShapeRunRecovery())->sessionToDrop(\n    (new SessionStore())->all((new ProjectLayout($root))->sessionsRoot()),\n    TASK,\n    PLANNER,\n);\nif ($sessionToDrop !== null) {\n    echo '[WARN] self-shape: dropping abandoned governed Session ' . $sessionToDrop . \\"\\n\\";\n    $loop(['session', 'close', $sessionToDrop, '--status', 'dropped']);\n}\n\n$base = $git(['merge-base', 'HEAD', 'origin/' . $options['base-ref']]);\n",
)

replace(
    '.github/workflows/ci.yml',
    """      - name: Govern this change with agent-loop itself
        run: php tools/self-shape-dogfood.php --base-ref=\"${{ github.base_ref }}\"
      - name: Upload self-shaping evidence
""",
    """      - name: Capture the opt-in guidance-gap diagnostic used for this dogfood
        run: |
          mkdir -p build
          php bin/agent-loop prompt guidance-gaps > build/self-shape-guidance-gaps.prompt.md
      - name: Govern this change twice with agent-loop itself
        run: |
          php tools/self-shape-dogfood.php --base-ref=\"${{ github.base_ref }}\"
          php tools/self-shape-dogfood.php --base-ref=\"${{ github.base_ref }}\"
      - name: Upload self-shaping evidence
""",
)

replace(
    '.github/workflows/ci.yml',
    """            build/self-shape-raw.diff
            .agent-loop/recall/SELF-SHAPE
""",
    """            build/self-shape-raw.diff
            build/self-shape-guidance-gaps.prompt.md
            .agent-loop/recall/SELF-SHAPE
""",
)

Path('src/Dogfood/SelfShapeRunRecovery.php').write_text(r'''<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use RuntimeException;
use voku\AgentSession\Session;

final class SelfShapeRunRecovery
{
    /** @param list<Session> $sessions */
    public function sessionToDrop(array $sessions, string $taskId, string $planner): ?string
    {
        $active = array_values(array_filter(
            $sessions,
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));

        if (count($active) > 1) {
            throw new RuntimeException('Self-shape recovery found multiple active Sessions for ' . $taskId . '.');
        }
        if ($active === []) {
            return null;
        }

        $session = $active[0];
        if ($session->claimedBy !== $planner) {
            throw new RuntimeException(sprintf(
                'Refusing to drop active self-shape Session %s claimed by %s; expected %s.',
                $session->id,
                $session->claimedBy ?? 'nobody',
                $planner,
            ));
        }

        return $session->id;
    }
}
''')

Path('tests/SelfShapeRunRecoveryTest.php').write_text(r'''<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Dogfood\SelfShapeRunRecovery;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;

final class SelfShapeRunRecoveryTest extends TestCase
{
    public function testOwnActiveSessionCanBeDroppedBeforeARepeatRun(): void
    {
        self::assertSame(
            '2026-08-14-self-shape',
            (new SelfShapeRunRecovery())->sessionToDrop([
                $this->session('2026-08-14-self-shape', 'SELF-SHAPE', SessionStatus::ACTIVE, 'agent-loop-self-shape'),
            ], 'SELF-SHAPE', 'agent-loop-self-shape'),
        );
    }

    public function testClosedAndOtherTaskSessionsNeedNoRecovery(): void
    {
        self::assertNull((new SelfShapeRunRecovery())->sessionToDrop([
            $this->session('closed', 'SELF-SHAPE', SessionStatus::DONE, 'agent-loop-self-shape'),
            $this->session('other', 'OTHER', SessionStatus::ACTIVE, 'agent-loop-self-shape'),
        ], 'SELF-SHAPE', 'agent-loop-self-shape'));
    }

    public function testForeignActiveSessionIsNeverAutoDropped(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to drop active self-shape Session');

        (new SelfShapeRunRecovery())->sessionToDrop([
            $this->session('manual', 'SELF-SHAPE', SessionStatus::ACTIVE, 'human-maintainer'),
        ], 'SELF-SHAPE', 'agent-loop-self-shape');
    }

    private function session(string $id, string $taskId, SessionStatus $status, ?string $claimedBy): Session
    {
        return new Session(
            $id,
            $taskId,
            $status,
            $claimedBy,
            null,
            null,
            '2026-08-14T12:00:00+00:00',
            '2026-08-14T12:00:00+00:00',
            [],
            '/tmp/' . $id,
        );
    }
}
''')

Path('tests/WorkflowRepeatRunTest.php').write_text(r'''<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class WorkflowRepeatRunTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-repeat-run-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testNewContractRevisionAfterPruneGetsFreshSessionIdentity(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('SELF-SHAPE', 'Govern the first candidate.', ['src/Foo.php'], [], ['composer ci'], 'agent-loop-self-shape');
        $approve = new WorkflowApproveCommand($this->root, static fn (array $argv): int => 0);

        ob_start();
        self::assertSame(0, $approve->run(['SELF-SHAPE', '--by', 'fixture-approver']));
        ob_end_clean();

        $sessions = new SessionStore();
        $firstSessions = $sessions->all($this->root . '/.agent-loop/sessions');
        self::assertCount(1, $firstSessions);
        $firstSession = $firstSessions[0];
        $firstRun = (new GovernedRunStore($this->root))->find('SELF-SHAPE');
        self::assertNotNull($firstRun);

        $sessions->setStatus($firstSession, SessionStatus::DONE);
        self::assertSame([$firstSession->id], $sessions->prune($this->root . '/.agent-loop/sessions', 0, [SessionStatus::DONE]));

        $contracts->revise('SELF-SHAPE', 'Govern the second candidate.', ['src/Foo.php'], [], ['composer ci'], 'agent-loop-self-shape');

        ob_start();
        self::assertSame(0, $approve->run(['SELF-SHAPE', '--by', 'fixture-approver']));
        ob_end_clean();

        $secondSessions = $sessions->all($this->root . '/.agent-loop/sessions');
        self::assertCount(1, $secondSessions);
        $secondSession = $secondSessions[0];
        self::assertNotSame($firstSession->id, $secondSession->id);

        $secondRun = (new GovernedRunStore($this->root))->find('SELF-SHAPE');
        self::assertNotNull($secondRun);
        self::assertNotSame($firstRun->runId, $secondRun->runId);
        self::assertSame(2, $secondRun->contractRevision);
        self::assertSame($secondSession->id, $secondRun->sessionId);
        self::assertCount(1, glob($this->root . '/.agent-loop/runs/SELF-SHAPE/history/*/run.json') ?: []);
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
''')

Path('.github/workflows/apply-self-shape-repeat-fix.yml').unlink(missing_ok=True)
Path('.github/apply-self-shape-repeat-fix.py').unlink(missing_ok=True)
