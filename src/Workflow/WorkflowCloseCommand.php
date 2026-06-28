<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;

final readonly class WorkflowCloseCommand
{
    /** @param callable(list<string>): int $sessionRunner @param callable(list<string>): int $verifyRunner */
    public function __construct(private string $rootPath, private mixed $sessionRunner, private mixed $verifyRunner)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $options = $this->parse(array_slice($args, 1));
            if ($options['status'] !== 'done') {
                echo "[FAIL] workflow close currently gates only --status done. Use agent-loop session close directly for other statuses.\n";
                return 1;
            }

            $failed = !$this->runGates($taskId->value);
            if ($failed && $options['acceptRisk'] === null) {
                echo "[FAIL] workflow close: gates failed; session was not closed.\n";
                return 1;
            }

            $acceptedRisk = $options['acceptRisk'] !== null;
            if ($acceptedRisk) {
                $path = (new AcceptedRiskWriter($this->rootPath))->write($taskId->value, $options['acceptRisk']);
                echo "[WARN] workflow close: accepted risk recorded at {$path}\n";
                if ($failed) {
                    echo "[WARN] workflow close: delegating to session close despite failed gates\n";
                }
            } else {
                echo "[OK] workflow close: gates passed; delegating to session close\n";
            }

            $exit = ($this->sessionRunner)(['close', $taskId->value, '--status', 'done']);
            if ($exit !== 0 && $acceptedRisk) {
                echo "[FAIL] workflow close: session close failed after accepted-risk bypass\n";
            }

            return $exit;
        } catch (InvalidArgumentException $e) {
            fwrite(\STDERR, '[FAIL] workflow close: ' . $e->getMessage() . "\n");
            return 1;
        } catch (Throwable $e) {
            fwrite(\STDERR, '[FAIL] workflow close: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function runGates(string $taskId): bool
    {
        $recallPassed = $this->checkRecall($taskId);
        $reviewPassed = $this->checkReview($taskId);
        $verifyPassed = $this->checkVerify();

        return $recallPassed && $reviewPassed && $verifyPassed;
    }

    private function checkRecall(string $taskId): bool
    {
        $relative = 'recall/' . $taskId . '/meta.json';
        if (is_file(rtrim($this->rootPath, '/') . '/' . $relative)) {
            echo "[OK] recall: found {$relative}\n";
            return true;
        }

        echo "[FAIL] recall: missing {$relative}\n";
        return false;
    }

    private function checkReview(string $taskId): bool
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $relative = $reader->relativePath($taskId);
        $report = $reader->read($taskId);

        if (!$report['exists']) {
            echo "[FAIL] review: missing {$relative}\n";
            echo "[ACTION REQUIRED] Run agent-loop review blindspots {$taskId} before workflow close.\n";
            return false;
        }

        if ($report['invalid']) {
            echo "[FAIL] review: blindspot report JSON is invalid or missing status\n";
            return false;
        }

        if ($report['status'] === 'fail') {
            echo "[FAIL] review: blindspot report status is fail\n";
            return false;
        }

        echo "[OK] review: found {$relative} with status {$report['status']}\n";
        return true;
    }

    private function checkVerify(): bool
    {
        if (($this->verifyRunner)([]) === 0) {
            echo "[OK] verify: agent-loop verify passed\n";
            return true;
        }

        echo "[FAIL] verify: agent-loop verify failed\n";
        return false;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{status: string, acceptRisk: string|null}
     */
    private function parse(array $tokens): array
    {
        $status = null;
        $risk = null;
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!in_array($token, ['--status', '--accept-risk'], true)) {
                throw new InvalidArgumentException('Unknown option: ' . $token);
            }
            if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                throw new InvalidArgumentException($token . ' requires a value.');
            }
            $value = $tokens[++$i];
            if ($token === '--status') {
                $status = $value;
            } else {
                $risk = $value;
            }
        }
        if ($status === null || trim($status) === '') {
            throw new InvalidArgumentException('--status done is required.');
        }
        if ($risk !== null && trim($risk) === '') {
            throw new InvalidArgumentException('--accept-risk requires a non-empty reason.');
        }

        return ['status' => $status, 'acceptRisk' => $risk];
    }
}
