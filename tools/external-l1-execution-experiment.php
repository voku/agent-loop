<?php

declare(strict_types=1);

final class ExternalL1ExecutionFailure extends RuntimeException
{
}

final readonly class ExternalL1ExecutionExperiment
{
    public function parseComment(string $eventPath, string $outputDirectory): int
    {
        try {
            $event = $this->decodeJsonFile($eventPath);

            $owner = $event['repository']['owner']['login'] ?? null;
            $author = $event['comment']['user']['login'] ?? null;
            $commentId = $event['comment']['id'] ?? null;
            $issueNumber = $event['issue']['number'] ?? null;
            $pullRequest = $event['issue']['pull_request'] ?? null;
            $body = $event['comment']['body'] ?? null;

            if (!is_string($owner) || trim($owner) === '') {
                throw new ExternalL1ExecutionFailure('Issue-comment event has no repository owner.');
            }
            if (!is_string($author) || $author !== $owner) {
                throw new ExternalL1ExecutionFailure('External L1 execution must be requested by the repository owner.');
            }
            if (!is_array($pullRequest)) {
                throw new ExternalL1ExecutionFailure('External L1 execution is accepted only on a pull-request conversation.');
            }
            if (!is_int($commentId) || !is_int($issueNumber)) {
                throw new ExternalL1ExecutionFailure('Issue-comment event is missing numeric comment or issue identity.');
            }
            if (!is_string($body) || trim($body) === '') {
                throw new ExternalL1ExecutionFailure('External L1 execution comment is empty.');
            }

            $body = trim(str_replace("\r\n", "\n", $body));
            if (str_contains($body, "\n")) {
                throw new ExternalL1ExecutionFailure('External L1 execution request must be a single marker line.');
            }

            if (preg_match(
                '/^AGENT_LOOP_EXTERNAL_L1_EXECUTE return_run_id=([1-9][0-9]*) attempt=([1-9][0-9]*)$/',
                $body,
                $matches,
            ) !== 1) {
                throw new ExternalL1ExecutionFailure(
                    'Execution marker must be: AGENT_LOOP_EXTERNAL_L1_EXECUTE return_run_id=<id> attempt=<attempt>.',
                );
            }

            $returnRunId = $matches[1];
            $returnAttempt = $matches[2];
            $artifactName = 'external-l1-return-' . $returnRunId . '-' . $returnAttempt;

            $this->makeDirectory($outputDirectory);
            $this->write($outputDirectory . '/request.json', $this->prettyJson([
                'schema_version' => '1.0',
                'kind' => 'external_l1_execution_request',
                'return_run_id' => $returnRunId,
                'return_run_attempt' => $returnAttempt,
                'return_artifact_name' => $artifactName,
                'comment_id' => $commentId,
                'pull_request_number' => $issueNumber,
                'author' => $author,
            ]));

            fwrite(STDOUT, "External L1 execution request parse: OK\n");

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'External L1 execution request parse: FAILED - ' . $throwable->getMessage() . "\n");

            return 1;
        }
    }

    /** @return array<string, mixed> */
    private function decodeJsonFile(string $path): array
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new ExternalL1ExecutionFailure('Unable to read JSON file: ' . $path);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExternalL1ExecutionFailure('Invalid JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new ExternalL1ExecutionFailure('JSON file must decode to an object: ' . $path);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function prettyJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new ExternalL1ExecutionFailure('Unable to write file: ' . $path);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new ExternalL1ExecutionFailure('Unable to create directory: ' . $path);
        }
    }
}

/** @return non-empty-string */
function externalL1ExecutionRequiredOption(array $argv, string $name): string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $token) {
        if (str_starts_with($token, $prefix)) {
            $value = trim(substr($token, strlen($prefix)));
            if ($value !== '') {
                return $value;
            }
        }
    }

    throw new ExternalL1ExecutionFailure('Missing required option --' . $name . '=...');
}

try {
    $command = $argv[1] ?? null;
    if ($command !== 'parse-comment') {
        throw new ExternalL1ExecutionFailure(
            'Usage: php tools/external-l1-execution-experiment.php parse-comment --event=<event.json> --output-dir=<dir>',
        );
    }

    $event = externalL1ExecutionRequiredOption($argv, 'event');
    $outputDirectory = externalL1ExecutionRequiredOption($argv, 'output-dir');

    exit((new ExternalL1ExecutionExperiment())->parseComment($event, $outputDirectory));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'External L1 execution bootstrap: FAILED - ' . $throwable->getMessage() . "\n");
    exit(2);
}
