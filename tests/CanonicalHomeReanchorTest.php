<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\SelfShapeEvidence;

/**
 * A reviewed canonical-home move repairs provenance, not the durable rule.
 *
 * @internal
 */
final class CanonicalHomeReanchorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . '/agent-loop-reanchor-' . bin2hex(random_bytes(5));
        mkdir($this->workspace . '/tools', 0o775, true);
        mkdir($this->workspace . '/.agent-loop/learning/proposals/applied', 0o775, true);
        file_put_contents($this->workspace . '/tools/New.php', '<?php');
    }

    protected function tearDown(): void
    {
        unlink($this->workspace . '/tools/New.php');
        unlink($this->workspace . '/.agent-loop/learning/proposals/applied/proposal.2026-10-10.001.json');
        rmdir($this->workspace . '/.agent-loop/learning/proposals/applied');
        rmdir($this->workspace . '/.agent-loop/learning/proposals');
        rmdir($this->workspace . '/.agent-loop/learning');
        rmdir($this->workspace . '/.agent-loop');
        rmdir($this->workspace . '/tools');
        rmdir($this->workspace);

        parent::tearDown();
    }

    public function testReanchoredCanonicalHomeOnlyCanBeClassifiedWithoutNewFinding(): void
    {
        $previous = "| Example | Keep this durable rule. | \x60src/Old.php\x60 |\n";
        $current = "| Example | Keep this durable rule. | \x60tools/New.php\x60 |\n";
        $this->writeAppliedProof($current);

        self::assertTrue(SelfShapeEvidence::hasVerifiedCanonicalHomeReanchor(
            $previous,
            $current,
            $this->changedFiles(),
            $this->workspace,
        ));

        $evidence = new SelfShapeEvidence([], true, true);
        self::assertSame([], $evidence->recordedFindingIds());
        self::assertSame('no_durable_learning', $evidence->learningStatus());
        self::assertStringContainsString('reanchored', $evidence->learningReason());
    }

    public function testChangingDurableRuleNeverQualifiesAsCanonicalHomeMaintenance(): void
    {
        $previous = "| Example | Keep this durable rule. | \x60src/Old.php\x60 |\n";
        $current = "| Example | Replace this durable rule. | \x60tools/New.php\x60 |\n";
        $this->writeAppliedProof($current);

        self::assertFalse(SelfShapeEvidence::hasVerifiedCanonicalHomeReanchor(
            $previous,
            $current,
            $this->changedFiles(),
            $this->workspace,
        ));
    }

    public function testNonexistentCanonicalHomeIsNotVerified(): void
    {
        $previous = "| Example | Keep this durable rule. | \x60src/Old.php\x60 |\n";
        $current = "| Example | Keep this durable rule. | \x60tools/DoesNotExist.php\x60 |\n";
        $this->writeAppliedProof($current);

        self::assertFalse(SelfShapeEvidence::hasVerifiedCanonicalHomeReanchor(
            $previous,
            $current,
            $this->changedFiles(),
            $this->workspace,
        ));
    }

    public function testUnchangedProposalProofCannotJustifyMemoryMaintenance(): void
    {
        $previous = "| Example | Keep this durable rule. | \x60src/Old.php\x60 |\n";
        $current = "| Example | Keep this durable rule. | \x60tools/New.php\x60 |\n";
        $this->writeAppliedProof($current);

        self::assertFalse(SelfShapeEvidence::hasVerifiedCanonicalHomeReanchor(
            $previous,
            $current,
            ['MEMORY.md'],
            $this->workspace,
        ));
    }

    public function testStaleHashCannotJustifyMemoryMaintenance(): void
    {
        $previous = "| Example | Keep this durable rule. | \x60src/Old.php\x60 |\n";
        $current = "| Example | Keep this durable rule. | \x60tools/New.php\x60 |\n";
        $this->writeAppliedProof($previous);

        self::assertFalse(SelfShapeEvidence::hasVerifiedCanonicalHomeReanchor(
            $previous,
            $current,
            $this->changedFiles(),
            $this->workspace,
        ));
    }

    /** @return list<string> */
    private function changedFiles(): array
    {
        return ['MEMORY.md', '.agent-loop/learning/proposals/applied/proposal.2026-10-10.001.json'];
    }

    private function writeAppliedProof(string $memoryText): void
    {
        $content = [
            'id' => 'proposal.2026-10-10.001',
            'target_type' => 'memory',
            'applied_validation' => [
                'target_source_ref' => 'MEMORY.md',
                'target_content_hash' => hash('sha256', $memoryText),
                'reanchored_by' => 'voku',
                'reanchored_at' => '2026-10-10T19:33:03+00:00',
                'reanchor_reason' => 'Reviewed canonical evidence relocation, no rule text changed.',
            ],
        ];
        file_put_contents(
            $this->workspace . '/.agent-loop/learning/proposals/applied/proposal.2026-10-10.001.json',
            json_encode($content, JSON_THROW_ON_ERROR),
        );
    }
}
