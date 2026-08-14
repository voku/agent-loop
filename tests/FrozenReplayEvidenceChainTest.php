<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The frozen replays exist to compare workflow changes over time.
 *
 * A replay is only useful if it records *where* the decisive evidence was lost,
 * not merely that the run finished. Each chain-shaped case therefore states
 * whether the evidence was discovered, selected and finally present, and what
 * the fix was allowed to touch.
 *
 * The `fix_boundary` assertions are the point: the cheap way to make any of
 * these replays pass is to widen search, change ranking, or raise the context
 * budget. That is not a fix, it is a larger haystack, and a future change that
 * reaches for one has to edit this file to do it.
 *
 * @internal
 */
final class FrozenReplayEvidenceChainTest extends TestCase
{
    private const string SUITE = __DIR__ . '/fixtures/real-upstream-adaptation';

    /** @return iterable<string, array{string}> */
    public static function chainShapedReplays(): iterable
    {
        foreach (glob(self::SUITE . '/*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && isset($decoded['chain_before'])) {
                yield basename($path) => [$path];
            }
        }
    }

    public function testTheSuiteStaysSmallAndReal(): void
    {
        $cases = glob(self::SUITE . '/*.json') ?: [];

        self::assertGreaterThanOrEqual(3, count($cases), 'A replay suite of one case cannot show a regression.');
        self::assertLessThanOrEqual(6, count($cases), 'Keep the suite small: synthetic prompts dilute what a replay proves.');
    }

    #[DataProvider('chainShapedReplays')]
    public function testAReplayRecordsWhereTheEvidenceWasLost(string $path): void
    {
        $case = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($case);

        foreach (['case', 'consumer_repository', 'consumer_commit', 'task', 'decisive_evidence', 'chain_before', 'chain_after', 'fix_boundary'] as $key) {
            self::assertArrayHasKey($key, $case, $path . ' does not record ' . $key . '.');
        }

        self::assertIsArray($case['decisive_evidence']);
        self::assertArrayHasKey('symbol', $case['decisive_evidence']);
        self::assertArrayHasKey('why_decisive', $case['decisive_evidence']);

        foreach (['chain_before', 'chain_after'] as $stage) {
            self::assertIsArray($case[$stage]);
            foreach (['discovered_by_map', 'selected_by_recall', 'present_in_governed_context', 'classification'] as $key) {
                self::assertArrayHasKey($key, $case[$stage], $path . ' does not record ' . $stage . '.' . $key . '.');
            }
        }

        self::assertTrue(
            $case['chain_after']['present_in_governed_context'],
            $path . ' claims a fix that still does not put the decisive evidence in the governed context.',
        );
    }

    #[DataProvider('chainShapedReplays')]
    public function testAReplayWasNotFixedByEnlargingTheHaystack(string $path): void
    {
        $case = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($case);
        self::assertIsArray($case['fix_boundary']);

        foreach (['search_widened', 'ranking_changed', 'context_budget_raised'] as $shortcut) {
            self::assertArrayHasKey($shortcut, $case['fix_boundary'], $path . ' does not state whether it ' . $shortcut . '.');
            self::assertFalse(
                $case['fix_boundary'][$shortcut],
                $path . ' records ' . $shortcut . '. Evidence conservation is the first response to evidence loss; '
                . 'if widening really was necessary, say so deliberately by changing this assertion.',
            );
        }
    }
}
