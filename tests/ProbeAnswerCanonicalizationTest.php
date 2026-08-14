<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Edit\Verify\ProbeGrader;

/**
 * The grader reduces both sides of a probe to a canonical set before comparing.
 *
 * It built that set as array *keys*, and PHP silently turns an integer-like key into
 * an int - so a numeric evidence id came back out as `7` rather than `"7"`, against
 * the declared `list<string>`, and landed in the graded report as a JSON number that
 * no consumer of that report expects.
 *
 * @internal
 */
final class ProbeAnswerCanonicalizationTest extends TestCase
{
    public function testNumericAnswerIdsStayStrings(): void
    {
        $graded = $this->grade(['12 7'], ['7', '12']);

        self::assertSame('passed', $graded['status']);
        // assertSame is type-strict, so this is what separates ['12','7'] from [12,7].
        self::assertSame(['12', '7'], $graded['given']);
    }

    public function testTheAcceptedListIsReportedAsGivenByTheKey(): void
    {
        $graded = $this->grade(['12 7'], ['7']);

        self::assertSame('failed', $graded['status']);
        self::assertSame(['12 7'], $graded['accepted']);
        self::assertSame(['7'], $graded['given']);
    }

    /** Ordering and duplication must never decide a grade. */
    public function testOrderAndDuplicationDoNotDecideTheGrade(): void
    {
        self::assertSame('passed', $this->grade(['a b'], ['b', 'a', 'b'])['status']);
        self::assertSame('passed', $this->grade(['a, b'], ['b a'])['status']);
    }

    /** Equality is required, not containment. */
    public function testNamingOneOfThreeIsNotAnAnswer(): void
    {
        self::assertSame('failed', $this->grade(['a b c'], ['a'])['status']);
    }

    /** A grade must survive JSON encoding as the same shape the report declares. */
    public function testTheGradedRowEncodesWithStringIds(): void
    {
        $encoded = (string) json_encode($this->grade(['12 7'], ['7', '12']));

        self::assertStringContainsString('"given":["12","7"]', $encoded);
    }

    /**
     * @param list<string> $acceptedAnswers
     * @param list<string> $given
     * @return array{id: string, status: string, given: list<string>, accepted: list<string>}
     */
    private function grade(array $acceptedAnswers, array $given): array
    {
        return (new ProbeGrader())->grade(
            [['id' => 'P1']],
            ['P1' => ['accepted_answers' => $acceptedAnswers]],
            ['P1' => $given],
        )[0];
    }
}
