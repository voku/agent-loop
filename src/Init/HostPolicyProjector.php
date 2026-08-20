<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Projects the small repository-level authority policy that agent-loop needs
 * into host-native configuration without taking ownership of unrelated config.
 */
final readonly class HostPolicyProjector
{
    /** @var non-empty-list<non-empty-string> */
    private const array HOSTS = ['codex', 'claude', 'opencode'];

    /**
     * Remote publication is authority-bearing. Claude Auto Mode can route ask
     * rules through its classifier instead of a human prompt, so shared project
     * policy must use deny for a boundary that remains hard in Auto Mode.
     *
     * @var non-empty-list<string>
     */
    private const array CLAUDE_DENY_RULES = [
        'Bash(git push *)',
        'Bash(gh pr create *)',
        'Bash(gh pr merge *)',
    ];

    /** @var array<non-empty-string, 'deny'> */
    private const array OPENCODE_BASH_RULES = [
        'git push *' => 'deny',
        'gh pr create *' => 'deny',
        'gh pr merge *' => 'deny',
    ];

    public function __construct(private string $rootPath)
    {
    }

    /** @return non-empty-list<non-empty-string> */
    public static function supportedAgents(): array
    {
        return self::HOSTS;
    }

    /**
     * @return array{
     *     status: 'ready'|'missing'|'conflict'|'manual',
     *     path: non-empty-string,
     *     detail: non-empty-string
     * }
     */
    public function inspect(string $agent): array
    {
        return match ($agent) {
            'codex' => $this->inspectCodex(),
            'claude' => $this->inspectClaude(),
            'opencode' => $this->inspectOpenCode(),
            default => throw new InvalidArgumentException('Host policy projection is not supported for agent: ' . $agent),
        };
    }

    /**
     * @return array{changed: bool, path: non-empty-string, detail: non-empty-string}
     */
    public function sync(string $agent, bool $dryRun = false, bool $force = false): array
    {
        return match ($agent) {
            'codex' => $this->syncCodex($dryRun, $force),
            'claude' => $this->syncClaude($dryRun, $force),
            'opencode' => $this->syncOpenCode($dryRun, $force),
            default => throw new InvalidArgumentException('Host policy projection is not supported for agent: ' . $agent),
        };
    }

    /**
     * Auto Mode classifier configuration is not read from shared project
     * settings. Report that host-owned fact without pretending repo projection
     * can configure it.
     *
     * @return non-empty-string
     */
    public static function claudeUserScopeAction(): string
    {
        return 'Claude Auto Mode classifier configuration is user/local/managed scoped, not shared-project scoped. The repository deny rules remain hard; review effective Auto Mode settings separately when autonomous execution is desired.';
    }

    /**
     * @return array{status: 'ready'|'missing'|'conflict'|'manual', path: non-empty-string, detail: non-empty-string}
     */
    private function inspectCodex(): array
    {
        $path = $this->codexPath();
        if (!is_file($path)) {
            return ['status' => 'missing', 'path' => $path, 'detail' => 'agent-loop Codex policy file is missing'];
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            return ['status' => 'conflict', 'path' => $path, 'detail' => 'agent-loop Codex policy file is unreadable'];
        }

        if ($this->normalizeText($content) !== $this->normalizeText($this->codexRules())) {
            return ['status' => 'conflict', 'path' => $path, 'detail' => 'agent-loop Codex policy file differs from the managed policy'];
        }

        return ['status' => 'ready', 'path' => $path, 'detail' => 'repository Codex policy is current; project trust remains host-owned'];
    }

    /**
     * @return array{status: 'ready'|'missing'|'conflict'|'manual', path: non-empty-string, detail: non-empty-string}
     */
    private function inspectClaude(): array
    {
        $path = $this->claudePath();
        if (!is_file($path)) {
            return ['status' => 'missing', 'path' => $path, 'detail' => 'Claude project settings are missing the agent-loop permission policy'];
        }

        try {
            $settings = $this->readJsonObject($path);
            $permissions = $this->claudePermissionLists($settings, $path);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'conflict',
                'path' => $path,
                'detail' => self::failureDetail($exception->getMessage(), 'Claude project settings could not be inspected'),
            ];
        }

        foreach (self::CLAUDE_DENY_RULES as $rule) {
            // A permissive entry is reported even when deny also lists the rule.
            // Claude resolves that contradiction in favour of deny, so this is a
            // policy-hygiene conflict rather than an open boundary, but reporting
            // it ready would leave a contradiction nothing can clean up.
            if (in_array($rule, $permissions['allow'], true) || in_array($rule, $permissions['ask'], true)) {
                return [
                    'status' => 'conflict',
                    'path' => $path,
                    'detail' => 'Claude project permission conflicts with the required deny boundary: ' . $rule,
                ];
            }
            if (in_array($rule, $permissions['deny'], true)) {
                continue;
            }

            return ['status' => 'missing', 'path' => $path, 'detail' => 'Claude project deny permission is missing: ' . $rule];
        }

        return ['status' => 'ready', 'path' => $path, 'detail' => 'Claude project deny policy is current; Auto Mode classifier configuration remains user/local/managed scoped'];
    }

    /**
     * @return array{status: 'ready'|'missing'|'conflict'|'manual', path: non-empty-string, detail: non-empty-string}
     */
    private function inspectOpenCode(): array
    {
        $path = $this->openCodePath();
        if (!is_file($path) && is_file($this->openCodeJsoncPath())) {
            return [
                'status' => 'manual',
                'path' => $this->openCodeJsoncPath(),
                'detail' => 'opencode.jsonc exists; agent-loop refuses to rewrite comment-bearing JSONC automatically',
            ];
        }
        if (!is_file($path)) {
            return ['status' => 'missing', 'path' => $path, 'detail' => 'OpenCode project permission policy is missing'];
        }

        try {
            $config = $this->readJsonObject($path);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'conflict',
                'path' => $path,
                'detail' => self::failureDetail($exception->getMessage(), 'OpenCode project configuration could not be inspected'),
            ];
        }

        $permission = self::jsonObjectOrNull($config['permission'] ?? null);
        if ($permission === null) {
            // A JSON array is not a repairable absence: sync refuses it, so
            // reporting missing would advertise a repair that cannot succeed.
            return array_key_exists('permission', $config)
                ? ['status' => 'conflict', 'path' => $path, 'detail' => 'OpenCode permission is not a JSON object; agent-loop merges granular rules only into an object']
                : ['status' => 'missing', 'path' => $path, 'detail' => 'OpenCode permission object is missing'];
        }

        $bash = self::jsonObjectOrNull($permission['bash'] ?? null);
        if ($bash === null) {
            return array_key_exists('bash', $permission)
                ? ['status' => 'conflict', 'path' => $path, 'detail' => 'OpenCode permission.bash is not a JSON object; agent-loop merges granular rules only into an object']
                : ['status' => 'missing', 'path' => $path, 'detail' => 'OpenCode permission.bash object is missing'];
        }
        foreach (self::OPENCODE_BASH_RULES as $pattern => $decision) {
            $current = $bash[$pattern] ?? null;
            if ($current === $decision) {
                continue;
            }
            if ($current !== null) {
                return ['status' => 'conflict', 'path' => $path, 'detail' => 'OpenCode permission conflicts for pattern: ' . $pattern];
            }

            return ['status' => 'missing', 'path' => $path, 'detail' => 'OpenCode permission is missing pattern: ' . $pattern];
        }

        return ['status' => 'ready', 'path' => $path, 'detail' => 'OpenCode deny policy is current and remains effective under --auto'];
    }

    /** @return array{changed: bool, path: non-empty-string, detail: non-empty-string} */
    private function syncCodex(bool $dryRun, bool $force): array
    {
        $path = $this->codexPath();
        $desired = $this->codexRules();
        if (is_file($path)) {
            $existing = file_get_contents($path);
            if (!is_string($existing)) {
                throw new InvalidArgumentException('Unable to read Codex policy file: ' . $path);
            }
            if ($this->normalizeText($existing) === $this->normalizeText($desired)) {
                return ['changed' => false, 'path' => $path, 'detail' => 'Codex policy is current'];
            }
            if (!$force) {
                throw new InvalidArgumentException('Codex policy file already exists with different content: ' . $path . ' (use --force only after reviewing the diff)');
            }
        }

        if (!$dryRun) {
            $this->writeFile($path, $desired);
        }

        return ['changed' => true, 'path' => $path, 'detail' => 'Codex policy ' . ($dryRun ? 'would be written' : 'written')];
    }

    /** @return array{changed: bool, path: non-empty-string, detail: non-empty-string} */
    private function syncClaude(bool $dryRun, bool $force): array
    {
        $path = $this->claudePath();
        $settings = is_file($path) ? $this->readJsonObject($path) : [];
        $permissions = $this->claudePermissionLists($settings, $path);

        $allow = $permissions['allow'];
        $ask = $permissions['ask'];
        $deny = $permissions['deny'];
        $changed = false;

        foreach (self::CLAUDE_DENY_RULES as $rule) {
            $conflicts = in_array($rule, $allow, true) || in_array($rule, $ask, true);
            if ($conflicts && !$force) {
                throw new InvalidArgumentException(
                    'Claude permission already owns ' . $rule . ' as allow/ask; use --force only after reviewing the change to deny',
                );
            }

            if ($conflicts) {
                $allow = array_values(array_filter($allow, static fn (string $value): bool => $value !== $rule));
                $ask = array_values(array_filter($ask, static fn (string $value): bool => $value !== $rule));
                $changed = true;
            }

            if (!in_array($rule, $deny, true)) {
                $deny[] = $rule;
                $changed = true;
            }
        }

        if (!$changed) {
            return ['changed' => false, 'path' => $path, 'detail' => 'Claude project deny policy is current'];
        }

        $rawPermissions = self::assertJsonObject(
            self::objectBoundary($settings, 'permissions'),
            'Claude settings permissions must be an object',
            $path,
        );
        $rawPermissions['allow'] = $allow;
        $rawPermissions['ask'] = $ask;
        $rawPermissions['deny'] = array_values(array_unique($deny));
        $settings['permissions'] = $rawPermissions;

        if (!$dryRun) {
            $this->writeJson($path, $settings);
        }

        return ['changed' => true, 'path' => $path, 'detail' => 'Claude project deny policy ' . ($dryRun ? 'would be merged' : 'merged')];
    }

    /** @return array{changed: bool, path: non-empty-string, detail: non-empty-string} */
    private function syncOpenCode(bool $dryRun, bool $force): array
    {
        $path = $this->openCodePath();
        if (!is_file($path) && is_file($this->openCodeJsoncPath())) {
            throw new InvalidArgumentException('opencode.jsonc exists; refusing to rewrite comment-bearing JSONC automatically: ' . $this->openCodeJsoncPath());
        }

        $config = is_file($path) ? $this->readJsonObject($path) : ['$schema' => 'https://opencode.ai/config.json'];
        $permission = self::assertJsonObject(
            self::objectBoundary($config, 'permission'),
            'OpenCode permission must be an object before agent-loop can merge granular rules',
            $path,
        );
        $bash = self::assertJsonObject(
            self::objectBoundary($permission, 'bash'),
            'OpenCode permission.bash must be an object before agent-loop can merge granular rules',
            $path,
        );

        $changed = false;
        foreach (self::OPENCODE_BASH_RULES as $pattern => $decision) {
            $current = $bash[$pattern] ?? null;
            if ($current === $decision) {
                continue;
            }
            if ($current !== null && !$force) {
                throw new InvalidArgumentException(
                    'OpenCode permission already owns pattern ' . $pattern
                    . ' with decision type/value ' . get_debug_type($current)
                    . '/' . (is_scalar($current) ? (string) $current : '<non-scalar>')
                    . '; use --force only after reviewing the policy change',
                );
            }
            $bash[$pattern] = $decision;
            $changed = true;
        }

        if (!$changed) {
            return ['changed' => false, 'path' => $path, 'detail' => 'OpenCode permission policy is current'];
        }

        $permission['bash'] = $bash;
        $config['permission'] = $permission;
        if (!$dryRun) {
            $this->writeJson($path, $config);
        }

        return ['changed' => true, 'path' => $path, 'detail' => 'OpenCode permission policy ' . ($dryRun ? 'would be merged' : 'merged')];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{allow: list<string>, ask: list<string>, deny: list<string>}
     */
    private function claudePermissionLists(array $settings, string $path): array
    {
        $permissions = self::assertJsonObject(
            self::objectBoundary($settings, 'permissions'),
            'Claude settings permissions must be an object',
            $path,
        );

        $allow = $permissions['allow'] ?? [];
        $ask = $permissions['ask'] ?? [];
        $deny = $permissions['deny'] ?? [];
        if (!is_array($allow) || !is_array($ask) || !is_array($deny)) {
            throw new InvalidArgumentException('Claude permissions.allow/ask/deny must be arrays: ' . $path);
        }

        return [
            'allow' => $this->stringList($allow, 'Claude permissions.allow', $path),
            'ask' => $this->stringList($ask, 'Claude permissions.ask', $path),
            'deny' => $this->stringList($deny, 'Claude permissions.deny', $path),
        ];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values, string $label, string $path): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException($label . ' must contain only non-empty strings: ' . $path);
            }
            $result[] = $value;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function readJsonObject(string $path): array
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new InvalidArgumentException('Unable to read JSON configuration: ' . $path);
        }

        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON configuration ' . $path . ': ' . $exception->getMessage(), previous: $exception);
        }

        return self::assertJsonObject(self::jsonObjectOrNull($decoded), 'JSON configuration must contain an object', $path);
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode JSON configuration ' . $path . ': ' . $exception->getMessage(), previous: $exception);
        }

        $this->writeFile($path, $json . "\n");
    }

    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create policy directory: ' . $directory);
        }
        if (file_put_contents($path, $content) === false) {
            throw new InvalidArgumentException('Unable to write host policy: ' . $path);
        }
    }

    /**
     * Host schemas treat a JSON object and a JSON array as different things, so
     * decoding keeps objects as stdClass instead of collapsing {} and [] onto
     * the same PHP value. Only the asserted level is converted, which also lets
     * untouched nested objects re-encode as objects rather than as arrays.
     *
     * @param array<string, mixed>|null $object
     *
     * @return array<string, mixed>
     */
    private static function assertJsonObject(?array $object, string $label, string $path): array
    {
        if ($object === null) {
            throw new InvalidArgumentException($label . ': ' . $path);
        }

        return $object;
    }

    /**
     * An absent boundary is a repairable absence, but a present one must
     * already be a JSON object. Treating an explicit null as absent would let
     * sync overwrite it while inspection reports the same value as a conflict.
     *
     * @param array<string, mixed> $parent
     *
     * @return array<string, mixed>|null
     */
    private static function objectBoundary(array $parent, string $key): ?array
    {
        if (!array_key_exists($key, $parent)) {
            return [];
        }

        return self::jsonObjectOrNull($parent[$key]);
    }

    /**
     * Single conversion boundary between a decoded JSON object and the
     * associative arrays the rest of this projector works with.
     *
     * @return array<string, mixed>|null
     */
    private static function jsonObjectOrNull(mixed $value): ?array
    {
        if (!$value instanceof stdClass) {
            return null;
        }

        return get_object_vars($value);
    }

    /**
     * An owner exception carries no guarantee of a non-empty message, so keep a
     * host-specific fallback rather than reporting an inspection failure with
     * an empty detail.
     *
     * @param non-empty-string $fallback
     *
     * @return non-empty-string
     */
    private static function failureDetail(string $message, string $fallback): string
    {
        return $message === '' ? $fallback : $message;
    }

    /** @return non-empty-string */
    private function codexPath(): string
    {
        return rtrim($this->rootPath, '/') . '/.codex/rules/agent-loop.rules';
    }

    /** @return non-empty-string */
    private function claudePath(): string
    {
        return rtrim($this->rootPath, '/') . '/.claude/settings.json';
    }

    /** @return non-empty-string */
    private function openCodePath(): string
    {
        return rtrim($this->rootPath, '/') . '/opencode.json';
    }

    /** @return non-empty-string */
    private function openCodeJsoncPath(): string
    {
        return rtrim($this->rootPath, '/') . '/opencode.jsonc';
    }

    private function normalizeText(string $content): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $content)) . "\n";
    }

    private function codexRules(): string
    {
        return <<<'RULES'
# Managed by agent-loop: remote authority-bearing mutations require an explicit boundary.
prefix_rule(
    pattern = ["git", "push"],
    decision = "prompt",
    justification = "Remote mutation requires explicit authority.",
)

prefix_rule(
    pattern = ["gh", "pr", "create"],
    decision = "prompt",
    justification = "Creating a pull request is an external mutation.",
)

prefix_rule(
    pattern = ["gh", "pr", "merge"],
    decision = "prompt",
    justification = "Merging a pull request is an external mutation.",
)
RULES;
    }
}
