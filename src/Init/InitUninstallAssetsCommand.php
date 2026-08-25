<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Cli\OptionTokens;

/**
 * CLI adapter over the typed uninstall boundary.
 *
 * This command owns argument handling and rendering only. The plan, the safety
 * rules and the mutation all live in {@see RepositorySetupService}, so the CLI
 * and a host UI cannot disagree about what "remove managed assets" means.
 *
 * Without `--yes` the command prints the plan and exits without touching
 * anything, which is also exactly what a UI renders before asking a human to
 * confirm.
 */
final readonly class InitUninstallAssetsCommand
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

        $requestedAgent = OptionTokens::value($tokens, 'agent');
        if ($requestedAgent === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        $withHooks = OptionTokens::hasFlag($tokens, 'with-hooks');
        $confirmed = OptionTokens::hasFlag($tokens, 'yes');
        $service = new RepositorySetupService($this->rootPath, $this->runtimeProbe);

        try {
            $plan = $service->planUninstall($requestedAgent, $withHooks);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, '[FAIL] uninstall assets: ' . $exception->getMessage() . "\n");

            return 1;
        }

        $this->printPlan($plan);

        if (!$confirmed) {
            echo '[DRY-RUN] uninstall assets: nothing was removed; rerun with --yes to apply this exact plan.' . "\n";

            return 0;
        }

        if (!$plan->mutates()) {
            echo '[OK] uninstall assets: nothing is removable, so nothing was removed.' . "\n";

            return 0;
        }

        try {
            $result = $service->uninstall($plan, $plan->expectedState->value);
        } catch (StaleRepositorySetupPlan $exception) {
            fwrite(\STDERR, '[FAIL] uninstall assets: ' . $exception->getMessage() . "\n");

            return 1;
        } catch (Throwable $exception) {
            fwrite(\STDERR, '[FAIL] uninstall assets: ' . $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($result->messages as $message) {
            echo '[OK] uninstall assets: ' . $message . "\n";
        }
        foreach ($result->blocked as $operation) {
            echo '[SKIP] uninstall assets: ' . $operation->entry . ' - ' . ($operation->reason ?? 'blocked') . "\n";
        }

        echo '[OK] uninstall assets: removed ' . count($result->applied) . ' managed entrie(s).' . "\n";

        return $result->succeeded ? 0 : 1;
    }

    private function printPlan(ManagedAssetChangePlan $plan): void
    {
        echo 'agent-loop init uninstall-assets' . "\n\n";
        echo 'Plan: ' . $plan->planId() . "\n";
        echo 'Expected state: ' . $plan->expectedState->value . "\n\n";

        foreach ($plan->operations as $operation) {
            echo '[REMOVE] ' . $operation->kind->value . ' ' . $operation->entry . ' (' . $operation->targetPath . ')' . "\n";
        }
        foreach ($plan->blocked as $operation) {
            echo '[BLOCKED] ' . $operation->kind->value . ' ' . $operation->entry . ' - ' . ($operation->reason ?? 'blocked') . "\n";
        }
        if ($plan->operations === [] && $plan->blocked === []) {
            echo '[INFO] no managed assets are projected for this host.' . "\n";
        }
        echo "\n";
    }

    /** @param list<string> $tokens */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent'];
        $flagOptions = ['with-hooks', 'yes'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === 'help' || $token === '--help' || $token === '-h') {
                continue;
            }
            if (!str_starts_with($token, '--')) {
                return 'Unknown init uninstall-assets argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (in_array($normalized, $flagOptions, true)) {
                continue;
            }
            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init uninstall-assets option: --' . $normalized;
            }
            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init uninstall-assets option: --' . $normalized;
                }
                ++$i;
            }
        }

        return null;
    }
}
