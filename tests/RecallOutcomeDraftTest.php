<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Dogfood\RecallOutcomeDraft;

/**
 * The self-shape runner's claim about guidance it never read.
 *
 * @internal
 */
final class RecallOutcomeDraftTest extends TestCase
{
    public function testCompiledPlaceholderJudgementsAreReplacedByADeclaredAbsence(): void
    {
        $draft = RecallOutcomeDraft::withheld([
            'task_id' => 'SELF-SHAPE',
            'guidance_outcomes' => [
                ['guidance_id' => 'proposal.2026-08-14.003', 'outcome' => 'unknown', 'comment' => null],
                ['guidance_id' => 'proposal.2026-08-14.013', 'outcome' => 'unknown', 'comment' => null],
            ],
        ], 'This runner never reads system.md.');

        self::assertSame([], $draft['guidance_outcomes']);
        self::assertSame('This runner never reads system.md.', $draft['guidance_outcomes_withheld_reason']);
    }

    public function testTheSelectionRecordIsLeftAlone(): void
    {
        // Selection is a fact about the compiler and stays on the record; only
        // the judgement the runner cannot make is withheld.
        $draft = RecallOutcomeDraft::withheld([
            'evaluated_guidance' => [['guidance_id' => 'proposal.2026-08-14.003', 'selected' => true]],
            'guidance_outcomes' => [['guidance_id' => 'proposal.2026-08-14.003', 'outcome' => 'unknown']],
        ], 'No evidence either way.');

        self::assertSame([['guidance_id' => 'proposal.2026-08-14.003', 'selected' => true]], $draft['evaluated_guidance']);
    }

    public function testAnAbsenceCannotBeDeclaredWithoutSayingWhy(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a reason');
        RecallOutcomeDraft::withheld(['guidance_outcomes' => []], '   ');
    }

    public function testTheNumberOfWithheldJudgementsIsReported(): void
    {
        $path = sys_get_temp_dir() . '/agent-loop-outcome-draft-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode([
            'task_id' => 'SELF-SHAPE',
            'guidance_outcomes' => [
                ['guidance_id' => 'a', 'outcome' => 'unknown'],
                ['guidance_id' => 'b', 'outcome' => 'unknown'],
                ['guidance_id' => 'c', 'outcome' => 'unknown'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            self::assertSame(3, RecallOutcomeDraft::withholdInFile($path, 'Compiled but not consulted.'));
            $rewritten = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($rewritten);
            self::assertSame([], $rewritten['guidance_outcomes']);
            self::assertSame('Compiled but not consulted.', $rewritten['guidance_outcomes_withheld_reason']);
        } finally {
            unlink($path);
        }
    }

    public function testAMalformedDraftFailsLoudlyInsteadOfLoggingNothing(): void
    {
        $path = sys_get_temp_dir() . '/agent-loop-outcome-draft-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, '{ not json');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Malformed outcome draft');
            RecallOutcomeDraft::withholdInFile($path, 'Compiled but not consulted.');
        } finally {
            unlink($path);
        }
    }
}
