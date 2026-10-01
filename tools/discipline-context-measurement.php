<?php

declare(strict_types=1);

use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Measure the source-backed and host-projected context boundary for #663.
 *
 * This tool is observation-only. It does not choose which guidance is useful,
 * approve Learning proposals, or mutate repository guidance.
 */

/** @return array{report: non-empty-string, root: non-empty-string} */
function options(): array
{
    $raw = getopt('', ['report:', 'root::']);
    $report = is_array($raw) ? ($raw['report'] ?? null) : null;
    $root = is_array($raw) ? ($raw['root'] ?? dirname(__DIR__)) : dirname(__DIR__);

    if (!is_string($report) || trim($report) === '') {
        throw new InvalidArgumentException('Usage: discipline-context-measurement.php --report=<path> [--root=<repository>].');
    }
    if (!is_string($root) || trim($root) === '') {
        throw new InvalidArgumentException('--root must be a non-empty repository path.');
    }

    return [
        'report' => trim($report),
        'root' => rtrim(trim($root), '/\\'),
    ];
}

function readFileStrict(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Unable to read non-empty file: ' . $path);
    }

    return $content;
}

function managedRouter(string $agents): string
{
    $begin = '<!-- agent-loop:project-instructions:begin -->';
    $end = '<!-- agent-loop:project-instructions:end -->';
    $start = strpos($agents, $begin);
    $finish = strpos($agents, $end);
    if ($start === false || $finish === false || $finish < $start) {
        throw new RuntimeException('AGENTS.md misses the managed project-instructions block.');
    }

    return substr($agents, $start, $finish + strlen($end) - $start);
}

function stripFrontmatter(string $skill): string
{
    $stripped = preg_replace('/\\A---\\R.*?\\R---\\R/s', '', $skill, 1);
    if (!is_string($stripped) || $stripped === $skill) {
        throw new RuntimeException('Discipline skill frontmatter could not be removed.');
    }

    return ltrim($stripped);
}

/** @return list<non-empty-string> */
function markdownHeadings(string $content): array
{
    preg_match_all('/^## (.+)$/m', $content, $matches);

    /** @var list<non-empty-string> $headings */
    $headings = array_values(array_filter(
        $matches[1] ?? [],
        static fn (mixed $heading): bool => is_string($heading) && $heading !== '',
    ));

    return $headings;
}

/** @return array<string, array{router: bool, discipline: bool}> */
function nextActionKindPresence(string $router, string $discipline): array
{
    $result = [];
    foreach (['command', 'command_template', 'decision_required', 'host_work', 'none'] as $kind) {
        $needle = '`' . $kind . '`';
        $result[$kind] = [
            'router' => str_contains($router, $needle),
            'discipline' => str_contains($discipline, $needle),
        ];
    }

    return $result;
}

/**
 * @return array{bytes: int<0, max>, lines: int<1, max>}
 */
function projectedContext(string $skill, bool $resumeHints, bool $claude): array
{
    $root = sys_get_temp_dir() . '/agent-loop-context-measurement-' . bin2hex(random_bytes(8));

    try {
        foreach (['.codex', '.claude'] as $client) {
            $directory = $root . '/' . $client . '/skills/agent-loop-discipline';
            if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create projected skill directory: ' . $directory);
            }
            if (file_put_contents($directory . '/SKILL.md', $skill) === false) {
                throw new RuntimeException('Unable to write projected discipline skill.');
            }
        }

        if ($resumeHints) {
            for ($index = 1; $index <= 5; ++$index) {
                $taskId = 'CONTEXT-MEASUREMENT-' . $index;
                $run = $root . '/.agent-loop/runs/' . $taskId;
                if (!mkdir($run, 0o775, true) && !is_dir($run)) {
                    throw new RuntimeException('Unable to create measurement Run directory.');
                }
                $manifest = json_encode([
                    'task_id' => $taskId,
                    'state' => 'incomplete',
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                if (file_put_contents($run . '/manifest.json', $manifest . "\n") === false) {
                    throw new RuntimeException('Unable to write measurement Run manifest.');
                }
            }
        }

        $hook = new AgentDisciplineHook($root);
        $payload = json_encode([
            'hook_event_name' => 'SessionStart',
            'source' => 'startup',
        ], JSON_THROW_ON_ERROR);
        $output = $claude
            ? $hook->claudeContextOutput('SessionStart', $payload)
            : $hook->contextOutput('SessionStart', $payload);
        $context = $output['hookSpecificOutput']['additionalContext'];

        return [
            'bytes' => strlen($context),
            'lines' => substr_count($context, "\n") + 1,
        ];
    } finally {
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }
}

/** @return array<string, mixed> */
function measurement(string $root): array
{
    $agents = readFileStrict($root . '/AGENTS.md');
    $discipline = readFileStrict($root . '/resources/skills/agent-loop-discipline/SKILL.md');
    $agentAssets = readFileStrict($root . '/docs/reference/agent-assets.md');
    $router = managedRouter($agents);
    $injectedDiscipline = stripFrontmatter($discipline);

    $hookRule = 'Hooks are behavioral guardrails, never correctness or security boundaries.';

    return [
        'source' => [
            'managed_router_bytes' => strlen($router),
            'managed_router_lines' => substr_count($router, "\n") + 1,
            'canonical_discipline_bytes' => strlen($discipline),
            'injected_discipline_bytes' => strlen($injectedDiscipline),
            'injected_discipline_lines' => substr_count($injectedDiscipline, "\n") + 1,
            'static_router_plus_discipline_bytes' => strlen($router) + strlen($injectedDiscipline),
            'discipline_headings' => markdownHeadings($discipline),
        ],
        'runtime_projection' => [
            'codex_without_resume_hints' => projectedContext($discipline, false, false),
            'codex_with_five_resume_hints' => projectedContext($discipline, true, false),
            'claude_without_resume_hints' => projectedContext($discipline, false, true),
            'claude_with_five_resume_hints' => projectedContext($discipline, true, true),
        ],
        'duplicate_observations' => [
            'next_action_kind_treatment' => nextActionKindPresence($router, $discipline),
            'hook_boundary_rule' => [
                'discipline' => str_contains($discipline, $hookRule),
                'agent_assets_reference' => str_contains($agentAssets, $hookRule),
            ],
        ],
    ];
}

try {
    $options = options();
    $result = [
        'schema_version' => '1.0',
        'scope' => 'agent-loop-discipline-context-boundary',
        'github_event_sha' => (($sha = getenv('GITHUB_SHA')) !== false && $sha !== '') ? $sha : null,
        'candidate_sha' => (($candidate = getenv('AGENT_LOOP_CANDIDATE_SHA')) !== false && $candidate !== '') ? $candidate : null,
        'base_sha' => (($base = getenv('AGENT_LOOP_BASE_SHA')) !== false && $base !== '') ? $base : null,
        'measurement' => measurement($options['root']),
    ];

    $directory = dirname($options['report']);
    if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create report directory: ' . $directory);
    }

    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    if (file_put_contents($options['report'], $json . "\n") === false) {
        throw new RuntimeException('Unable to write measurement report: ' . $options['report']);
    }

    echo $json . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Discipline context measurement failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
