<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\ProjectLayout;

/**
 * Runs repository-defined workflow lifecycle hooks.
 *
 * Hooks can be configured in `.agent-loop/workflow-hooks.json` or within `.agent-loop/githooks.json`
 * under the `workflow_hooks` key. Supported events include:
 *   - `enter`: executed when a governed task is entered
 *   - `finish`: executed when a governed task completes and closes out
 *   - `approve`: executed when a task contract is approved
 */
final readonly class WorkflowHookRunner
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param 'enter'|'finish'|'approve' $event
     * @param array<string, mixed> $context
     *
     * @return list<array{hook: string, command: string, exit_code: int, error: ?string}>
     */
    public function run(string $event, string $taskId, array $context = []): array
    {
        $configs = $this->loadConfig();
        $hooks = $configs[$event] ?? [];
        if ($hooks === []) {
            return [];
        }

        $results = [];
        $env = [
            'AGENT_LOOP_EVENT' => $event,
            'AGENT_LOOP_TASK_ID' => $taskId,
            'AGENT_LOOP_PROJECT_ROOT' => $this->rootPath,
        ];

        foreach ($hooks as $hook) {
            $name = is_array($hook) ? ($hook['name'] ?? 'anonymous') : 'hook';
            $command = is_array($hook) ? ($hook['command'] ?? null) : $hook;
            if (!is_string($command) || trim($command) === '') {
                continue;
            }

            $failClosed = is_array($hook) && ($hook['fail_closed'] ?? false) === true;

            try {
                $exitCode = $this->executeDeclaredHookShell($command, $env);
                $results[] = [
                    'hook' => (string) $name,
                    'command' => $command,
                    'exit_code' => $exitCode,
                    'error' => null,
                ];

                if ($exitCode !== 0 && $failClosed) {
                    throw new \RuntimeException(sprintf(
                        'Workflow hook "%s" (%s) failed with exit code %d.',
                        $name,
                        $command,
                        $exitCode,
                    ));
                }
            } catch (Throwable $e) {
                $results[] = [
                    'hook' => (string) $name,
                    'command' => $command,
                    'exit_code' => -1,
                    'error' => $e->getMessage(),
                ];

                if ($failClosed) {
                    throw $e;
                }
            }
        }

        return $results;
    }

    /**
     * @param array<string, string> $env
     */
    private function executeDeclaredHookShell(string $command, array $env): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->rootPath,
            array_merge($_ENV, getenv(), $env),
        );

        if (!is_resource($process)) {
            return -1;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function loadConfig(): array
    {
        $layout = new ProjectLayout($this->rootPath);
        $hooksFile = $this->rootPath . '/.agent-loop/workflow-hooks.json';

        if (is_file($hooksFile)) {
            try {
                $content = file_get_contents($hooksFile);
                if (is_string($content)) {
                    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $decoded['workflow_hooks'] ?? $decoded;
                    }
                }
            } catch (Throwable) {
                // Ignore malformed hook file
            }
        }

        $gitHooksFile = $layout->gitHooksConfig();
        if (is_file($gitHooksFile)) {
            try {
                $content = file_get_contents($gitHooksFile);
                if (is_string($content)) {
                    $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded) && isset($decoded['workflow_hooks']) && is_array($decoded['workflow_hooks'])) {
                        return $decoded['workflow_hooks'];
                    }
                }
            } catch (Throwable) {
                // Ignore malformed hook file
            }
        }

        return [];
    }
}
