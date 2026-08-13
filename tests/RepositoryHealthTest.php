<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\RepositoryHealth;

final class RepositoryHealthTest extends TestCase
{
    /** @param array<string, mixed> $evidence @param list<string> $findings */
    #[DataProvider('cases')]
    public function testRealRepositoryEvidence(array $evidence, string $status, array $findings): void
    {
        $result = (new RepositoryHealth())->classify($evidence);

        self::assertSame($status, $result['status']);
        self::assertSame($findings, $result['findings']);
    }

    /** @return iterable<string, array{array<string, mixed>, string, list<string>}> */
    public static function cases(): iterable
    {
        $peers = ['comparable' => 20, 'renovate_active' => 18];

        yield 'slop-scan before repair' => [[
            'repository' => 'voku/slop-scan', 'composer' => true, 'fork' => true, 'issues_enabled' => false,
            'renovate' => ['config_path' => null, 'activity' => false, 'history' => false], 'peers' => $peers,
        ], 'suspicious', ['ISSUES_REQUIRED_FOR_DASHBOARD', 'EXPECTED_DEPENDENCY_AUTOMATION_MISSING', 'RENOVATE_FORK_PROCESSING_REQUIRED']];

        yield 'simple_html_dom ineffective fork config' => [[
            'repository' => 'voku/simple_html_dom', 'composer' => true, 'fork' => true, 'issues_enabled' => true,
            'renovate' => ['config_path' => '.github/renovate.json', 'activity' => false, 'history' => true], 'peers' => $peers,
        ], 'suspicious', ['RENOVATE_CONFIG_PRESENT_BUT_INEFFECTIVE_FOR_FORK']];

        yield 'agent-loop missing peer convention' => [[
            'repository' => 'voku/agent-loop', 'composer' => true, 'fork' => false, 'issues_enabled' => true,
            'renovate' => ['config_path' => null, 'activity' => false, 'history' => false], 'peers' => $peers,
        ], 'suspicious', ['EXPECTED_DEPENDENCY_AUTOMATION_MISSING']];

        yield 'closed onboarding is evidence of expectation, not activity' => [[
            'repository' => 'voku/PHPMailer-BMH', 'composer' => true, 'fork' => false, 'issues_enabled' => true,
            'renovate' => ['config_path' => null, 'activity' => false, 'history' => true], 'peers' => $peers,
        ], 'suspicious', ['EXPECTED_DEPENDENCY_AUTOMATION_MISSING']];

        yield 'configured but not yet observed' => [[
            'repository' => 'voku/agent-loop', 'composer' => true, 'fork' => false, 'issues_enabled' => true,
            'renovate' => ['config_path' => 'renovate.json', 'activity' => false, 'history' => false], 'peers' => $peers,
        ], 'configured', []];

        yield 'repaired fork with activity' => [[
            'repository' => 'voku/slop-scan', 'composer' => true, 'fork' => true, 'issues_enabled' => true,
            'renovate' => ['config_path' => 'renovate.json', 'fork_processing' => 'enabled', 'activity' => true, 'history' => true], 'peers' => $peers,
        ], 'healthy', []];

        yield 'weak peer convention does not invent work' => [[
            'repository' => 'voku/experiment', 'composer' => true, 'fork' => false,
            'renovate' => ['config_path' => null, 'activity' => false, 'history' => false],
            'peers' => ['comparable' => 4, 'renovate_active' => 2],
        ], 'skip', []];
    }
}
