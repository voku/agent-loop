<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use voku\AgentLoop\Cli\OptionTokens;

/**
 * CLI adapter over the typed repository setup projection.
 *
 * Host/user-owned boundaries such as Claude Auto Mode and Codex project trust
 * are reported separately and never masquerade as repository mutations.
 */
final readonly class InitHostStatusCommand
{
    public function __construct(
        private string $rootPath,
        private ?HostRuntimeProbe $runtimeProbe = null,
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $format = OptionTokens::value($tokens, 'format') ?? 'text';
        $requestedAgent = OptionTokens::value($tokens, 'agent');
        try {
            $status = (new RepositorySetupService($this->rootPath, $this->runtimeProbe))->overview($requestedAgent);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            fwrite(\STDERR, '[FAIL] init host-status: ' . $exception->getMessage() . "\n");

            return 1;
        }

        if ($format === 'json') {
            try {
                echo json_encode($status->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            } catch (JsonException $exception) {
                fwrite(\STDERR, 'Unable to encode host status: ' . $exception->getMessage() . "\n");

                return 1;
            }

            return 0;
        }

        $this->renderText($status);

        return 0;
    }

    private function renderText(RepositorySetupProjection $status): void
    {
        echo "agent-loop init host-status\n\n";
        echo 'Host: ' . ($status->host ?? 'unresolved') . ' (' . $status->selection . ")\n";
        if ($status->runtime !== null) {
            echo 'Runtime: ' . $status->runtime->status;
            if ($status->runtime->path !== null) {
                echo ' (' . $status->runtime->path . ')';
            }
            echo "\n";
        }
        if ($status->integration !== null) {
            echo 'Integration: instructions=' . $status->integration->instructions
                . ', skills=' . $status->integration->skills
                . ', subagents=' . $status->integration->subagents
                . ', policy=' . $status->integration->policy
                . ', git_integration=' . $status->integration->gitIntegration . "\n";
        }
        if ($status->runtimeBoundary !== null) {
            echo 'Runtime boundary: ' . $status->runtimeBoundary . "\n";
        }
        echo 'next_action_kind=' . $status->nextActionKind . "\n";
        echo 'next_action=' . ($status->nextAction ?? 'none') . "\n";
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent', 'format'];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init host-status argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!is_string($normalized) || !in_array($normalized, $valueOptions, true)) {
                return 'Unknown init host-status option: --' . (is_string($normalized) ? $normalized : '');
            }

            $value = str_contains($token, '=')
                ? substr($token, strpos($token, '=') + 1)
                : ($tokens[$index + 1] ?? null);
            if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                return 'Missing value for init host-status option: --' . $normalized;
            }
            if (!str_contains($token, '=')) {
                ++$index;
            }

            if ($normalized === 'format' && !in_array($value, ['text', 'json'], true)) {
                return 'Unknown init host-status format: ' . $value;
            }
        }

        return null;
    }
}
