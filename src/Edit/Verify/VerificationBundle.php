<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

use JsonException;
use RuntimeException;

/**
 * The five artifacts a verification reads, loaded and cross-checked before anything is graded.
 *
 * Grading a bundle whose parts do not belong together is worse than refusing to grade it: the
 * numbers would look like evidence. So every binding is checked first - the key against the plan it
 * was cut from, the answer sheet against the same plan, and the task and target across all three -
 * and any mismatch is a hard stop rather than a failed gate.
 */
final readonly class VerificationBundle
{
    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $key
     * @param array<string, mixed> $result
     * @param array<string, mixed> $execution
     */
    private function __construct(
        public string $directory,
        public string $recallDirectory,
        public string $taskId,
        public string $target,
        public string $planSha256,
        public array $plan,
        public array $key,
        public array $result,
        public array $execution,
    ) {
    }

    public static function load(string $bundleDirectory): self
    {
        $directory = realpath($bundleDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('Edit bundle not found: ' . $bundleDirectory);
        }

        $execution = self::json($directory . '/execution.json');
        $result = self::json($directory . '/agent-result.json');

        $recallDirectory = $execution['artifacts']['recall'] ?? null;
        if (!is_string($recallDirectory) || $recallDirectory === '') {
            throw new RuntimeException('execution.json does not record a recall directory: ' . $directory);
        }

        $planFile = rtrim($recallDirectory, '/') . '/verification-plan.json';
        $keyFile = rtrim($recallDirectory, '/') . '/verification-key.json';
        if (!is_file($planFile) || !is_file($keyFile)) {
            throw new RuntimeException(
                'This edit was compiled without a verification plan, so there is nothing to verify: ' . $recallDirectory,
            );
        }

        $planRaw = (string) file_get_contents($planFile);
        $planSha256 = 'sha256:' . hash('sha256', $planRaw);
        $plan = self::decode($planRaw, $planFile);
        $key = self::json($keyFile);

        self::assertSame($planSha256, $key['plan_sha256'] ?? null, 'verification-key.json was cut from a different plan');
        self::assertSame($planSha256, $result['verification_plan_sha256'] ?? null, 'agent-result.json answers a different plan');

        $taskId = self::string($plan['task_id'] ?? null, 'verification-plan.json task_id');
        $target = self::string($plan['target'] ?? null, 'verification-plan.json target');
        self::assertSame($taskId, $result['task_id'] ?? null, 'agent-result.json belongs to a different task');
        self::assertSame($target, $result['target'] ?? null, 'agent-result.json answers a different target');
        self::assertSame($taskId, $execution['task_id'] ?? null, 'execution.json belongs to a different task');
        self::assertSame($target, $key['target'] ?? null, 'verification-key.json answers a different target');

        return new self(
            directory: $directory,
            recallDirectory: rtrim($recallDirectory, '/'),
            taskId: $taskId,
            target: $target,
            planSha256: $planSha256,
            plan: $plan,
            key: $key,
            result: $result,
            execution: $execution,
        );
    }

    /** @return list<array<string, mixed>> */
    public function knowledgeProbes(): array
    {
        return self::objectList($this->plan['knowledge_probes'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function checklist(): array
    {
        return self::objectList($this->plan['checklist'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function objectiveGates(): array
    {
        return self::objectList($this->plan['objective_gates'] ?? null);
    }

    /** @return array<string, mixed> */
    public function keyProbes(): array
    {
        $probes = $this->key['probes'] ?? null;

        return is_array($probes) ? $probes : [];
    }

    /** @return list<string> */
    public function changedFiles(): array
    {
        $files = [];
        foreach (is_array($this->result['changed_files'] ?? null) ? $this->result['changed_files'] : [] as $file) {
            if (is_string($file) && $file !== '') {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function changedFilesObserved(): bool
    {
        return ($this->result['changed_files_source'] ?? null) === 'git_status_diff';
    }

    public function preEditMapDigest(): ?string
    {
        $digest = $this->plan['map_digest'] ?? null;

        return is_string($digest) ? $digest : null;
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Required verification input is missing: ' . $file);
        }

        return self::decode((string) file_get_contents($file), $file);
    }

    /** @return array<string, mixed> */
    private static function decode(string $raw, string $file): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed verification input ' . $file . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Verification input must decode to an object: ' . $file);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private static function objectList(mixed $entries): array
    {
        $list = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $list[] = $entry;
            }
        }

        return $list;
    }

    private static function assertSame(string $expected, mixed $actual, string $message): void
    {
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException(
                $message . ' (expected ' . $expected . ', got ' . (is_string($actual) ? $actual : gettype($actual)) . ').',
            );
        }
    }

    private static function string(mixed $value, string $what): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Missing ' . $what . '.');
        }

        return $value;
    }
}
