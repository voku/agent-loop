<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use InvalidArgumentException;
use JsonException;

/**
 * Classifies already-collected repository evidence without talking to GitHub.
 *
 * Collection stays outside agent-loop; this class owns only deterministic
 * interpretation so real fleet snapshots can be frozen and replayed offline.
 */
final class RepositoryHealthCli
{
    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || array_intersect($tokens, ['help', '--help', '-h']) !== []) {
            echo $this->usage();

            return $tokens === [] ? 1 : 0;
        }

        $format = 'text';
        $path = null;
        foreach ($tokens as $token) {
            if (str_starts_with($token, '--format=')) {
                $format = substr($token, 9);
                continue;
            }
            if (str_starts_with($token, '--')) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if ($path !== null) {
                throw new InvalidArgumentException('Expected one evidence JSON file.');
            }
            $path = $token;
        }
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('--format must be text or json.');
        }
        if ($path === null || !is_file($path)) {
            throw new InvalidArgumentException('Evidence JSON file not found: ' . ($path ?? 'missing'));
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Evidence JSON is invalid: ' . $exception->getMessage(), previous: $exception);
        }
        if (!is_array($document)) {
            throw new InvalidArgumentException('Evidence JSON must contain an object.');
        }

        $items = isset($document['repositories']) && is_array($document['repositories'])
            ? $document['repositories']
            : [$document];
        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each repository evidence entry must be an object.');
            }
            $results[] = $this->classify($item);
        }

        if ($format === 'json') {
            echo json_encode(['repositories' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } else {
            foreach ($results as $result) {
                echo sprintf(
                    '%s [%s] composer=%s renovate_expected=%s fork=%s%s',
                    $result['repository'],
                    strtoupper($result['status']),
                    $result['composer'] ? 'yes' : 'no',
                    $result['renovate_expected'] ? 'yes' : 'no',
                    $result['fork'] === null ? 'unknown' : ($result['fork'] ? 'yes' : 'no'),
                    "\n",
                );
                foreach ($result['findings'] as $finding) {
                    echo '  - ' . $finding . "\n";
                }
            }
        }

        return in_array('suspicious', array_column($results, 'status'), true) ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{repository: string, status: 'configured'|'healthy'|'skip'|'suspicious', composer: bool, renovate_expected: bool, fork: bool|null, findings: list<string>}
     */
    public function classify(array $evidence): array
    {
        $repository = is_string($evidence['repository'] ?? null) && trim($evidence['repository']) !== ''
            ? trim($evidence['repository'])
            : '<unknown>';
        $composer = ($evidence['package_manager'] ?? null) === 'composer' || ($evidence['composer'] ?? null) === true;
        $fork = is_bool($evidence['fork'] ?? null) ? $evidence['fork'] : null;
        $renovate = is_array($evidence['renovate'] ?? null) ? $evidence['renovate'] : [];
        $configPath = is_string($renovate['config_path'] ?? null) ? $renovate['config_path'] : null;
        $forkProcessing = is_string($renovate['fork_processing'] ?? null) ? $renovate['fork_processing'] : null;
        $active = ($renovate['dashboard'] ?? false) === true || ($renovate['pr_history'] ?? false) === true;
        $peers = is_array($evidence['peers'] ?? null) ? $evidence['peers'] : [];
        $comparable = is_int($peers['comparable'] ?? null) ? $peers['comparable'] : 0;
        $peerActive = is_int($peers['renovate_active'] ?? null) ? $peers['renovate_active'] : 0;
        $expected = $composer && ($active || $configPath !== null || ($comparable >= 3 && $peerActive * 4 >= $comparable * 3));

        if (!$composer || !$expected) {
            return compact('repository', 'composer', 'fork') + [
                'status' => 'skip',
                'renovate_expected' => $expected,
                'findings' => [],
            ];
        }

        $findings = [];
        if (($evidence['issues_enabled'] ?? null) === false) {
            $findings[] = 'ISSUES_REQUIRED_FOR_DASHBOARD';
        }
        if (!$active && $configPath === null) {
            $findings[] = 'EXPECTED_DEPENDENCY_AUTOMATION_MISSING';
            if ($fork === true && $forkProcessing !== 'enabled') {
                $findings[] = 'RENOVATE_FORK_PROCESSING_REQUIRED';
            }
        } elseif (!$active && $fork === true && ($configPath !== 'renovate.json' || $forkProcessing !== 'enabled')) {
            $findings[] = 'RENOVATE_CONFIG_PRESENT_BUT_INEFFECTIVE_FOR_FORK';
        }

        return compact('repository', 'composer', 'fork') + [
            'status' => $findings !== [] ? 'suspicious' : ($active ? 'healthy' : 'configured'),
            'renovate_expected' => true,
            'findings' => $findings,
        ];
    }

    private function usage(): string
    {
        return <<<'TXT'
        agent-loop-repo-health EVIDENCE.json [--format=text|json]

        Classify dependency-automation evidence collected by a host workflow.
        A document may describe one repository or contain a `repositories` list.
        Peer adoption (>=75% across at least three comparable repositories) is
        treated as evidence that Renovate is expected, so absence can be detected.
        `configured` means the repository-side setup looks correct but no bot
        activity was included in the evidence yet.

        TXT;
    }
}
