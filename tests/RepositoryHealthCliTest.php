<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\RepositoryHealthCli;

final class RepositoryHealthCliTest extends TestCase
{
    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $findings
     */
    #[DataProvider('realCases')]
    public function testRepositoryHealthClassification(array $evidence, string $status, array $findings): void
    {
        $result = (new RepositoryHealthCli())->classify($evidence);

        self::assertSame($status, $result['status']);
        self::assertSame($findings, $result['findings']);
    }

    /** @return iterable<string, array{array<string, mixed>, string, list<string>}> */
    public static function realCases(): iterable
    {
        $peers = ['comparable' => 20, 'renovate_active' => 18];

        yield 'slop-scan before repair' => [[
            'repository' => 'voku/slop-scan',
            'package_manager' => 'composer',
            'fork' => true,
            'issues_enabled' => false,
            'renovate' => ['config_path' => null, 'fork_processing' => null, 'dashboard' => false, 'pr_history' => false],
            'peers' => $peers,
        ], 'suspicious', [
            'ISSUES_REQUIRED_FOR_DASHBOARD',
            'EXPECTED_DEPENDENCY_AUTOMATION_MISSING',
            'RENOVATE_FORK_PROCESSING_REQUIRED',
        ]];

        yield 'simple_html_dom ineffective fork config' => [[
            'repository' => 'voku/simple_html_dom',
            'package_manager' => 'composer',
            'fork' => true,
            'issues_enabled' => true,
            'renovate' => ['config_path' => '.github/renovate.json', 'fork_processing' => null, 'dashboard' => false, 'pr_history' => false],
            'peers' => $peers,
        ], 'suspicious', ['RENOVATE_CONFIG_PRESENT_BUT_INEFFECTIVE_FOR_FORK']];

        yield 'agent-loop missing peer convention' => [[
            'repository' => 'voku/agent-loop',
            'package_manager' => 'composer',
            'fork' => false,
            'issues_enabled' => true,
            'renovate' => ['config_path' => null, 'fork_processing' => null, 'dashboard' => false, 'pr_history' => false],
            'peers' => $peers,
        ], 'suspicious', ['EXPECTED_DEPENDENCY_AUTOMATION_MISSING']];

        yield 'repaired fork with activity' => [[
            'repository' => 'voku/slop-scan',
            'package_manager' => 'composer',
            'fork' => true,
            'issues_enabled' => true,
            'renovate' => ['config_path' => 'renovate.json', 'fork_processing' => 'enabled', 'dashboard' => true, 'pr_history' => true],
            'peers' => $peers,
        ], 'healthy', []];

        yield 'weak peer convention does not invent work' => [[
            'repository' => 'voku/experimental-package',
            'package_manager' => 'composer',
            'fork' => false,
            'renovate' => ['config_path' => null, 'dashboard' => false, 'pr_history' => false],
            'peers' => ['comparable' => 4, 'renovate_active' => 2],
        ], 'skip', []];
    }
}
