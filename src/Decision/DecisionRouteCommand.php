<?php

declare(strict_types=1);

namespace voku\AgentLoop\Decision;

use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\ManagedSubagentSourceResolver;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\PackageResources;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowTaskId;

/**
 * Optional advisory front door for direct Codex CLI users.
 *
 * Does not call enter/finish, load private task context, or mutate lifecycle state.
 */
final readonly class DecisionRouteCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        if (in_array($args[0] ?? null, ['help', '--help', '-h'], true)) {
            echo "Usage: agent-loop decision route [--mode=off|rules|shadow|assist] [--task-id=ID --contract-revision=N] [--public-state=TEXT --data-classification=public] [--candidate=MANAGED-ID ...] [--endpoint=https://HOST/v1/systemone] [--model=MODEL] [--timeout-ms=1000] [--token-env=NAME] [--allow-loopback] --format=json\n";
            echo "Output is advisory and sanitized. Default off; no provider is auto-enabled.\n";

            return 0;
        }

        try {
            $options = self::options($args);
            if (($args[0] ?? null) !== 'route') {
                throw new InvalidArgumentException('Expected decision route.');
            }

            $mode = $options['mode'][0] ?? 'off';
            if (!in_array($mode, ['off', 'rules', 'shadow', 'assist'], true)) {
                throw new InvalidArgumentException('Decision mode must be off, rules, shadow, or assist.');
            }
            if (($options['format'][0] ?? 'json') !== 'json') {
                throw new InvalidArgumentException('Decision route supports --format=json only.');
            }

            $taskId = $options['task-id'][0] ?? null;
            $revision = $options['contract-revision'][0] ?? null;
            if ($taskId !== null) {
                $taskId = (new WorkflowTaskId($taskId))->value;
                if ($revision === null || !ctype_digit($revision) || (int) $revision < 1) {
                    throw new InvalidArgumentException('Task-bound advice requires --contract-revision=N.');
                }
                $contract = (new TaskContractStore($this->rootPath))->find($taskId);
                if (
                    $contract === null
                    || $contract->status !== TaskContract::APPROVED
                    || $contract->revision !== (int) $revision
                ) {
                    throw new InvalidArgumentException('Task Contract is missing, unapproved, or stale; refresh enter/context before routing.');
                }
            } elseif ($revision !== null) {
                throw new InvalidArgumentException('--contract-revision requires --task-id.');
            }

            $candidateDescriptions = $mode === 'off' ? [] : $this->managedCandidates($options['candidate'] ?? []);
            $candidateIds = array_keys($candidateDescriptions);
            $receipt = [
                'schema_version' => '1.0',
                'kind' => 'advisory_specialist_route',
                'task_id' => $taskId,
                'contract_revision' => $revision === null ? null : (int) $revision,
                'mode' => $mode,
                'provider' => $mode === 'shadow' || $mode === 'assist' ? 'laya' : null,
                'disposition' => DecisionDisposition::NotEnabled->value,
                'candidate_ids' => $candidateIds,
                'observed_candidate_id' => null,
                'routing_hint' => null,
                'answer_probability' => null,
                'provider_latency_ms' => null,
                'provider_error' => null,
                'request_sha256' => null,
                'selection_authority' => 'codex_host',
                'lifecycle_mutation' => false,
            ];

            if ($mode === 'off' || $mode === 'rules') {
                if ($mode === 'rules') {
                    $receipt['disposition'] = DecisionDisposition::Abstain->value;
                }
                self::printReceipt($receipt);

                return 0;
            }

            $state = $options['public-state'][0] ?? null;
            if (
                ($options['data-classification'][0] ?? null) !== 'public'
                || $state === null
                || trim($state) === ''
                || strlen($state) > 2000
            ) {
                throw new InvalidArgumentException('Remote advice requires explicit --public-state (1..2000 bytes) and --data-classification=public.');
            }
            $endpoint = $options['endpoint'][0] ?? null;
            if ($endpoint === null) {
                throw new InvalidArgumentException('Remote advice requires an explicit --endpoint.');
            }
            $timeoutRaw = $options['timeout-ms'][0] ?? '1000';
            if (!ctype_digit($timeoutRaw)) {
                throw new InvalidArgumentException('--timeout-ms must be a positive integer.');
            }
            $token = null;
            $tokenEnvironment = $options['token-env'][0] ?? null;
            if ($tokenEnvironment !== null) {
                if (preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $tokenEnvironment) !== 1) {
                    throw new InvalidArgumentException('Invalid --token-env variable name.');
                }
                $read = getenv($tokenEnvironment);
                if (!is_string($read) || $read === '') {
                    throw new InvalidArgumentException('Explicit Laya credential environment variable is missing.');
                }
                $token = $read;
            }

            $client = new LayaRoutingClient(
                $endpoint,
                (int) $timeoutRaw,
                isset($options['allow-loopback']),
                $token,
            );
            $model = $options['model'][0] ?? null;
            $fingerprintInput = [
                'state' => $state,
                'candidate_descriptions' => $candidateDescriptions,
                'model' => $model,
                'contract_revision' => $revision,
            ];
            $receipt['request_sha256'] = 'sha256:' . hash('sha256', json_encode($fingerprintInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($candidateDescriptions === []) {
                throw new InvalidArgumentException('No eligible routing candidates.');
            }
            $start = hrtime(true);
            try {
                $choice = $client->decide($state, $candidateDescriptions, $model);

                // A remote suggestion cannot outlive the approved Contract or
                // the exact canonical candidate definitions it was computed for.
                if ($taskId !== null) {
                    $currentContract = (new TaskContractStore($this->rootPath))->find($taskId);
                    if (
                        $currentContract === null
                        || $currentContract->status !== TaskContract::APPROVED
                        || $currentContract->revision !== (int) $revision
                    ) {
                        throw new \DomainException('Task Contract revision changed during routing.');
                    }
                }
                try {
                    $currentCandidates = $this->managedCandidates($options['candidate'] ?? []);
                } catch (InvalidArgumentException $exception) {
                    throw new \DomainException('Canonical candidate set changed during routing.', 0, $exception);
                }
                if ($currentCandidates !== $candidateDescriptions) {
                    throw new \DomainException('Canonical candidate set changed during routing.');
                }

                $receipt['disposition'] = ($choice->candidateId === null ? DecisionDisposition::Abstain : DecisionDisposition::Suggest)->value;
                $receipt['observed_candidate_id'] = $choice->candidateId;
                $receipt['answer_probability'] = $choice->answerProbability;
                if ($mode === 'assist' && $choice->candidateId !== null) {
                    $receipt['routing_hint'] = [
                        'candidate_id' => $choice->candidateId,
                        'answer_probability' => $choice->answerProbability,
                        'authority' => 'advisory',
                        'host_retains_final_selection' => true,
                    ];
                }
            } catch (\DomainException) {
                $receipt['disposition'] = DecisionDisposition::InvalidResponse->value;
                $receipt['provider_error'] = 'stale_decision_context';
            } catch (UnexpectedValueException) {
                $receipt['disposition'] = DecisionDisposition::InvalidResponse->value;
                $receipt['provider_error'] = 'response_schema';
            } catch (\RuntimeException) {
                $receipt['disposition'] = DecisionDisposition::ProviderUnavailable->value;
                $receipt['provider_error'] = 'transport_or_http';
            }
            $receipt['provider_latency_ms'] = round((hrtime(true) - $start) / 1_000_000, 3);
            self::printReceipt($receipt);

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] decision route: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $argv
     * @return array<string, non-empty-list<string>>
     */
    private static function options(array $argv): array
    {
        $allowed = [
            'mode', 'task-id', 'contract-revision', 'public-state', 'data-classification',
            'candidate', 'endpoint', 'model', 'timeout-ms', 'token-env', 'format', 'allow-loopback',
        ];
        $options = [];
        $tokens = array_slice($argv, 1);
        for ($i = 0, $n = count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                throw new InvalidArgumentException('Unexpected decision route argument.');
            }
            $pair = explode('=', substr($token, 2), 2);
            $name = $pair[0];
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException('Unknown decision route option: --' . $name);
            }
            if ($name === 'allow-loopback') {
                if (count($pair) > 1 || isset($options[$name])) {
                    throw new InvalidArgumentException('--allow-loopback is a unique flag.');
                }
                $options[$name] = ['true'];

                continue;
            }
            $value = $pair[1] ?? null;
            if ($value === null) {
                $value = $tokens[++$i] ?? null;
            }
            if (!is_string($value) || $value === '' || str_starts_with($value, '--')) {
                throw new InvalidArgumentException('Option --' . $name . ' requires a value.');
            }
            if ($name !== 'candidate' && isset($options[$name])) {
                throw new InvalidArgumentException('Duplicate decision route option: --' . $name);
            }
            $options[$name][] = $value;
        }

        return $options;
    }

    /**
     * @param list<string> $requested
     * @return non-empty-array<string, non-empty-string>
     */
    private function managedCandidates(array $requested): array
    {
        $layout = new ProjectLayout($this->rootPath);
        $configPath = $layout->configPath();
        $config = (new InitConfigLoader($this->rootPath))->load(
            is_file($configPath) ? $layout->display($configPath) : null,
        );
        $paths = AgentAssetSourcePaths::fromConfig($this->rootPath, $config);
        $sources = (new ManagedSubagentSourceResolver($this->rootPath))->resolve($paths);
        if ($sources === []) {
            throw new InvalidArgumentException('No managed subagents available.');
        }
        if (count($requested) !== count(array_unique($requested))) {
            throw new InvalidArgumentException('Duplicate candidate IDs are not allowed.');
        }
        // Only package-owned public definitions may cross the remote data boundary.
        // Project-managed and extra-root subagents can be private, even when their
        // identifiers are otherwise valid and usable by the local Codex host.
        $packageRoot = realpath(PackageResources::subagentsRoot());
        if ($packageRoot === false) {
            throw new InvalidArgumentException('Public package subagent source root is unavailable.');
        }
        $prefix = rtrim($packageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $descriptions = [];
        foreach ($sources as $id => $source) {
            if ($requested !== [] && !in_array($id, $requested, true)) {
                continue;
            }
            $sourcePath = realpath($source->path);
            if (
                $source->assetSource->owner !== 'voku/agent-loop'
                || $sourcePath === false
                || !str_starts_with($sourcePath, $prefix)
            ) {
                // Private/project-managed definitions are never serialized for Laya.
                continue;
            }
            $description = $source->definition()->description();
            if ($description === '') {
                throw new InvalidArgumentException('Managed subagent description is empty.');
            }
            $descriptions[$id] = $description;
        }
        if ($requested !== [] && count($descriptions) !== count($requested)) {
            throw new InvalidArgumentException('Requested candidate is not a canonical managed subagent.');
        }
        if ($descriptions === []) {
            throw new InvalidArgumentException('No eligible routing candidates.');
        }
        ksort($descriptions, SORT_STRING);

        return $descriptions;
    }

    /** @param array<string, bool|float|int|string|array<int|string, bool|float|int|string|null>|null> $receipt */
    private static function printReceipt(array $receipt): void
    {
        echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
