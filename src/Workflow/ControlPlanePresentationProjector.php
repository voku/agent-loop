<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use JsonException;
use Throwable;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\Process\CommandProcessResult;
use voku\AgentLoop\Process\CommandProcessRunner;
use voku\AgentLoop\ProjectLayout;

final readonly class ControlPlanePresentationProjector
{
    /** @var Closure(non-empty-list<string>): CommandProcessResult */
    private Closure $runner;

    /** @param null|callable(non-empty-list<string>): CommandProcessResult $runner */
    public function __construct(private string $rootPath, ?callable $runner = null)
    {
        $this->runner = $runner === null
            ? fn (array $command): CommandProcessResult => (new CommandProcessRunner())->run(
                $command,
                $this->rootPath,
                3,
            )
            : Closure::fromCallable($runner);
    }

    /**
     * @return array{
     *     schema_version: '1.0',
     *     kind: 'control_plane',
     *     status: 'ready'|'not_installed'|'unreachable'|'wrong_service'|'wrong_project'|'invalid_response'|'probe_failed',
     *     required: false,
     *     url: string|null,
     *     detail: string|null
     * }|null
     */
    public function project(string $taskId): ?array
    {
        $layout = new ProjectLayout($this->rootPath);
        $config = (new InitConfigLoader($this->rootPath))->load($layout->configPath());
        $controlPlane = $config['interaction']['control_plane'];
        if (!$controlPlane['enabled']) {
            return null;
        }

        $binary = rtrim($this->rootPath, '/\\') . '/vendor/bin/agent-ui';
        if (!is_file($binary)) {
            return $this->result('not_installed', null, 'vendor/bin/agent-ui is not installed.');
        }

        $command = [
            $binary,
            'status',
            '--root=' . $this->rootPath,
            '--host=' . $controlPlane['host'],
            '--port=' . $controlPlane['port'],
            '--format=json',
        ];

        try {
            $process = ($this->runner)($command);
        } catch (Throwable $exception) {
            return $this->result('probe_failed', null, $this->boundedDetail($exception->getMessage()));
        }

        if ($process->timedOut) {
            return $this->result('probe_failed', null, 'agent-ui status probe timed out.');
        }

        try {
            $payload = json_decode($process->stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->result('probe_failed', null, 'agent-ui status did not return valid JSON.');
        }
        if (!is_array($payload)) {
            return $this->result('probe_failed', null, 'agent-ui status did not return a JSON object.');
        }

        $status = $payload['status'] ?? null;
        if (!is_string($status) || !in_array($status, [
            'ready',
            'unreachable',
            'wrong_service',
            'wrong_project',
            'invalid_response',
        ], true)) {
            return $this->result('probe_failed', null, 'agent-ui status returned an unsupported state.');
        }

        $detail = is_string($payload['detail'] ?? null)
            ? $this->boundedDetail($payload['detail'])
            : null;

        if ($status !== 'ready') {
            return $this->result($status, null, $detail);
        }
        if ($process->exitCode !== 0) {
            return $this->result('probe_failed', null, 'agent-ui reported ready with a non-zero exit code.');
        }

        return $this->result(
            'ready',
            $this->taskUrl($controlPlane['host'], $controlPlane['port'], $taskId),
            null,
        );
    }

    /**
     * @param 'ready'|'not_installed'|'unreachable'|'wrong_service'|'wrong_project'|'invalid_response'|'probe_failed' $status
     * @return array{
     *     schema_version: '1.0',
     *     kind: 'control_plane',
     *     status: string,
     *     required: false,
     *     url: string|null,
     *     detail: string|null
     * }
     */
    private function result(string $status, ?string $url, ?string $detail): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'control_plane',
            'status' => $status,
            'required' => false,
            'url' => $url,
            'detail' => $detail,
        ];
    }

    private function taskUrl(string $host, int $port, string $taskId): string
    {
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return sprintf('http://%s:%d/task/%s', $authority, $port, rawurlencode($taskId));
    }

    private function boundedDetail(string $detail): string
    {
        return substr($detail, 0, 300);
    }
}
