<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;
use voku\AgentLoop\Process\CommandProcessRunner;

final readonly class CommandEditRunner implements EditRunner
{
    public function run(EditExecution $execution): EditRunResult
    {
        $command = $execution->request->runnerCommand;
        if ($command === null || trim($command) === '') {
            throw new RuntimeException('Command runner has no executable.');
        }

        $result = (new CommandProcessRunner())->run(
            [$command, ...$execution->request->runnerArguments],
            $execution->request->projectRoot,
            $execution->request->runnerTimeoutSeconds,
            $execution->promptPath,
        );
        $stderr = $result->stderr;
        if ($result->timedOut) {
            $stderr .= ($stderr === '' ? '' : "\n")
                . 'Runner timed out after ' . $execution->request->runnerTimeoutSeconds . ' seconds.';
        }

        return new EditRunResult(
            status: $result->exitCode === 0 ? 'runner_succeeded' : 'runner_failed',
            exitCode: $result->exitCode,
            stdout: $result->stdout,
            stderr: $stderr,
        );
    }
}
