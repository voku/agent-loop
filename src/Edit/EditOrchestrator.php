<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use JsonException;
use RuntimeException;

final readonly class EditOrchestrator
{
    public function __construct(
        private EditMapProvider $mapPreparer = new EditMapPreparer(),
        private EditBriefingCompiler $briefingCompiler = new RecallEditBriefingCompiler(),
        private StdoutEditRunner $stdoutRunner = new StdoutEditRunner(),
        private CommandEditRunner $commandRunner = new CommandEditRunner(),
        private MechanicalEditRunner $mechanicalRunner = new MechanicalEditRunner(),
    ) {
    }

    public function execute(EditRequest $request): EditOutcome
    {
        $map = $this->mapPreparer->prepare($request);
        $this->ensureDirectory($request->outputDirectory);

        $briefing = $this->briefingCompiler->compile($request);
        $prompt = $briefing->prompt($request->instruction, $request->focusTerms);
        $requestPath = $request->outputDirectory . '/request.json';
        $promptPath = $request->outputDirectory . '/prompt.md';
        $executionPath = $request->outputDirectory . '/execution.json';

        $this->write($requestPath, $this->json($request->toArray()));
        $this->write($promptPath, $prompt);

        $execution = new EditExecution($request, $promptPath, $map->resolveMethod($request->target));
        $routing = $this->route($request);
        $runResult = $request->dryRun
            ? new EditRunResult('prepared')
            : ($routing->selectedRunner === null
                ? new EditRunResult(
                    status: 'escalation_required',
                    exitCode: 2,
                    stdout: 'No external model runner was invoked. Provide an exact replacement for mechanical execution or explicitly select --runner=command.',
                )
                : $this->runner($routing->selectedRunner)->run($execution));

        $evidence = $this->writeRunnerEvidence($request->outputDirectory, $runResult);
        $mapIndexDigest = hash_file('sha256', $request->mapIndex);
        if (!is_string($mapIndexDigest)) {
            throw new RuntimeException('Unable to hash agent-map index: ' . $request->mapIndex);
        }

        $executionData = [
            'schema_version' => '1.0',
            'status' => $runResult->status,
            'task_id' => $request->taskId,
            'target' => $request->target,
            'map_digest' => $map->mapDigest(),
            'map_index_sha256' => 'sha256:' . $mapIndexDigest,
            'recall_bundle_sha256' => $briefing->bundleDigest,
            'prompt_sha256' => 'sha256:' . hash('sha256', $prompt),
            'runner' => [
                'name' => $routing->selectedRunner ?? 'none',
                'command' => $routing->selectedRunner === 'command' ? $request->runnerCommand : null,
                'arguments' => $routing->selectedRunner === 'command' ? $request->runnerArguments : [],
                'exit_code' => $runResult->exitCode,
                'dry_run' => $request->dryRun,
                'timeout_seconds' => $request->runnerTimeoutSeconds,
                'replacement' => $routing->selectedRunner === 'mechanical'
                    ? ['old' => $request->replacementOld, 'new' => $request->replacementNew]
                    : null,
                'model_input_tokens' => $routing->invokesExternalModel() && !$request->dryRun ? null : 0,
                'model_tool_calls' => $routing->invokesExternalModel() && !$request->dryRun ? null : 0,
            ],
            'routing' => [
                'requested_runner' => $request->runner,
                'selected_runner' => $routing->selectedRunner ?? 'none',
                'reason' => $routing->reason,
                'external_model_invoked' => $routing->invokesExternalModel() && !$request->dryRun,
            ],
            'artifacts' => [
                'request' => $requestPath,
                'prompt' => $promptPath,
                'recall' => $briefing->outputDirectory,
                'runner_stdout' => $evidence['stdout'],
                'runner_stderr' => $evidence['stderr'],
            ],
        ];
        $this->write($executionPath, $this->json($executionData));

        return new EditOutcome(
            outputDirectory: $request->outputDirectory,
            promptPath: $promptPath,
            executionPath: $executionPath,
            mapDigest: $map->mapDigest(),
            recallBundleDigest: $briefing->bundleDigest,
            runResult: $runResult,
        );
    }

    private function runner(string $name): EditRunner
    {
        return match ($name) {
            'stdout' => $this->stdoutRunner,
            'command' => $this->commandRunner,
            'mechanical' => $this->mechanicalRunner,
            default => throw new RuntimeException('Unknown edit runner: ' . $name),
        };
    }

    private function route(EditRequest $request): EditRoutingDecision
    {
        if ($request->runner !== 'auto') {
            return new EditRoutingDecision($request->runner, 'Explicit runner selected by caller.');
        }
        if ($request->replacementOld !== null && $request->replacementNew !== null) {
            return new EditRoutingDecision('mechanical', 'Exact replacement supplied; selected scoped mechanical execution.');
        }

        return new EditRoutingDecision(null, 'No exact replacement proof was supplied; external model execution requires an explicit --runner=command.');
    }

    /** @return array{stdout: ?string, stderr: ?string} */
    private function writeRunnerEvidence(string $outputDirectory, EditRunResult $result): array
    {
        if ($result->stdout === '' && $result->stderr === '' && $result->exitCode === null) {
            return ['stdout' => null, 'stderr' => null];
        }

        $directory = $outputDirectory . '/evidence/runner';
        $this->ensureDirectory($directory);
        $stdoutPath = $directory . '/stdout.txt';
        $stderrPath = $directory . '/stderr.txt';
        $this->write($stdoutPath, $result->stdout);
        $this->write($stderrPath, $result->stderr);

        return ['stdout' => $stdoutPath, 'stderr' => $stderrPath];
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create edit directory: ' . $directory);
        }
    }

    private function write(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write edit artifact: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish edit artifact: ' . $path);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode edit artifact JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
