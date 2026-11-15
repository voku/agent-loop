<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Run\CanonicalJson;

/** Explicit issue, inspect and revoke operations for the local task-autonomy grant. */
final readonly class WorkflowAutonomyCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $json = in_array('--format=json', $args, true) || in_array('--json', $args, true);
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $operation = $args[1] ?? '';
            $options = $this->parse(array_slice($args, 2), $operation);
            $store = new TaskAutonomyGrantStore($this->rootPath);
            $now = new DateTimeImmutable('now');
            $grant = match ($operation) {
                'issue' => $store->issue($taskId->value, $options['by'] ?? '', $now, new DateTimeImmutable($options['until'] ?? '')),
                'revoke' => $store->revoke($taskId->value, $options['by'] ?? '', $now),
                'status' => $store->find($taskId->value),
                default => throw new InvalidArgumentException('Expected issue, status, or revoke.'),
            };
            $contract = (new TaskContractStore($this->rootPath))->find($taskId->value);
            $active = $grant !== null && $contract !== null && $grant->isEligibleFor($contract, 'review', $now);
            $payload = [
                'schema_version' => '1.0',
                'command' => 'workflow autonomy',
                'task_id' => $taskId->value,
                'operation' => $operation,
                'status' => $grant === null ? 'not_granted' : ($active ? 'active' : ($grant->revokedAt !== null ? 'revoked' : 'inactive')),
                'active' => $active,
                'grant' => $grant === null ? null : [
                    'contract_revision' => $grant->contractRevision,
                    'contract_digest' => $grant->contractDigest,
                    'requested_by' => $grant->requestedBy,
                    'issued_at' => $grant->issuedAt->format(DATE_ATOM),
                    'expires_at' => $grant->expiresAt->format(DATE_ATOM),
                    'revoked_at' => $grant->revokedAt?->format(DATE_ATOM),
                    'revoked_by' => $grant->revokedBy,
                ],
                'next_action_kind' => 'none',
            ];
            if ($json) {
                echo CanonicalJson::pretty($payload);
            } else {
                echo sprintf("[OK] workflow autonomy: %s for %s\n", $payload['status'], $taskId->value);
                if ($grant !== null) {
                    echo sprintf("Contract: revision %d (%s)\nExpires: %s\n", $grant->contractRevision, $grant->contractDigest, $grant->expiresAt->format(DATE_ATOM));
                }
            }

            return 0;
        } catch (Throwable $exception) {
            $payload = [
                'schema_version' => '1.0',
                'command' => 'workflow autonomy',
                'status' => 'error',
                'error' => $exception->getMessage(),
                'next_action_kind' => 'host_work',
            ];
            if ($json) {
                echo CanonicalJson::pretty($payload);
            } else {
                fwrite(STDERR, '[FAIL] workflow autonomy: ' . $exception->getMessage() . "\n");
            }

            return 1;
        }
    }

    /** @param list<string> $tokens
     * @return array{by: ?string, until: ?string}
     */
    private function parse(array $tokens, string $operation): array
    {
        $options = ['by' => null, 'until' => null];
        for ($index = 0; $index < count($tokens); ++$index) {
            $option = $tokens[$index];
            if ($option === '--json' || $option === '--format=json') {
                continue;
            }
            if ($option === '--format') {
                if (!in_array($tokens[$index + 1] ?? null, ['json', 'text'], true)) {
                    throw new InvalidArgumentException('--format must be text or json.');
                }
                ++$index;
                continue;
            }
            if (!in_array($option, ['--by', '--until'], true) || !isset($tokens[$index + 1]) || str_starts_with($tokens[$index + 1], '--')) {
                throw new InvalidArgumentException('Expected --by <actor> and, for issue, --until <date-time>.');
            }
            $value = trim($tokens[++$index]);
            if ($value === '') {
                throw new InvalidArgumentException($option . ' requires a non-empty value.');
            }
            $key = $option === '--by' ? 'by' : 'until';
            $options[$key] = $value;
        }
        if (in_array($operation, ['issue', 'revoke'], true) && $options['by'] === null) {
            throw new InvalidArgumentException('--by is required for issue and revoke.');
        }
        if ($operation === 'issue' && $options['until'] === null) {
            throw new InvalidArgumentException('--until is required for issue.');
        }
        if ($operation === 'status' && ($options['by'] !== null || $options['until'] !== null)) {
            throw new InvalidArgumentException('status is read-only and accepts no grant options.');
        }

        return $options;
    }
}
