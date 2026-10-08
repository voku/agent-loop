<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;

/**
 * Runs the read-only Dream preview when it is due, so nobody has to remember to.
 *
 * Due is decided from facts, not from an agent's judgement: no previous run, the
 * Learning inputs Dream evaluates changed (content fingerprint, so a checkout that
 * only touches mtimes does not trigger), project AGENTS.md or MEMORY.md changed,
 * or the last run is older than
 * {@see MAX_AGE_SECONDS}. Outcome history is left out of the fingerprint on
 * purpose: it grows with every task and would make Dream due on every session; the
 * age limit covers it.
 *
 * Only {@see WorkflowDreamService::preview()} is called. It writes nothing into
 * the Learning root, so this never creates, approves or applies guidance. The
 * digest lands in `<state>/dream/auto.json`, which is regenerable working state.
 *
 * Set AGENT_LOOP_DREAM_AUTORUN=0 to switch it off.
 */
final readonly class WorkflowDreamAutoRun
{
    public const MAX_AGE_SECONDS = 604800;

    private const OPT_OUT_ENV = 'AGENT_LOOP_DREAM_AUTORUN';

    /** Learning directories whose content Dream evaluates; history/ is excluded on purpose. */
    private const FINGERPRINT_DIRECTORIES = ['findings', 'proposals', 'constraints/active', 'notes'];

    /** Central project guidance only; installed skill copies and other documents are outside this trigger. */
    private const PROJECT_GUIDANCE_FILES = ['AGENTS.md', 'MEMORY.md'];

    /** @param (Closure(): int)|null $clock injectable for tests; defaults to the wall clock */
    public function __construct(
        private string $rootPath,
        private ?Closure $clock = null,
    ) {
    }

    /**
     * @return array{
     *     ran: bool,
     *     reason: string,
     *     ran_at: int,
     *     evaluated: int,
     *     warnings: list<string>,
     *     reviewDecisions: int,
     *     suppressedDecisions: int,
     * }|null null when Dream is switched off, the repository has no Learning root, or nothing is worth saying
     */
    public function runIfDue(): ?array
    {
        if (getenv(self::OPT_OUT_ENV) === '0') {
            return null;
        }

        $layout = new ProjectLayout($this->rootPath);
        $learningRoot = $layout->learningRoot();
        if (!is_dir($learningRoot)) {
            return null;
        }

        $stateFile = $layout->stateRoot() . '/dream/auto.json';
        $previous = $this->readState($stateFile);
        $fingerprint = $this->fingerprint($learningRoot);
        $guidanceFingerprint = $this->guidanceFingerprint();
        $now = $this->clock !== null ? ($this->clock)() : time();

        $reason = $this->dueReason($previous, $fingerprint, $guidanceFingerprint, $now);
        if ($reason === null) {
            // Not due: stay quiet unless the last run left decisions waiting for a human.
            return $previous !== null && $previous['reviewDecisions'] > 0
                ? ['ran' => false, 'reason' => 'pending'] + $this->digest($previous)
                : null;
        }

        $outcome = (new WorkflowDreamService($this->rootPath))->preview()->outcome->result;
        $state = [
            'fingerprint' => $fingerprint,
            'guidance_fingerprint' => $guidanceFingerprint,
            'ran_at' => $now,
            'evaluated' => $outcome->evaluatedGuidanceCount,
            'warnings' => array_values(array_unique(array_map(static fn ($warning) => $warning->code, $outcome->warnings))),
            'reviewDecisions' => count($outcome->decisions),
            'suppressedDecisions' => count($outcome->suppressedDecisions),
        ];
        $this->writeState($stateFile, $state);

        return ['ran' => true, 'reason' => $reason] + $this->digest($state);
    }

    /** @param array{fingerprint: string, guidance_fingerprint: string|null, ran_at: int}|null $previous */
    private function dueReason(?array $previous, string $fingerprint, string $guidanceFingerprint, int $now): ?string
    {
        if ($previous === null) {
            return 'no previous automatic run';
        }
        if ($previous['fingerprint'] !== $fingerprint) {
            return 'Learning inputs changed since the last run';
        }
        if ($previous['guidance_fingerprint'] !== $guidanceFingerprint) {
            return 'project guidance changed since the last run';
        }
        if ($now - $previous['ran_at'] >= self::MAX_AGE_SECONDS) {
            return 'last run is older than 7 days';
        }

        return null;
    }

    private function fingerprint(string $learningRoot): string
    {
        $entries = [];
        foreach (self::FINGERPRINT_DIRECTORIES as $directory) {
            $path = $learningRoot . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'json') {
                    $entries[] = substr($file->getPathname(), strlen($learningRoot)) . "\0" . hash_file('sha256', $file->getPathname());
                }
            }
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    private function guidanceFingerprint(): string
    {
        $entries = [];
        foreach (self::PROJECT_GUIDANCE_FILES as $relativePath) {
            $path = $this->rootPath . '/' . $relativePath;
            if (is_file($path)) {
                $hash = hash_file('sha256', $path);
                if ($hash === false) {
                    throw new RuntimeException('Cannot fingerprint project guidance: ' . $path);
                }
                $entries[] = $relativePath . "\0" . $hash;
            }
        }

        return hash('sha256', implode("\n", $entries));
    }

    /**
     * @return array{fingerprint: string, guidance_fingerprint: string|null, ran_at: int, evaluated: int, warnings: list<string>, reviewDecisions: int, suppressedDecisions: int}|null
     */
    private function readState(string $file): ?array
    {
        $raw = is_file($file) ? file_get_contents($file) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (
            !is_array($data)
            || !is_string($data['fingerprint'] ?? null)
            || !is_int($data['ran_at'] ?? null)
            || !is_int($data['evaluated'] ?? null)
            || !is_array($data['warnings'] ?? null)
            || !is_int($data['reviewDecisions'] ?? null)
            || !is_int($data['suppressedDecisions'] ?? null)
        ) {
            // Unreadable or foreign state is simply "no previous run": Dream runs again and rewrites it.
            return null;
        }

        return [
            'fingerprint' => $data['fingerprint'],
            'guidance_fingerprint' => is_string($data['guidance_fingerprint'] ?? null) ? $data['guidance_fingerprint'] : null,
            'ran_at' => $data['ran_at'],
            'evaluated' => $data['evaluated'],
            'warnings' => array_values(array_filter($data['warnings'], is_string(...))),
            'reviewDecisions' => $data['reviewDecisions'],
            'suppressedDecisions' => $data['suppressedDecisions'],
        ];
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $file, array $state): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create Dream state directory: ' . $directory);
        }

        // Write-then-rename so two sessions starting together never leave a half-written file.
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n") === false || !rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('Cannot write Dream state file: ' . $file);
        }
    }

    /**
     * @param array{ran_at: int, evaluated: int, warnings: list<string>, reviewDecisions: int, suppressedDecisions: int, fingerprint?: string} $state
     *
     * @return array{ran_at: int, evaluated: int, warnings: list<string>, reviewDecisions: int, suppressedDecisions: int}
     */
    private function digest(array $state): array
    {
        return [
            'ran_at' => $state['ran_at'],
            'evaluated' => $state['evaluated'],
            'warnings' => $state['warnings'],
            'reviewDecisions' => $state['reviewDecisions'],
            'suppressedDecisions' => $state['suppressedDecisions'],
        ];
    }
}
