<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use JsonException;
use RuntimeException;

/**
 * Records a human override of the workflow close gates.
 *
 * An override is a decision, and a decision needs an owner and a reason: who overrode it, why, and
 * exactly which gates were failing at that moment - including which evidence was missing. Writing
 * only "risk accepted" would produce the thing this exists to prevent, a green close nobody can be
 * asked about afterwards.
 *
 * Two files, on purpose: the Markdown is what a human reads in review, the JSON is what a later
 * projection can count without parsing prose.
 */
final readonly class AcceptedRiskWriter
{
    public function __construct(private string $rootPath)
    {
    }

    public function relativePath(string $taskId): string
    {
        return '.agent-loop/risks/' . $taskId . '.accepted-risk.md';
    }

    public function relativeJsonPath(string $taskId): string
    {
        return '.agent-loop/risks/' . $taskId . '.accepted-risk.json';
    }

    /**
     * @param list<array{gate: string, detail: string}> $failures gates that were failing when the
     *                                                           override was recorded
     */
    public function write(string $taskId, string $reason, string $acceptedBy, array $failures = []): string
    {
        $dir = rtrim($this->rootPath, '/') . '/.agent-loop/risks';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create accepted-risk directory: ' . $dir);
        }

        $relative = $this->relativePath($taskId);
        $content = "# Accepted risk for {$taskId}\n\n";
        $content .= "Accepted by: {$acceptedBy}\n";
        $content .= "Reason: {$reason}\n\n";
        $content .= "## Gates that failed\n\n";
        if ($failures === []) {
            $content .= "None. The override was recorded while every gate passed.\n";
        } else {
            foreach ($failures as $failure) {
                $content .= "- `{$failure['gate']}`: {$failure['detail']}\n";
            }
        }
        $content .= "\nBypassing workflow close gates does not approve code or durable learning.\n";
        $content .= "Human review remains required.\n";

        $this->put($relative, $content);
        $this->put($this->relativeJsonPath($taskId), $this->json([
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'accepted_by' => $acceptedBy,
            'reason' => $reason,
            'failed_gates' => $failures,
        ]));

        return $relative;
    }

    private function put(string $relative, string $content): void
    {
        $path = rtrim($this->rootPath, '/') . '/' . $relative;
        if (is_dir($path)) {
            throw new RuntimeException('Could not write accepted-risk file: ' . $relative);
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Could not write accepted-risk file: ' . $relative);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        try {
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode accepted-risk JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
