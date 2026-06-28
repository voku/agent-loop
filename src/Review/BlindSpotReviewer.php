<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class BlindSpotReviewer
{
    /** @var list<string> */
    private const array VALIDATION_MARKERS = ['PHPStan', 'phpstan', 'php-cs-fixer', 'PHPUnit', 'Codeception', 'tests passed'];
    /** @var list<string> */
    private const array TOKEN_NOISE_MARKERS = ['docker compose logs', 'grep -R', 'composer install', 'npm install'];
    /** @var list<string> */
    private const array SECURITY_MARKERS = ['auth', 'login', 'password', 'csrf', 'xss', 'sql', 'migration', 'permission', 'role'];
    /** @var list<string> */
    private const array REVIEW_MARKERS = ['review blindspots', 'agent-loop review blindspots'];

    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    public static function isValidTaskId(string $taskId): bool
    {
        return $taskId !== '' && preg_match('/\A[A-Za-z0-9._-]+\z/', $taskId) === 1 && !str_contains($taskId, '..');
    }

    public function review(string $taskId): ReviewReport
    {
        if (!self::isValidTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }

        $findings = [];
        $recallMeta = 'recall/' . $taskId . '/meta.json';
        if (!is_file($this->path($recallMeta))) {
            $findings[] = new BlindSpotFinding('missing_recall', ReviewSeverity::FAIL, "Recall metadata is missing for task {$taskId}.", ["Expected file: {$recallMeta}"]);
        }

        $session = $this->collectRelatedSessionText($taskId);
        $sessionText = $this->stripSessionTemplatePlaceholders($session['text']);
        $validationMatches = $this->matchedMarkers($sessionText, self::VALIDATION_MARKERS, false);
        if ($validationMatches === []) {
            $evidence = ['Searched markers: ' . implode(', ', self::VALIDATION_MARKERS)];
            if ($session['files'] === []) {
                $evidence[] = 'No related session files found under session_plan/.';
            }
            $findings[] = new BlindSpotFinding('missing_validation_checkpoint', ReviewSeverity::WARN, "No validation checkpoint was found in session notes for task {$taskId}.", $evidence);
        }

        $reviewMatches = $this->matchedMarkers($sessionText, self::REVIEW_MARKERS, false);
        if ($reviewMatches === []) {
            $evidence = ['Searched markers: ' . implode(', ', self::REVIEW_MARKERS)];
            if ($session['files'] === []) {
                $evidence[] = 'No related session files found under session_plan/.';
            }
            $findings[] = new BlindSpotFinding('missing_review_checkpoint', ReviewSeverity::WARN, "No review blindspots checkpoint was found in session notes for task {$taskId}.", $evidence);
        }

        $noiseMatches = $this->matchedMarkers($sessionText, self::TOKEN_NOISE_MARKERS, false);
        if ($noiseMatches !== []) {
            $findings[] = new BlindSpotFinding('token_noise_risk', ReviewSeverity::INFO, 'Session notes contain commands that can create token noise.', ['Matched markers: ' . implode(', ', $noiseMatches)]);
        }

        $securityMatches = $this->matchedMarkers($sessionText, self::SECURITY_MARKERS, true);
        if ($securityMatches !== []) {
            $findings[] = new BlindSpotFinding('security_sensitive_area', ReviewSeverity::WARN, 'Session notes mention security-sensitive areas.', ['Matched markers: ' . implode(', ', $securityMatches)]);
        }

        if (is_file($this->path('MEMORY.md'))) {
            $findings[] = new BlindSpotFinding('memory_promotion_review_available', ReviewSeverity::INFO, 'MEMORY.md exists; human memory promotion review is available through agent-loop memory review.', ['Found file: MEMORY.md']);
        }

        return new ReviewReport($taskId, $findings);
    }

    /**
     * @return array{text: string, files: list<string>}
     */
    private function collectRelatedSessionText(string $taskId): array
    {
        $root = $this->path('session_plan');
        if (!is_dir($root)) {
            return ['text' => '', 'files' => []];
        }

        /** @var array<string, array{related: bool, files: array<string, string>}> $sessionGroups */
        $sessionGroups = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            if (!$item->isReadable() || !$this->looksTextFile($item)) {
                continue;
            }
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $relative = $this->relativePath($path);
            $groupKey = $this->sessionGroupKey($relative);
            $sessionGroups[$groupKey] ??= ['related' => false, 'files' => []];
            $sessionGroups[$groupKey]['files'][$relative] = $content;

            if (str_contains($relative, $taskId) || str_contains($content, $taskId)) {
                $sessionGroups[$groupKey]['related'] = true;
            }
        }

        ksort($sessionGroups);
        $files = [];
        $chunks = [];
        foreach ($sessionGroups as $group) {
            if (!$group['related']) {
                continue;
            }
            ksort($group['files']);
            array_push($files, ...array_keys($group['files']));
            array_push($chunks, ...array_values($group['files']));
        }

        return ['text' => implode("\n", $chunks), 'files' => $files];
    }

    private function stripSessionTemplatePlaceholders(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/\A(?:[-*]\s*)?\*[^*]+\*\z/', $trimmed) === 1) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * @param list<string> $markers
     *
     * @return list<string>
     */
    private function matchedMarkers(string $text, array $markers, bool $caseInsensitive): array
    {
        $matches = [];
        $haystack = $caseInsensitive ? strtolower($text) : $text;
        foreach ($markers as $marker) {
            $needle = $caseInsensitive ? strtolower($marker) : $marker;
            if (str_contains($haystack, $needle)) {
                $matches[] = $marker;
            }
        }
        return $matches;
    }

    private function looksTextFile(SplFileInfo $file): bool
    {
        $extension = strtolower($file->getExtension());
        return in_array($extension, ['md', 'txt', 'json', 'log', ''], true);
    }

    private function sessionGroupKey(string $relative): string
    {
        $prefix = 'session_plan/';
        $withoutRoot = str_starts_with($relative, $prefix) ? substr($relative, strlen($prefix)) : $relative;
        $separator = strpos($withoutRoot, '/');

        return $separator === false ? $withoutRoot : substr($withoutRoot, 0, $separator);
    }

    private function path(string $relative): string
    {
        return rtrim($this->rootPath, '/') . '/' . $relative;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->rootPath, '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
