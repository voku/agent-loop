<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentRecallCompiler\Review\ReviewReportPaths;
use voku\AgentRecallCompiler\Review\ReviewReportReader;

/**
 * Writes a disposable human-facing review projection without changing lifecycle authority.
 */
final readonly class WorkflowHumanReviewCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            if (count($args) !== 1) {
                throw new RuntimeException('workflow review accepts exactly one task id and no options.');
            }

            $html = $this->render($taskId->value);

            $path = $this->path($taskId->value);
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create human review output directory: ' . $directory);
            }
            if (file_put_contents($path, $html) === false) {
                throw new RuntimeException('Unable to write human review HTML: ' . $path);
            }

            echo '[OK] workflow review: wrote non-authoritative human review projection to '
                . RecallOutputRoot::relativeTo($this->rootPath, $path) . "\n";
            echo '[OK] workflow review: authority remains Contract revision + implementation snapshot + review SHA-256.' . "\n";

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow review: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /** Render a disposable human view; the returned HTML is not decision authority. */
    public function render(string $taskId): string
    {
        $task = new WorkflowTaskId($taskId);
        $contract = (new TaskContractStore($this->rootPath))->find($task->value);
        if ($contract === null) {
            throw new RuntimeException('Cannot render human review without a durable Contract.');
        }

        $diff = (new HumanReviewDiffCollector($this->rootPath))->collect($contract);
        $report = (new WorkflowReportCommand($this->rootPath))->buildReport($task->value, $diff->changedFiles);
        $review = self::reviewArray($report);
        $findings = $this->findings($task->value, $review);
        $html = HumanReviewHtmlRenderer::fromPackageResources()->render($report, $findings, $diff);
        $this->assertReviewIdentityUnchanged($task->value, $review);

        return $html;
    }

    /**
     * The disposable HTML workbench is Loop's own artifact, but it sits beside
     * the review report it renders, and that directory is Recall's layout to
     * name. Only the filename below it belongs to Loop.
     */
    public function path(string $taskId): string
    {
        $outputDirectory = (new ProjectLayout($this->rootPath))->recallTaskDirectory($taskId);

        return (new ReviewReportPaths($this->rootPath))->reviewsDirectory($outputDirectory)
            . '/' . $taskId . '.human.html';
    }

    /**
     * @param array<string, mixed> $review
     * @return list<array{id:string,severity:string,message:string,evidence:list<string>}>
     */
    private function findings(string $taskId, array $review): array
    {
        if (($review['exists'] ?? false) !== true || ($review['invalid'] ?? false) === true) {
            return [];
        }

        $outputDirectory = (new ProjectLayout($this->rootPath))->recallTaskDirectory($taskId);
        $artifact = (new ReviewReportReader($this->rootPath))->read($taskId, $outputDirectory);
        if ($artifact === null) {
            throw new RuntimeException('Review summary exists but typed review artifact is missing.');
        }
        $expectedSha = $review['sha256'] ?? null;
        if (!is_string($expectedSha) || !hash_equals($expectedSha, $artifact->sha256)) {
            throw new RuntimeException('Review report changed while preparing the human review projection.');
        }

        return array_map(
            static fn ($finding): array => $finding->toArray(),
            $artifact->report->findings,
        );
    }

    /** @param array<string, mixed> $before */
    private function assertReviewIdentityUnchanged(string $taskId, array $before): void
    {
        $after = (new WorkflowReviewReportReader($this->rootPath))->read($taskId);
        foreach (['exists', 'status', 'report_status', 'invalid', 'contract_revision', 'implementation_snapshot', 'sha256', 'acknowledged_by'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                throw new RuntimeException('Review evidence changed while rendering; discard this projection and render again.');
            }
        }
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private static function reviewArray(array $report): array
    {
        $review = $report['review'] ?? null;

        return is_array($review) ? $review : [];
    }
}
