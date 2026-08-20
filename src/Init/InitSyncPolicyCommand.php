<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\Cli\OptionTokens;

/**
 * Projects the small agent-loop authority policy into supported coding hosts.
 *
 * This command owns repository policy only. Host/user-scoped trust or Auto Mode
 * configuration stays outside the repository and is reported as an explicit
 * boundary rather than silently modified.
 */
final readonly class InitSyncPolicyCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $requestedAgent = OptionTokens::value($tokens, 'agent');
        if ($requestedAgent === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse(
                $requestedAgent,
                HostPolicyProjector::supportedAgents(),
                true,
            );
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $dryRun = OptionTokens::hasFlag($tokens, 'dry-run');
        $force = OptionTokens::hasFlag($tokens, 'force');
        $agents = $agent->isAll() ? HostPolicyProjector::supportedAgents() : [$agent->canonicalName()];
        $projector = new HostPolicyProjector($this->rootPath);

        foreach ($agents as $canonicalAgent) {
            try {
                $result = $projector->sync($canonicalAgent, $dryRun, $force);
            } catch (InvalidArgumentException $exception) {
                fwrite(\STDERR, '[FAIL] sync policy [' . $canonicalAgent . ']: ' . $exception->getMessage() . "\n");

                return 1;
            }

            $prefix = $dryRun && $result['changed'] ? '[DRY-RUN]' : '[OK]';
            echo $prefix . ' sync policy [' . $canonicalAgent . ']: ' . $result['detail'] . '; path=' . $result['path'] . "\n";
            echo $this->boundaryHint($canonicalAgent) . "\n";
        }

        return 0;
    }

    private function boundaryHint(string $agent): string
    {
        return match ($agent) {
            'claude' => '[IMPORTANT] sync policy [claude]: ' . HostPolicyProjector::claudeUserScopeAction(),
            'codex' => '[IMPORTANT] sync policy [codex]: project rules load only after Codex trusts this repository; review .codex/rules/agent-loop.rules before granting trust.',
            'opencode' => '[IMPORTANT] sync policy [opencode]: OpenCode --auto bypasses ask decisions, so authority-bearing remote mutations are projected as deny and must be performed outside that auto-approved path.',
            default => throw new InvalidArgumentException('Unsupported host policy boundary: ' . $agent),
        };
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent'];
        $flagOptions = ['dry-run', 'force'];
        $count = count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init sync-policy argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!is_string($normalized) || !in_array($normalized, array_merge($valueOptions, $flagOptions), true)) {
                return 'Unknown init sync-policy option: --' . (is_string($normalized) ? $normalized : '');
            }

            if (in_array($normalized, $valueOptions, true) && !str_contains($token, '=')) {
                $candidate = $tokens[$index + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init sync-policy option: --' . $normalized;
                }

                ++$index;
            }
        }

        return null;
    }
}
