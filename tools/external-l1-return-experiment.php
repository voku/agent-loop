<?php

declare(strict_types=1);

final class ExternalL1ReturnFailure extends RuntimeException
{
}

final readonly class ExternalL1ReturnExperiment
{
    /** @var list<string> */
    private const array REQUIRED_SECTIONS = ['Goal', 'Context', 'Constraints', 'Verification', 'Done When'];

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
                throw new ExternalL1ReturnFailure('Issue-comment event has no repository owner.');
            }
            if (!is_string($author) || $author !== $owner) {
                throw new ExternalL1ReturnFailure('External L1 return must be posted by the repository owner.');
            }
            if (!is_array($pullRequest)) {
                throw new ExternalL1ReturnFailure('External L1 return is accepted only on a pull-request conversation.');
            }
            if (!is_int($commentId) || !is_int($issueNumber)) {
                throw new ExternalL1ReturnFailure('Issue-comment event is missing numeric comment or issue identity.');
            }
            if (!is_string($body) || trim($body) === '') {
                throw new ExternalL1ReturnFailure('External L1 return comment is empty.');
            }

            $body = str_replace("\r\n", "\n", trim($body));
            $parts = explode("\n", $body, 2);
            $header = trim($parts[0]);
            $l1 = isset($parts[1]) ? trim($parts[1]) : '';

            if (preg_match(
                '/^AGENT_LOOP_EXTERNAL_L1_RETURN run_id=([1-9][0-9]*) attempt=([1-9][0-9]*)$/',
                $header,
                $matches,
            ) !== 1) {
                throw new ExternalL1ReturnFailure(
                    'External L1 return header must be: AGENT_LOOP_EXTERNAL_L1_RETURN run_id=<id> attempt=<attempt>.',
                );
            }
            if ($l1 === '') {
                throw new ExternalL1ReturnFailure('External L1 return contains no L1 Markdown after the header.');
            }

            $this->assertL1Shape($l1);

            $runId = $matches[1];
            $attempt = $matches[2];
            $artifactName = 'external-l1-handoff-' . $runId . '-' . $attempt;

            $this->makeDirectory($outputDirectory);
            $this->write($outputDirectory . '/l1.md', $l1 . "\n");
            $this->write($outputDirectory . '/return.json', $this->prettyJson([
                'schema_version' => '1.0',
                'kind' => 'external_l1_return',
                'run_id' => $runId,
                'run_attempt' => $attempt,
                'artifact_name' => $artifactName,
                'comment_id' => $commentId,
                'pull_request_number' => $issueNumber,
                'author' => $author,
                'l1_sha256' => 'sha256:' . hash('sha256', $l1 . "\n"),
            ]));

            fwrite(STDOUT, "External L1 return parse: OK\n");

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'External L1 return parse: FAILED - ' . $throwable->getMessage() . "\n");

            return 1;
        }
    }

    private function assertL1Shape(string $l1): void
    {
        preg_match_all('/^## ([^\r\n]+)$/m', $l1, $matches);
        $sections = is_array($matches[1] ?? null) ? array_values($matches[1]) : [];
        if ($sections !== self::REQUIRED_SECTIONS) {
            throw new ExternalL1ReturnFailure(
                'Returned L1 must contain exactly these level-2 sections in order: '
                . implode(', ', self::REQUIRED_SECTIONS) . '.',
            );
        }

        foreach (self::REQUIRED_SECTIONS as $section) {
            $pattern = '/^## ' . preg_quote($section, '/') . '\R(?<body>.*?)(?=^## |\z)/ms';
            if (preg_match($pattern, $l1, $match) !== 1 || trim((string) ($match['body'] ?? '')) === '') {
                throw new ExternalL1ReturnFailure('Returned L1 section is empty: ' . $section . '.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeJsonFile(string $path): array
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new ExternalL1ReturnFailure('Unable to read JSON file: ' . $path);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExternalL1ReturnFailure('Invalid JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new ExternalL1ReturnFailure('JSON file must decode to an object: ' . $path);
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
            throw new ExternalL1ReturnFailure('Unable to write file: ' . $path);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new ExternalL1ReturnFailure('Unable to create directory: ' . $path);
        }
    }
}

/** @return non-empty-string */
function requiredOption(array $argv, string $name): string
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

    throw new ExternalL1ReturnFailure('Missing required option --' . $name . '=...');
}

try {
    $command = $argv[1] ?? null;
    if ($command !== 'parse-comment') {
        throw new ExternalL1ReturnFailure(
            'Usage: php tools/external-l1-return-experiment.php parse-comment --event=<event.json> --output-dir=<dir>',
        );
    }

    $event = requiredOption($argv, 'event');
    $outputDirectory = requiredOption($argv, 'output-dir');

    exit((new ExternalL1ReturnExperiment())->parseComment($event, $outputDirectory));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'External L1 return bootstrap: FAILED - ' . $throwable->getMessage() . "\n");
    exit(2);
}
