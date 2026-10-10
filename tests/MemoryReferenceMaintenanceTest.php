<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\MemoryReferenceMaintenance;

/** @internal */
final class MemoryReferenceMaintenanceTest extends TestCase
{
    public function testOnlyProofRepairedCanonicalReferenceChangesAreAccepted(): void
    {
        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();

        self::assertTrue((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));
    }

    public function testAChangedDurableRuleIsRejectedEvenWithAReanchorHash(): void
    {
        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();
        $after = str_replace('Keep the rule.', 'Change the rule.', $after);
        $newProofs[$paths[1]]['applied_validation']['target_content_hash'] = hash('sha256', $after);

        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));
    }

    public function testChangedCanonicalPathMustResolveInsideTheProject(): void
    {
        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();
        $after = str_replace('tools/Dogfood/SelfShapeEvidence.php', 'tools/not-a-real-owner.php', $after);
        $newProofs[$paths[1]]['applied_validation']['target_content_hash'] = hash('sha256', $after);

        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));
    }

    public function testMismatchedHashOrAlteredApprovalIsRejected(): void
    {
        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();
        $newProofs[$paths[1]]['applied_validation']['target_content_hash'] = str_repeat('0', 64);

        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));

        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();
        $newProofs[$paths[1]]['approved_by'] = 'somebody-else';

        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));
    }

    public function testMissingProofOrAdditionalChangedFileIsRejected(): void
    {
        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();
        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, [], dirname(__DIR__),
        ));

        $paths[] = 'src/Dispatcher.php';
        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));
    }

    public function testUnattributedReanchorAndChangedHomeProseAreRejected(): void
    {
        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();
        $newProofs[$paths[1]]['applied_validation']['reanchor_reason'] = '';
        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));

        [$before, $after, $paths, $oldProofs, $newProofs] = $this->fixture();
        $after = str_replace(' and the code', ' but not the code', $after);
        $newProofs[$paths[1]]['applied_validation']['target_content_hash'] = hash('sha256', $after);
        self::assertFalse((new MemoryReferenceMaintenance())->isVerified(
            $before, $after, $paths, $oldProofs, $newProofs, dirname(__DIR__),
        ));
    }

    /**
     * @return array{string, string, list<string>, array<string, array<string, mixed>>, array<string, array<string, mixed>>}
     */
    private function fixture(): array
    {
        $before = "# Repository memory\n| Subject | Durable rule | Canonical home |\n| Rule | Keep the rule. | `src/Dogfood/SelfShapeEvidence.php` and the code |\n";
        $after = "# Repository memory\n| Subject | Durable rule | Canonical home |\n| Rule | Keep the rule. | `tools/Dogfood/SelfShapeEvidence.php` and the code |\n";
        $path = '.agent-loop/learning/proposals/applied/proposal.2026-08-14.007.json';
        $old = [
            'status' => 'applied',
            'approved_by' => 'voku',
            'new' => 'Keep the rule.',
            'applied_validation' => [
                'target_source_ref' => 'MEMORY.md',
                'target_content_hash' => hash('sha256', $before),
                'commit' => 'approved-commit',
            ],
        ];
        $new = $old;
        $new['applied_validation']['target_content_hash'] = hash('sha256', $after);
        $new['applied_validation']['reanchored_by'] = 'voku';
        $new['applied_validation']['reanchored_at'] = '2026-10-10T19:33:03+00:00';
        $new['applied_validation']['reanchor_reason'] = 'Validated canonical-reference repair.';

        return [$before, $after, ['MEMORY.md', $path], [$path => $old], [$path => $new]];
    }
}
