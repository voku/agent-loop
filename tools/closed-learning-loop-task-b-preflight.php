<?php

declare(strict_types=1);

use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ClosedLearningLoopTaskBPreflightFailure extends RuntimeException
{
}

final readonly class ClosedLearningLoopTaskBPreflight
{
    private const string TASK = 'CLOSED-LOOP-457-TASK-B';
    private const string NOTE_ID = 'learning-note.2026-09-12.457001';
    private const string SCOPE = 'tests/Dogfood/LearningNoteTwoRunDogfoodTest.php';
    private const string OPERATING_PROMPT = 'execution-dispatch';

    public function __construct(
        private string $repositoryRoot,
        private string $operatingPromptManifest,
    ) {
    }

    public function run(): int
    {
        $worktree = sys_get_temp_dir() . '/agent-loop-closed-learning-task-b-' . bin2hex(random_bytes(6));

        try {
            $this->runCommand(['git', 'worktree', 'add', '--quiet', '--detach', $worktree, 'HEAD'], $this->repositoryRoot);
            if (!symlink($this->repositoryRoot . '/vendor', $worktree . '/vendor')) {
                throw new ClosedLearningLoopTaskBPreflightFailure('Unable to expose the candidate Composer vendor tree in Task-B worktree.');
            }

            $notePath = $worktree . '/.agent-loop/learning/notes/active/' . self::NOTE_ID . '.json';
            if (!is_file($notePath)) {
                throw new ClosedLearningLoopTaskBPreflightFailure('Durable #457 LearningNote is absent from the Task-B checkout.');
            }

            $this->runCommand([
                PHP_BINARY,
                'vendor/bin/agent-learning',
                'lineage-rebuild',
                '--root=.agent-loop/learning',
                '--project-root=' . $worktree,
            ], $worktree);

            $this->runCommand([
                PHP_BINARY,
                'bin/agent-loop',
                'map',
                'build',
                '--paths=tests/Dogfood',
            ], $worktree);

            $this->runCommand([
                PHP_BINARY,
                'bin/agent-loop',
                'workflow',
                'plan',
                self::TASK,
                '--by',
                'closed-loop-dogfood',
                '--file',
                self::SCOPE,
                '--goal',
                'Prepare the smallest next #457 dogfood slice that can prove a durable prior LearningNote reaches the L2 construction boundary without pretending a model has executed it.',
                '--non-goal',
                'Do not hand-author the L1, mutate production code, promote the LearningNote, or claim behavioral usefulness from selection alone.',
                '--acceptance',
                'Recall selects learning-note.2026-09-12.457001 for this later relevant task and the L2 briefing includes that precedent before Loop stops at the missing execution-contract gate.',
                '--validation',
                'vendor/bin/phpunit tests/Dogfood/LearningNoteTwoRunDogfoodTest.php',
                '--tag',
                'dogfood',
                '--tag',
                'learning',
                '--tag',
                'workflow',
                '--operating-prompt-manifest',
                $this->operatingPromptManifest,
                '--operating-prompt',
                '{"id":"' . self::OPERATING_PROMPT . '","arguments":{}}',
            ], $worktree);

            $this->runCommand([
                PHP_BINARY,
                'bin/agent-loop',
                'workflow',
                'approve',
                self::TASK,
                '--by',
                'closed-loop-dogfood-approver',
            ], $worktree);

            $enter = $this->runCommand([
                PHP_BINARY,
                'bin/agent-loop',
                'enter',
                self::TASK,
                '--format=json',
            ], $worktree, throwOnFailure: false);
            if ($enter['exit_code'] !== 1) {
                throw new ClosedLearningLoopTaskBPreflightFailure(
                    'L2 Task B must stop before host work until a project-specific L1 exists; got enter exit '
                    . $enter['exit_code'] . '.',
                );
            }

            $enterPayload = $this->decodeObject($enter['stdout'], 'enter');
            $recallDir = $worktree . '/.agent-loop/recall/' . self::TASK;
            $compiled = (new CompiledRecallOutputReader())->read($recallDir);
            if ($compiled === null || !$compiled->hasFacts() || !$compiled->areFactsReadable()) {
                throw new ClosedLearningLoopTaskBPreflightFailure('Task-B Recall output does not expose readable compiled facts.');
            }

            $precedent = null;
            foreach ($compiled->facts() as $fact) {
                if ($fact->type !== 'learning_precedent') {
                    continue;
                }
                if (($fact->payload['note_id'] ?? null) === self::NOTE_ID) {
                    $precedent = $fact;
                    break;
                }
            }
            if ($precedent === null) {
                throw new ClosedLearningLoopTaskBPreflightFailure(
                    'Later relevant Task B did not receive the persisted #457 LearningNote from Recall.',
                );
            }

            if (($precedent->payload['evidence_state'] ?? null) !== 'current') {
                throw new ClosedLearningLoopTaskBPreflightFailure('Selected #457 precedent is not current repository evidence.');
            }
            $matchReasons = $precedent->payload['match_reasons'] ?? [];
            if (!is_array($matchReasons) || (!in_array('scope_match', $matchReasons, true) && !in_array('tag_match', $matchReasons, true))) {
                throw new ClosedLearningLoopTaskBPreflightFailure('Selected precedent has no task-context match reason.');
            }

            $systemPath = $recallDir . '/system.md';
            $system = $this->read($systemPath);
            foreach ([
                '## L2 Operational Prompt Construction',
                self::OPERATING_PROMPT,
                'Closed-loop proof requires a real durable precedent handoff',
                'Prompt inclusion alone is evidence of exposure, not usefulness.',
            ] as $needle) {
                if (!str_contains($system, $needle)) {
                    throw new ClosedLearningLoopTaskBPreflightFailure('Task-B L2 briefing lost required evidence: ' . $needle);
                }
            }

            $executionState = $enterPayload['manifest']['references']['execution_contract']['state'] ?? null;
            if ($executionState !== 'missing') {
                throw new ClosedLearningLoopTaskBPreflightFailure(
                    'Task-B preflight must stop at the missing L1 boundary; got execution-contract state '
                    . var_export($executionState, true) . '.',
                );
            }

            $compilationId = $compiled->compilationId();
            if (!is_string($compilationId) || trim($compilationId) === '') {
                throw new ClosedLearningLoopTaskBPreflightFailure('Task-B Recall output has no compilation identity.');
            }
            $bundleSha256 = $compiled->bundleSha256();
            if (!is_string($bundleSha256) || trim($bundleSha256) === '') {
                throw new ClosedLearningLoopTaskBPreflightFailure('Task-B Recall output has no bundle SHA-256.');
            }

            $report = [
                'schema_version' => '1.0',
                'issue' => 457,
                'task_b' => [
                    'task_id' => self::TASK,
                    'scope' => [self::SCOPE],
                    'tags' => ['dogfood', 'learning', 'workflow'],
                    'known_relevant_precedent' => self::NOTE_ID,
                ],
                'learning_to_recall' => [
                    'state' => 'proven',
                    'note_id' => self::NOTE_ID,
                    'pattern_key' => $precedent->payload['pattern_key'] ?? null,
                    'note_digest' => $precedent->payload['note_digest'] ?? null,
                    'evidence_state' => $precedent->payload['evidence_state'] ?? null,
                    'match_reasons' => array_values($matchReasons),
                    'source_ref' => $precedent->sourceRef,
                ],
                'recall_to_l2' => [
                    'state' => 'proven',
                    'compilation_id' => $compilationId,
                    'bundle_sha256' => $bundleSha256,
                    'operating_prompt' => self::OPERATING_PROMPT,
                    'l2_contains_precedent' => true,
                ],
                'l2_to_l1_model_handoff' => [
                    'state' => 'unknown',
                    'execution_contract_state' => 'missing',
                    'enter_exit_code' => $enter['exit_code'],
                    'reason' => 'This deterministic CI preflight intentionally does not synthesize an L1. A real coding-agent host must consume this exact L2 briefing, construct the project-specific L1, bind it through Loop, and then make an attributable engineering decision.',
                ],
                'guidance_outcome' => [
                    'state' => 'not_judged',
                    'reason' => 'Selection proves exposure only. No model executed the selected precedent, so helpful/irrelevant/harmful cannot be judged honestly.',
                ],
                'deterministic_promotion' => [
                    'state' => 'not_justified',
                    'reason' => 'No real-host behavioral outcome exists yet.',
                ],
                'result' => 'deterministic_handoff_proven_model_handoff_unknown',
            ];

            $build = $this->repositoryRoot . '/build';
            $this->makeDirectory($build);
            file_put_contents(
                $build . '/closed-learning-loop-task-b-preflight.json',
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            fwrite(STDOUT, "Closed learning-loop Task B: DETERMINISTIC_HANDOFF_PROVEN\n");
            fwrite(STDOUT, 'Precedent: ' . self::NOTE_ID . "\n");
            fwrite(STDOUT, 'Recall compilation: ' . $compilationId . "\n");
            fwrite(STDOUT, "L2 -> L1 model handoff: UNKNOWN\n");

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'Closed learning-loop Task B: FAILED - ' . $throwable->getMessage() . "\n");

            return 1;
        } finally {
            if (is_file($worktree . '/.git') || is_dir($worktree . '/.git')) {
                $this->runCommand(['git', 'worktree', 'remove', '--force', $worktree], $this->repositoryRoot, throwOnFailure: false);
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json, string $label): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ClosedLearningLoopTaskBPreflightFailure($label . ' did not return a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit_code:int, stdout:string, stderr:string}
     */
    private function runCommand(array $command, string $workingDirectory, bool $throwOnFailure = true): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new ClosedLearningLoopTaskBPreflightFailure('Unable to start command: ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $stdout = is_string($stdout) ? $stdout : '';
        $stderr = is_string($stderr) ? $stderr : '';

        if ($stdout !== '') {
            fwrite(STDOUT, $stdout);
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        if ($exitCode !== 0 && $throwOnFailure) {
            throw new ClosedLearningLoopTaskBPreflightFailure(sprintf(
                'Command failed with exit %d: %s',
                $exitCode,
                implode(' ', $command),
            ));
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new ClosedLearningLoopTaskBPreflightFailure('Unable to read Task-B artifact: ' . $path);
        }

        return $contents;
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new ClosedLearningLoopTaskBPreflightFailure('Unable to create directory: ' . $path);
        }
    }
}

$repositoryRoot = realpath(dirname(__DIR__));
$manifest = getenv('AGENT_LOOP_OPERATING_PROMPT_MANIFEST');
if (!is_string($repositoryRoot) || !is_string($manifest) || trim($manifest) === '' || !is_file($manifest)) {
    fwrite(STDERR, "Closed-loop Task-B preflight requires repository root and AGENT_LOOP_OPERATING_PROMPT_MANIFEST.\n");
    exit(2);
}

exit((new ClosedLearningLoopTaskBPreflight($repositoryRoot, $manifest))->run());
