<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use JsonException;
use RuntimeException;

/**
 * Writes `agent-result.json`: the answer sheet a verifier grades against the private key.
 *
 * The file is seeded, not authored. Everything the orchestrator observed itself - which plan the
 * edit is bound to, which files actually changed, which commands actually ran with which exit code
 * - is filled in here and is not the agent's to state. The two things only the agent can supply,
 * `probe_answers` and `checklist_evidence`, are seeded as empty slots keyed by the plan's own ids,
 * so an unanswered probe is visibly unanswered instead of silently absent.
 *
 * Deliberately absent, and not to be added later: expected answers, scores, verdicts, generated
 * learnings. Those belong to the verifier. A result file that can grade itself is not evidence.
 */
final readonly class AgentResultWriter
{
    public const FILE_NAME = 'agent-result.json';

    /**
     * @param list<array{id: string, exit_code: int|null, stdout_sha256: string|null}> $commands
     * @param string $canonicalTarget resolved symbol id, e.g. `method:App\\Service\\UserService::save`
     *
     * @return array<string, mixed> the payload as written
     */
    public function write(
        string $outputDirectory,
        EditRequest $request,
        EditRunResult $runResult,
        VerificationPlanBinding $binding,
        WorkingTreeSnapshot $before,
        WorkingTreeSnapshot $after,
        array $commands,
        string $canonicalTarget,
    ): array {
        $payload = [
            'schema_version' => '1.0',
            'task_id' => $request->taskId,
            // The canonical symbol id, because that is what the plan and the key are keyed on. The
            // string the caller typed is kept beside it so the bundle stays readable by a human.
            'target' => $canonicalTarget,
            'requested_target' => $request->target,
            'verification_plan_sha256' => $binding->planSha256,
            'probe_answers' => $binding->emptyProbeAnswers(),
            'checklist_evidence' => $binding->emptyChecklistEvidence(),
            'changed_files' => $after->changedPathsSince($before),
            // An unavailable snapshot is not the same as an edit that changed nothing, and a
            // verifier must be able to tell those apart before trusting an empty list.
            'changed_files_source' => $before->available && $after->available ? 'git_status_diff' : 'unavailable',
            'commands' => $commands,
            'runner' => [
                'status' => $runResult->status,
                'exit_code' => $runResult->exitCode,
                'dry_run' => $request->dryRun,
            ],
        ];

        $this->write_file($outputDirectory . '/' . self::FILE_NAME, $this->json($payload));

        return $payload;
    }

    private function write_file(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create edit directory: ' . $directory);
        }

        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write edit artifact: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish edit artifact: ' . $path);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode agent result JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
