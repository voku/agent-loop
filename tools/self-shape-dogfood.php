<?php

declare(strict_types=1);

/**
 * Repository self-shaping dogfood gate.
 *
 * Unlike the installed release-set gate, this executes the root checkout via
 * `php bin/agent-loop`. The governed task is the real diff from the merge-base
 * to HEAD; changed-file scope is derived from Git rather than duplicated in CI.
 */

final readonly class SelfShapeCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }
}

final class SelfShapeDogfood
{
    private const string TASK_ID = 'SELF-SHAPE';
    private const string ACTOR = 'agent-loop-self-shape';
    private const string LEARNING_ROOT = 'infra/doc/agent-learning';
    private const string BUILD_ROOT = 'build';

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $baseRef,
    ) {
    }

    public function run(): int
    {
        try {
            $this->ensureDirectory(self::BUILD_ROOT);
            $baseCommit = trim($this->mustRun(['git', 'merge-base', 'HEAD', 'origin/' . $this->baseRef])->stdout);
            $headCommit = trim($this->mustRun(['git', 'rev-parse', 'HEAD'])->stdout);
            $changedFiles = $this->changedFiles($baseCommit);

            $this->mustRun($this->agentLoop('learn', 'validate', '--root', self::LEARNING_ROOT));
            $this->mustRun($this->planCommand($baseCommit, $changedFiles));
            $this->mustRun($this->agentLoop(
                'workflow',
                'approve',
                self::TASK_ID,
                '--by',
                self::ACTOR,
                '--learning-root',
                self::LEARNING_ROOT,
            ));
            $this->capture(
                $this->agentLoop(
                    'workflow',
                    'context',
                    self::TASK_ID,
                    '--format',
                    'json',
                    '--learning-root',
                    self::LEARNING_ROOT,
                ),
                self::BUILD_ROOT . '/self-shape-context.json',
            );

            $this->mustRun(['composer', 'ci']);
            $this->mustRun($this->agentLoop(
                'session',
                'validation',
                'record',
                self::TASK_ID,
                '--brief-revision',
                '1',
                '--command',
                'composer ci',
                '--status',
                'passed',
                '--exit-code',
                '0',
                '--duration-ms',
                '0',
                '--by',
                self::ACTOR,
            ));

            // The first review observes the deliberately incomplete close-out.
            $this->mustRun($this->agentLoop('review', 'blindspots', self::TASK_ID), [0, 1]);

            $this->mustRun($this->agentLoop(
                'recall',
                'log-outcome',
                '--root',
                self::LEARNING_ROOT,
                '--draft',
                'recall/' . self::TASK_ID . '/recall-log.draft.json',
                '--by',
                self::ACTOR,
                '--commit',
                $headCommit,
            ));
            $this->mustRun($this->agentLoop(
                'session',
                'checkpoint',
                self::TASK_ID,
                '--title',
                'review blindspots close-out',
                '--body',
                'Reviewed the deterministic review blindspots report for this change against ' . $baseCommit . '.',
            ));
            $this->mustRun($this->agentLoop(
                'session',
                'learning',
                'decide',
                self::TASK_ID,
                '--status',
                'no_durable_learning',
                '--by',
                self::ACTOR,
            ));

            // Final review is a gate. WARN is not an accepted completion state.
            $this->mustRun($this->agentLoop('review', 'blindspots', self::TASK_ID));
            $this->assertMemoryPromotionQueueIsEmpty();

            $this->capture(
                $this->agentLoop('workflow', 'manifest', self::TASK_ID, '--write', '--format', 'json'),
                self::BUILD_ROOT . '/self-shape-manifest-before-close.json',
            );
            $this->mustRun($this->agentLoop('verify'));
            $this->capture(
                $this->reportCommand($changedFiles),
                self::BUILD_ROOT . '/self-shape-report.json',
            );
            $this->mustRun($this->agentLoop('workflow', 'close', self::TASK_ID, '--status', 'done'));
            $status = $this->capture(
                $this->agentLoop('workflow', 'status', self::TASK_ID, '--format', 'json'),
                self::BUILD_ROOT . '/self-shape-status.json',
            );
            $this->assertFinalStatus($status->stdout);

            echo "Self-shape dogfood: PASSED\n";
            echo 'Base: ' . $baseCommit . "\n";
            echo 'Head: ' . $headCommit . "\n";
            echo 'Changed files: ' . count($changedFiles) . "\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] self-shape dogfood: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** @return list<string> */
    private function changedFiles(string $baseCommit): array
    {
        $result = $this->mustRun(['git', 'diff', '--name-only', '--diff-filter=ACMRTUXB', $baseCommit, 'HEAD', '--']);
        $lines = preg_split('/\R/', trim($result->stdout));
        if ($lines === false) {
            throw new RuntimeException('Could not split git diff output.');
        }

        $files = [];
        foreach ($lines as $line) {
            $path = trim($line);
            if ($path !== '') {
                $files[] = $path;
            }
        }

        if ($files === []) {
            throw new RuntimeException('Self-shape requires at least one changed file between the merge-base and HEAD.');
        }

        return $files;
    }

    /**
     * @param list<string> $changedFiles
     *
     * @return list<string>
     */
    private function planCommand(string $baseCommit, array $changedFiles): array
    {
        $command = $this->agentLoop(
            'workflow',
            'plan',
            self::TASK_ID,
            '--by',
            self::ACTOR,
            '--learning-root',
            self::LEARNING_ROOT,
            '--base-commit',
            $baseCommit,
        );

        foreach ($changedFiles as $file) {
            $command[] = '--file';
            $command[] = $file;
        }

        return [
            ...$command,
            '--goal',
            'Shape agent-loop with agent-loop: reduce internal orchestration glue while preserving behavior and persist evidence-backed project learning.',
            '--non-goal',
            'Do not move package ownership, add a shared core package, or expand the public workflow surface.',
            '--validation',
            'composer ci',
            '--behavior-anchor',
            'agent-loop workflow command -> typed package API -> persisted governed state -> validation -> review -> learning -> close',
        ];
    }

    /**
     * @param list<string> $changedFiles
     *
     * @return list<string>
     */
    private function reportCommand(array $changedFiles): array
    {
        $command = $this->agentLoop(
            'workflow',
            'report',
            self::TASK_ID,
            '--format',
            'json',
            '--learning-root',
            self::LEARNING_ROOT,
        );

        foreach ($changedFiles as $file) {
            $command[] = '--changed-file';
            $command[] = $file;
        }

        return $command;
    }

    private function assertMemoryPromotionQueueIsEmpty(): void
    {
        $result = $this->mustRun($this->agentLoop('memory', 'review', '--file=MEMORY.md'));
        if (!str_contains($result->stdout, 'Rows still needing promotion review: 0')) {
            throw new RuntimeException('MEMORY.md still contains an unresolved promotion row.');
        }
    }

    private function assertFinalStatus(string $json): void
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Workflow status did not decode to an object.');
        }

        $manifest = $data['manifest'] ?? null;
        if (!is_array($manifest)) {
            throw new RuntimeException('Workflow status is missing the manifest projection.');
        }

        $references = $manifest['references'] ?? null;
        if (!is_array($references)) {
            throw new RuntimeException('Workflow status is missing manifest references.');
        }

        $session = $references['session'] ?? null;
        $review = $references['review'] ?? null;
        if (
            ($manifest['state'] ?? null) !== 'complete'
            || ($manifest['next_action'] ?? null) !== 'none'
            || !is_array($session)
            || ($session['state'] ?? null) !== 'done'
            || !is_array($review)
            || ($review['state'] ?? null) !== 'ok'
        ) {
            throw new RuntimeException('Final workflow projection is not complete/done/clean.');
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function agentLoop(string ...$arguments): array
    {
        return [PHP_BINARY, 'bin/agent-loop', ...$arguments];
    }

    /**
     * @param list<string> $command
     */
    private function capture(array $command, string $relativePath): SelfShapeCommandResult
    {
        $result = $this->mustRun($command);
        $path = $this->repositoryRoot . '/' . $relativePath;
        if (file_put_contents($path, $result->stdout) === false) {
            throw new RuntimeException('Could not write self-shape artifact: ' . $relativePath);
        }

        return $result;
    }

    /**
     * @param list<string> $command
     * @param list<int>    $acceptedExitCodes
     */
    private function mustRun(array $command, array $acceptedExitCodes = [0]): SelfShapeCommandResult
    {
        echo '$ ' . $this->display($command) . "\n";

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repositoryRoot,
        );
        if (!is_resource($process) || !isset($pipes[1], $pipes[2])) {
            throw new RuntimeException('Could not start command: ' . $this->display($command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = $stdout === false ? '' : $stdout;
        $stderr = $stderr === false ? '' : $stderr;

        if ($stdout !== '') {
            echo $stdout;
            if (!str_ends_with($stdout, "\n")) {
                echo "\n";
            }
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
            if (!str_ends_with($stderr, "\n")) {
                fwrite(STDERR, "\n");
            }
        }

        if (!in_array($exitCode, $acceptedExitCodes, true)) {
            throw new RuntimeException(sprintf(
                'Command exited %d: %s',
                $exitCode,
                $this->display($command),
            ));
        }

        return new SelfShapeCommandResult($exitCode, $stdout, $stderr);
    }

    private function ensureDirectory(string $relativePath): void
    {
        $path = $this->repositoryRoot . '/' . $relativePath;
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create directory: ' . $relativePath);
        }
    }

    /** @param list<string> $command */
    private function display(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $argument): string => preg_match('/\A[A-Za-z0-9_\.\/\\:=@-]+\z/', $argument) === 1
                ? $argument
                : "'" . str_replace("'", "'\\''", $argument) . "'",
            $command,
        ));
    }
}

/** @param list<string> $argv */
function selfShapeBaseRef(array $argv): string
{
    $environmentBaseRef = getenv('GITHUB_BASE_REF');
    $baseRef = is_string($environmentBaseRef) && $environmentBaseRef !== '' ? $environmentBaseRef : 'main';

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--base-ref=')) {
            $baseRef = substr($argument, strlen('--base-ref='));
        }
    }

    if ($baseRef === '' || preg_match('/\A[A-Za-z0-9._\/-]+\z/', $baseRef) !== 1 || str_contains($baseRef, '..')) {
        throw new InvalidArgumentException('Invalid --base-ref value.');
    }

    return $baseRef;
}

try {
    $baseRef = selfShapeBaseRef($argv);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] self-shape dogfood: ' . $exception->getMessage() . "\n");
    exit(1);
}

exit((new SelfShapeDogfood(dirname(__DIR__), $baseRef))->run());
