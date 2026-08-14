<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Edit\Verify\ChecklistValidator;
use voku\AgentLoop\Edit\Verify\VerificationBundle;

/**
 * What counts as a resolved checklist reference.
 *
 * The validator promises three categories: a file in the bundle, a changed file, or
 * an id the plan itself declared. It closed with a bare `is_file($reference)`, which
 * accepts any readable path on the machine - so `/etc/hostname` "resolved" and a
 * required `human_review` item passed on evidence belonging to no task at all. The
 * gate exists precisely because a program cannot judge whether a review happened;
 * the one thing it can check is that the artifact is real and is *this* task's.
 *
 * @internal
 */
final class ChecklistEvidenceBoundaryTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private const array CHECKLIST = [[
        'id' => 'C1',
        'verifier' => 'human_review',
        'required' => true,
        'evidence_ids' => ['review:pairing-notes'],
    ]];

    private string $root;
    private string $bundleDirectory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-evidence-' . bin2hex(random_bytes(6));
        $this->bundleDirectory = $this->root . '/project/.agent-loop/edit/EV-1';
        $recallDirectory = $this->root . '/project/.agent-loop/recall/EV-1';
        mkdir($this->bundleDirectory, 0o775, true);
        mkdir($recallDirectory, 0o775, true);
        mkdir($this->root . '/project/src', 0o775, true);
        mkdir($this->root . '/outside', 0o775, true);

        file_put_contents($this->root . '/outside/unrelated.txt', "not this task's evidence\n");
        file_put_contents($this->root . '/project/src/Changed.php', "<?php\n");
        file_put_contents($this->bundleDirectory . '/diff.patch', "--- a\n+++ b\n");

        $plan = [
            'task_id' => 'EV-1',
            'target' => 'Demo\\Service::save',
            'checklist' => self::CHECKLIST,
            'knowledge_probes' => [],
            'changed_files' => ['src/Changed.php'],
        ];
        $planRaw = (string) json_encode($plan, \JSON_PRETTY_PRINT);
        file_put_contents($recallDirectory . '/verification-plan.json', $planRaw);

        $planSha = 'sha256:' . hash('sha256', $planRaw);
        file_put_contents($recallDirectory . '/verification-key.json', (string) json_encode([
            'plan_sha256' => $planSha,
            'target' => 'Demo\\Service::save',
            'probes' => [],
        ]));
        file_put_contents($this->bundleDirectory . '/execution.json', (string) json_encode([
            'task_id' => 'EV-1',
            'artifacts' => ['recall' => $recallDirectory],
        ]));
        file_put_contents($this->bundleDirectory . '/agent-result.json', (string) json_encode([
            'task_id' => 'EV-1',
            'target' => 'Demo\\Service::save',
            'verification_plan_sha256' => $planSha,
        ]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAnAbsolutePathOutsideTheProjectIsNotEvidence(): void
    {
        self::assertSame('failed', $this->statusFor([$this->root . '/outside/unrelated.txt']));
    }

    public function testASystemFileIsNotEvidence(): void
    {
        self::assertSame('failed', $this->statusFor(['/etc/hostname']));
    }

    public function testARelativeReferenceClimbingOutOfTheProjectIsNotEvidence(): void
    {
        self::assertSame('failed', $this->statusFor(['../outside/unrelated.txt']));
    }

    /** One unresolvable reference fails the item even when the others are fine. */
    public function testOneEscapingReferenceFailsTheWholeItem(): void
    {
        $results = $this->validate(['diff.patch', '/etc/hostname']);

        self::assertSame('failed', $results[0]['status']);
        self::assertStringContainsString('/etc/hostname', $results[0]['detail']);
        self::assertStringNotContainsString('diff.patch', $results[0]['detail']);
    }

    public function testAFileInTheBundleStillResolves(): void
    {
        self::assertSame('passed', $this->statusFor(['diff.patch']));
    }

    public function testAFileInTheProjectStillResolves(): void
    {
        self::assertSame('passed', $this->statusFor(['src/Changed.php']));
    }

    public function testAnAbsolutePathInsideTheProjectStillResolves(): void
    {
        self::assertSame('passed', $this->statusFor([$this->root . '/project/src/Changed.php']));
    }

    /** An id the plan declared needs no file at all, and that stays true. */
    public function testAnIdTheChecklistDeclaredStillResolves(): void
    {
        self::assertSame('passed', $this->statusFor(['review:pairing-notes']));
    }

    /** An id the plan never declared is not rescued by looking like one. */
    public function testAnUndeclaredIdIsNotEvidence(): void
    {
        self::assertSame('failed', $this->statusFor(['review:invented-notes']));
    }

    public function testMissingEvidenceIsStillMissingRatherThanFailed(): void
    {
        self::assertSame('missing', $this->statusFor([]));
    }

    /** @param list<string> $references */
    private function statusFor(array $references): string
    {
        return $this->validate($references)[0]['status'];
    }

    /**
     * @param list<string> $references
     * @return list<array{id: string, verifier: string, required: bool, status: string, detail: string}>
     */
    private function validate(array $references): array
    {
        $bundle = VerificationBundle::load($this->bundleDirectory);

        return (new ChecklistValidator())->validate(
            self::CHECKLIST,
            $references === [] ? [] : ['C1' => $references],
            $bundle,
            $this->root . '/project',
        );
    }
}
