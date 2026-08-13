<?php

declare(strict_types=1);

namespace voku\AgentLoop;

final class RepositoryHealth
{
    /**
     * @param array<string, mixed> $evidence
     * @return array{repository: string, status: 'configured'|'healthy'|'skip'|'suspicious', findings: list<string>}
     */
    public function classify(array $evidence): array
    {
        $repository = is_string($evidence['repository'] ?? null) ? $evidence['repository'] : '<unknown>';
        $composer = ($evidence['package_manager'] ?? null) === 'composer' || ($evidence['composer'] ?? null) === true;
        $renovate = is_array($evidence['renovate'] ?? null) ? $evidence['renovate'] : [];
        $config = is_string($renovate['config_path'] ?? null) ? $renovate['config_path'] : null;
        $active = ($renovate['activity'] ?? false) === true;
        $history = ($renovate['history'] ?? false) === true;
        $peers = is_array($evidence['peers'] ?? null) ? $evidence['peers'] : [];
        $comparable = is_int($peers['comparable'] ?? null) ? $peers['comparable'] : 0;
        $peerActive = is_int($peers['renovate_active'] ?? null) ? $peers['renovate_active'] : 0;
        $expected = $composer && ($active || $history || $config !== null || ($comparable >= 3 && $peerActive * 4 >= $comparable * 3));

        if (!$expected) {
            return ['repository' => $repository, 'status' => 'skip', 'findings' => []];
        }

        $findings = [];
        if (($evidence['issues_enabled'] ?? null) === false) {
            $findings[] = 'ISSUES_REQUIRED_FOR_DASHBOARD';
        }
        if (!$active && $config === null) {
            $findings[] = 'EXPECTED_DEPENDENCY_AUTOMATION_MISSING';
            if (($evidence['fork'] ?? null) === true) {
                $findings[] = 'RENOVATE_FORK_PROCESSING_REQUIRED';
            }
        } elseif (!$active && ($evidence['fork'] ?? null) === true && ($config !== 'renovate.json' || ($renovate['fork_processing'] ?? null) !== 'enabled')) {
            $findings[] = 'RENOVATE_CONFIG_PRESENT_BUT_INEFFECTIVE_FOR_FORK';
        }

        return [
            'repository' => $repository,
            'status' => $findings !== [] ? 'suspicious' : ($active ? 'healthy' : 'configured'),
            'findings' => $findings,
        ];
    }
}
