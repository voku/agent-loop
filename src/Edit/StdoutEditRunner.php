<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;

final readonly class StdoutEditRunner implements EditRunner
{
    public function run(EditExecution $execution): EditRunResult
    {
        if ($execution->request->printPrompt) {
            $prompt = file_get_contents($execution->promptPath);
            if (!is_string($prompt)) {
                throw new RuntimeException('Unable to read compiled edit prompt: ' . $execution->promptPath);
            }
            fwrite(STDOUT, $prompt);
        }

        return new EditRunResult('prepared');
    }
}
