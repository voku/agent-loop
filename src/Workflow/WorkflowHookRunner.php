<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use JsonException;
use Throwable;
use voku\AgentLoop\GitHooks\HookConfigTrustStore;
use voku\AgentLoop\GitHooks\HookProcessEnvironment;
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
    private HookConfigTrustStore $trustStore;

    public function __construct(private string $rootPath, ?HookConfigTrustStore $trustStore = null)
    {
        $this->trustStore = $trustStore ?? new HookConfigTrustStore();
    }

    /**
     * @param 'enter'|'finish'|'approve' $event
     * @param array<string, mixed> $context
     *
     * @return list<array{hook: string, command: string, exit_code: int, error: ?string}>
     */
    public function run(string $event, string $taskId, array $context = []): array
    {
        $configs = $this->loadConfig($event);
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
            array_merge(HookProcessEnvironment::inherited(), $env),
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
    private function loadConfig(string $event): array
    {
        $hooksFile = $this->rootPath . '/.agent-loop/workflow-hooks.json';

        $workflowHooks = $this->readWorkflowConfig($hooksFile, requireWorkflowHooksKey: false, event: $event);
        if ($workflowHooks !== null) {
            return $workflowHooks;
        }

        $gitHooksFile = (new ProjectLayout($this->rootPath))->gitHooksConfig();
        return $this->readWorkflowConfig($gitHooksFile, requireWorkflowHooksKey: true, event: $event) ?? [];
    }

    /**
     * @return array<string, list<mixed>>|null null means absent, malformed, or not a workflow-hook config
     */
    private function readWorkflowConfig(string $path, bool $requireWorkflowHooksKey, string $event): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        if ($requireWorkflowHooksKey && !is_array($decoded['workflow_hooks'] ?? null)) {
            return null;
        }

        $hooks = $requireWorkflowHooksKey ? $decoded['workflow_hooks'] : ($decoded['workflow_hooks'] ?? $decoded);
        if (!is_array($hooks)) {
            return null;
        }

        $eventHooks = $hooks[$event] ?? null;
        if (is_array($eventHooks) && $this->hasShellCommands([$event => $eventHooks])) {
            $this->trustStore->assertTrusted($this->rootPath, $path, $content);
        }

        return $hooks;
    }

    /** @param array<string, mixed> $hooks */
    private function hasShellCommands(array $hooks): bool
    {
        foreach ($hooks as $eventHooks) {
            if (!is_array($eventHooks)) {
                continue;
            }
            foreach ($eventHooks as $hook) {
                $command = is_array($hook) ? ($hook['command'] ?? null) : $hook;
                if (is_string($command) && trim($command) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
