<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunManifestProjector;

/**
 * Task identity is whatever string a card, Contract and Run share. Follow-ups created on independent
 * branches carry collision-resistant 63-bit card numbers (agent-kanban `card create --generate-id`);
 * Loop must keep each one linked to its own card and must not conflate it with an existing numeric ID
 * or with a free-form ID that is not a card at all.
 */
final class TaskIdentityAcrossBranchesTest extends TestCase
{
    private const string BRANCH_A_ID = 'TEST-3393201579522068686';
    private const string BRANCH_B_ID = 'TEST-9223372036854775807';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-task-identity-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/todo/kanban.config.json',
            json_encode(['projectPrefix' => 'TEST'], JSON_THROW_ON_ERROR) . "\n",
        );
        foreach (['TEST-1' => 'Existing numeric task', self::BRANCH_A_ID => 'Follow-up from branch a', self::BRANCH_B_ID => 'Follow-up from branch b'] as $id => $title) {
            file_put_contents($this->root . '/.agent-loop/todo/cards/' . $id . '.md', <<<MD
# {$id}: {$title}

- **Ticket:** {$id}
- **Lane:** READY
- **Status:** Selected

## Agent Task Brief

{$title}.
MD
                . "\n");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testIndependentFollowUpsStayLinkedToTheirOwnCardAndRun(): void
    {
        $projector = new RunManifestProjector($this->root);
        $a = $projector->project(self::BRANCH_A_ID);
        $b = $projector->project(self::BRANCH_B_ID);

        foreach ([self::BRANCH_A_ID => $a, self::BRANCH_B_ID => $b] as $id => $manifest) {
            self::assertSame('linked', $manifest->references['board']['state'], $id);
            self::assertSame($id, $manifest->references['board']['card_id']);
            self::assertSame('.agent-loop/todo/cards/' . $id . '.md', $manifest->references['board']['source']['path']);
            self::assertSame($id, $manifest->taskId);
        }
        self::assertNotSame($a->runId, $b->runId, 'Distinct task IDs must never share a Run identity.');
    }

    public function testExistingNumericTaskIdsKeepWorking(): void
    {
        $manifest = (new RunManifestProjector($this->root))->project('TEST-1');

        self::assertSame('linked', $manifest->references['board']['state']);
        self::assertSame('TEST-1', $manifest->references['board']['card_id']);
    }

    public function testFreeFormTaskIdIsAdHocAndNeverLinkedToACard(): void
    {
        $manifest = (new RunManifestProjector($this->root))->project('FOLLOW-UP-36');

        self::assertSame('ad_hoc_task', $manifest->references['board']['state']);
        self::assertArrayNotHasKey('card_id', $manifest->references['board']);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $path . '/' . $entry;
            is_dir($fullPath) ? $this->removeDirectory($fullPath) : unlink($fullPath);
        }

        rmdir($path);
    }
}
