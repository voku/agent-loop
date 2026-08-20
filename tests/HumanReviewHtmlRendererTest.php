<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\HumanReviewDiff;
use voku\AgentLoop\Workflow\HumanReviewHtmlRenderer;

final class HumanReviewHtmlRendererTest extends TestCase
{
    public function testRenderIsDeterministicSelfContainedAndEscapesReviewData(): void
    {
        $renderer = new HumanReviewHtmlRenderer(
            'body { color: CanvasText; }',
            "document.documentElement.dataset.ready = '1';",
        );
        $report = [
            'task_id' => 'REVIEW-1',
            'run_id' => 'run-1',
            'contract' => [
                'status' => 'approved',
                'revision' => 3,
                'goal' => 'Do not execute <script>alert("goal")</script>.',
                'acceptance_criteria' => ['Rendered source stays text.'],
                'behavior_anchors' => ['Human owns judgement.'],
                'scope' => ['src'],
                'non_goals' => ['No browser approval.'],
            ],
            'scope' => [
                'changed_files' => ['src/<unsafe>.php'],
                'outside_approved_scope' => [],
            ],
            'validation' => [[
                'command' => 'php -r \'echo "<validation>";\'',
                'status' => 'passed',
                'source' => 'session',
                'executed_at' => '2026-08-20T20:00:00+00:00',
            ]],
            'review' => [
                'exists' => true,
                'status' => 'unacknowledged',
                'report_status' => 'warn',
                'invalid' => false,
                'implementation_snapshot' => 'sha256:' . str_repeat('a', 64),
                'sha256' => 'sha256:' . str_repeat('b', 64),
                'acknowledged_by' => null,
            ],
            'learning' => ['status' => 'missing', 'decision' => null],
            'accepted_risk' => ['recorded' => false],
        ];
        $findings = [[
            'id' => 'unsafe-html',
            'severity' => 'WARN',
            'message' => '<img src=x onerror=alert(1)>',
            'evidence' => ['src/<unsafe>.php:1'],
        ]];
        $diff = new HumanReviewDiff(
            available: true,
            baseCommit: str_repeat('c', 40),
            changedFiles: ['src/<unsafe>.php'],
            untrackedFiles: [],
            patch: "+<script>alert('diff')</script>\n",
        );

        $first = $renderer->render($report, $findings, $diff);
        $second = $renderer->render($report, $findings, $diff);

        self::assertSame($first, $second);
        self::assertStringContainsString('Content-Security-Policy', $first);
        self::assertStringContainsString("style-src &#039;sha256-", $first);
        self::assertStringContainsString("script-src &#039;sha256-", $first);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;goal&quot;)&lt;/script&gt;', $first);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $first);
        self::assertStringContainsString('&lt;script&gt;alert(&#039;diff&#039;)&lt;/script&gt;', $first);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $first);
        self::assertStringNotContainsString("+<script>alert('diff')</script>", $first);
        self::assertStringContainsString('0 fail · 1 warn', $first);
        self::assertStringContainsString('data-severity-filter="info"', $first);
        self::assertStringNotContainsString(' style="', $first);
        self::assertStringContainsString('disposable projection, not approval authority', $first);
        self::assertStringNotContainsString('https://', $first);
    }
}
