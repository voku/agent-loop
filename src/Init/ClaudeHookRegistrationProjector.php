<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Owns only agent-loop's Claude Code hook registrations inside project settings.
 *
 * Claude allows unrelated project hooks to coexist in the same top-level
 * `hooks` object. The receipt persists the exact subset agent-loop registered,
 * so later sync/uninstall operations can distinguish owned handlers from project
 * configuration without claiming the whole settings key.
 *
 * @phpstan-import-type JsonValue from ClaudeJsonObjectCodec
 * @phpstan-import-type JsonObject from ClaudeJsonObjectCodec
 */
final readonly class ClaudeHookRegistrationProjector
{
    public const string RECEIPT_ENTRY = 'hooks/.agent-loop-registration.json';

    public const string LEGACY_WHOLE_KEY_ENTRY = 'settings.json#hooks';

    private const int RECEIPT_VERSION = 1;

    private ClaudeHookRegistrationStore $store;

    public function __construct(private string $rootPath)
    {
        $this->store = new ClaudeHookRegistrationStore($rootPath);
    }

    /** @param JsonObject $desiredHooks */
    public function sync(
        array $desiredHooks,
        bool $dryRun = false,
        bool $force = false,
        bool $legacyWholeKeyOwned = false,
    ): bool {
        $settings = $this->readSettings();
        $hooks = $this->hooksObject($settings);
        $before = $hooks;

        $previousHooks = $this->readReceiptHooks();
        if ($previousHooks !== null) {
            foreach ($this->records($previousHooks) as $record) {
                $matches = $this->recordsForCommand($hooks, $record['command']);
                if ($matches === []) {
                    continue;
                }
                if (count($matches) !== 1 || !$this->sameRecord($matches[0], $record)) {
                    if (!$force) {
                        throw new InvalidArgumentException(
                            'Claude agent-loop hook registration changed outside agent-loop: ' . $record['command']
                            . ' (use --force only after reviewing the project hook change)',
                        );
                    }
                }
                $this->removeCommand($hooks, $record['command']);
            }
        }

        foreach ($this->records($desiredHooks) as $record) {
            $matches = $this->recordsForCommand($hooks, $record['command']);
            if ($matches !== []) {
                $exact = count($matches) === 1 && $this->sameRecord($matches[0], $record);
                $claimableLegacy = $previousHooks === null && $legacyWholeKeyOwned && $exact;
                $alreadyOwned = $previousHooks !== null && $exact;
                if (!$alreadyOwned && !$claimableLegacy) {
                    if (!$force) {
                        throw new InvalidArgumentException(
                            'Claude project hook already uses the agent-loop command identity without matching owned provenance: '
                            . $record['command'] . ' (use --force only after reviewing the project hook change)',
                        );
                    }
                    $this->removeCommand($hooks, $record['command']);
                }
            }
            $this->insertRecord($hooks, $record);
        }

        $changed = $this->normalize($before) !== $this->normalize($hooks)
            || $this->receiptContent($desiredHooks) !== $this->currentReceiptContent();

        if (!$dryRun && $changed) {
            $this->store->commit(
                $this->settingsContent($settings, $hooks),
                $this->receiptContent($desiredHooks),
            );
        }

        return $changed;
    }

    /**
     * Checks the live project settings against the persisted owned subset.
     *
     * @return array{status:'ready'|'missing'|'conflict',detail:non-empty-string}
     */
    public function inspect(): array
    {
        $owned = $this->readReceiptHooks();
        if ($owned === null) {
            return ['status' => 'missing', 'detail' => 'Claude agent-loop hook registration receipt is missing'];
        }

        try {
            $hooks = $this->hooksObject($this->readSettings());
            foreach ($this->records($owned) as $record) {
                $matches = $this->recordsForCommand($hooks, $record['command']);
                if ($matches === []) {
                    return [
                        'status' => 'missing',
                        'detail' => 'Claude agent-loop hook registration is missing: ' . $record['command'],
                    ];
                }
                if (count($matches) !== 1 || !$this->sameRecord($matches[0], $record)) {
                    return [
                        'status' => 'conflict',
                        'detail' => 'Claude agent-loop hook registration differs from its receipt: ' . $record['command'],
                    ];
                }
            }
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'conflict',
                'detail' => $exception->getMessage() === ''
                    ? 'Claude project hook registration could not be inspected'
                    : $exception->getMessage(),
            ];
        }

        return ['status' => 'ready', 'detail' => 'Claude agent-loop hook registrations are current'];
    }

    /**
     * Removes only the handlers recorded by the agent-loop receipt.
     *
     * The receipt itself remains for the generic managed-asset uninstaller to
     * remove after this live registration mutation succeeds.
     */
    public function removeOwnedRegistrations(bool $dryRun = false): bool
    {
        $owned = $this->readReceiptHooks();
        if ($owned === null) {
            return false;
        }

        $settings = $this->readSettings();
        $hooks = $this->hooksObject($settings);
        $changed = false;

        foreach ($this->records($owned) as $record) {
            $matches = $this->recordsForCommand($hooks, $record['command']);
            if ($matches === []) {
                continue;
            }
            if (count($matches) !== 1 || !$this->sameRecord($matches[0], $record)) {
                throw new InvalidArgumentException(
                    'Refusing to remove a changed Claude agent-loop hook registration: ' . $record['command'],
                );
            }
            $this->removeCommand($hooks, $record['command']);
            $changed = true;
        }

        if (!$dryRun && $changed) {
            $this->store->writeSettings($this->settingsContent($settings, $hooks));
        }

        return $changed;
    }

    public function receiptPath(): string
    {
        return $this->store->receiptPath();
    }

    /** @param JsonObject $hooks */
    private function receiptContent(array $hooks): string
    {
        try {
            return json_encode(
                ['version' => self::RECEIPT_VERSION, 'hooks' => $this->normalize($hooks)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode Claude hook registration receipt.', 0, $exception);
        }
    }

    private function currentReceiptContent(): ?string
    {
        return $this->store->readReceiptContent();
    }

    /** @return JsonObject|null */
    private function readReceiptHooks(): ?array
    {
        $path = $this->receiptPath();
        $content = $this->store->readReceiptContent();
        if ($content === null) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Claude hook registration receipt is not valid JSON: ' . $path, 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded) || ($decoded['version'] ?? null) !== self::RECEIPT_VERSION) {
            throw new InvalidArgumentException('Unsupported Claude hook registration receipt: ' . $path);
        }
        $hooks = $decoded['hooks'] ?? null;
        if (!is_array($hooks) || array_is_list($hooks)) {
            throw new InvalidArgumentException('Claude hook registration receipt must contain a hooks object: ' . $path);
        }

        /** @var JsonObject $hooks */
        return $hooks;
    }

    /** @return JsonObject */
    private function readSettings(): array
    {
        $path = $this->store->settingsPath();
        $content = $this->store->readSettingsContent();
        if ($content === null || trim($content) === '') {
            return [];
        }

        return ClaudeJsonObjectCodec::decode($content, $path);
    }

    /**
     * @param JsonObject $settings
     * @return JsonObject
     */
    private function hooksObject(array $settings): array
    {
        if (!array_key_exists('hooks', $settings)) {
            return [];
        }
        $hooks = $settings['hooks'];
        if ($hooks instanceof stdClass && get_object_vars($hooks) === []) {
            return [];
        }
        if (!is_array($hooks) || array_is_list($hooks)) {
            throw new InvalidArgumentException('Claude project settings hooks must be a JSON object.');
        }

        /** @var JsonObject $hooks */
        return $hooks;
    }

    /**
     * @param JsonObject $settings
     * @param JsonObject $hooks
     */
    private function settingsContent(array $settings, array $hooks): ?string
    {
        if ($hooks === []) {
            unset($settings['hooks']);
        } else {
            $settings['hooks'] = $hooks;
        }

        if ($settings === []) {
            return null;
        }

        try {
            return json_encode(
                $settings,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode Claude project settings.', 0, $exception);
        }
    }

    /**
     * @param JsonObject $hooks
     * @return array<string, array{command: non-empty-string, event: non-empty-string, group: JsonObject, handler: JsonObject}>
     */
    private function records(array $hooks): array
    {
        $records = [];
        foreach ($hooks as $event => $groups) {
            if (!is_string($event) || $event === '' || !is_array($groups) || !array_is_list($groups)) {
                throw new InvalidArgumentException('Claude hooks must map event names to hook-group lists.');
            }
            foreach ($groups as $group) {
                if (!is_array($group) || array_is_list($group)) {
                    throw new InvalidArgumentException('Claude hook group must be a JSON object.');
                }
                $handlers = $group['hooks'] ?? null;
                if (!is_array($handlers) || !array_is_list($handlers)) {
                    throw new InvalidArgumentException('Claude hook group must contain a hook list.');
                }
                $groupMeta = $group;
                unset($groupMeta['hooks']);
                foreach ($handlers as $handler) {
                    if (!is_array($handler) || array_is_list($handler)) {
                        throw new InvalidArgumentException('Claude hook handler must be a JSON object.');
                    }
                    $command = $handler['command'] ?? null;
                    if (($handler['type'] ?? null) !== 'command' || !is_string($command) || trim($command) === '') {
                        continue;
                    }
                    if (isset($records[$command])) {
                        throw new InvalidArgumentException('Claude hook command identity must be unique: ' . $command);
                    }
                    $records[$command] = [
                        'command' => $command,
                        'event' => $event,
                        'group' => $groupMeta,
                        'handler' => $handler,
                    ];
                }
            }
        }

        ksort($records, SORT_STRING);

        return $records;
    }

    /**
     * @param JsonObject $hooks
     * @return list<array{command: non-empty-string, event: non-empty-string, group: JsonObject, handler: JsonObject}>
     */
    private function recordsForCommand(array $hooks, string $command): array
    {
        $records = [];
        foreach ($hooks as $event => $groups) {
            if (!is_string($event) || !is_array($groups) || !array_is_list($groups)) {
                throw new InvalidArgumentException('Claude hooks must map event names to hook-group lists.');
            }
            foreach ($groups as $group) {
                if (!is_array($group) || array_is_list($group)) {
                    throw new InvalidArgumentException('Claude hook group must be a JSON object.');
                }
                $handlers = $group['hooks'] ?? null;
                if (!is_array($handlers) || !array_is_list($handlers)) {
                    throw new InvalidArgumentException('Claude hook group must contain a hook list.');
                }
                $groupMeta = $group;
                unset($groupMeta['hooks']);
                foreach ($handlers as $handler) {
                    if (!is_array($handler) || array_is_list($handler) || ($handler['command'] ?? null) !== $command) {
                        continue;
                    }
                    $records[] = [
                        'command' => $command,
                        'event' => $event,
                        'group' => $groupMeta,
                        'handler' => $handler,
                    ];
                }
            }
        }

        return $records;
    }

    /**
     * @param array{command: non-empty-string, event: non-empty-string, group: JsonObject, handler: JsonObject} $left
     * @param array{command: non-empty-string, event: non-empty-string, group: JsonObject, handler: JsonObject} $right
     */
    private function sameRecord(array $left, array $right): bool
    {
        return $left['event'] === $right['event']
            && $this->normalize($left['group']) === $this->normalize($right['group'])
            && $this->normalize($left['handler']) === $this->normalize($right['handler']);
    }

    /**
     * @param JsonObject $hooks
     * @param array{command: non-empty-string, event: non-empty-string, group: JsonObject, handler: JsonObject} $record
     */
    private function insertRecord(array &$hooks, array $record): void
    {
        $event = $record['event'];
        $groups = $hooks[$event] ?? [];
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new InvalidArgumentException('Claude hook event is not a hook-group list: ' . $event);
        }

        foreach ($groups as $index => $group) {
            if (!is_array($group) || array_is_list($group)) {
                throw new InvalidArgumentException('Claude hook group must be a JSON object.');
            }
            $groupMeta = $group;
            unset($groupMeta['hooks']);
            if ($this->normalize($groupMeta) !== $this->normalize($record['group'])) {
                continue;
            }

            $handlers = $group['hooks'] ?? null;
            if (!is_array($handlers) || !array_is_list($handlers)) {
                throw new InvalidArgumentException('Claude hook group must contain a hook list.');
            }
            foreach ($handlers as $handler) {
                if (is_array($handler) && $this->normalize($handler) === $this->normalize($record['handler'])) {
                    return;
                }
            }
            $handlers[] = $record['handler'];
            $group['hooks'] = $handlers;
            $groups[$index] = $group;
            $hooks[$event] = $groups;

            return;
        }

        $group = $record['group'];
        $group['hooks'] = [$record['handler']];
        $groups[] = $group;
        $hooks[$event] = $groups;
    }

    /** @param JsonObject $hooks */
    private function removeCommand(array &$hooks, string $command): void
    {
        foreach (array_keys($hooks) as $event) {
            $groups = $hooks[$event] ?? null;
            if (!is_array($groups) || !array_is_list($groups)) {
                throw new InvalidArgumentException('Claude hook event is not a hook-group list: ' . $event);
            }

            $keptGroups = [];
            foreach ($groups as $group) {
                if (!is_array($group) || array_is_list($group)) {
                    throw new InvalidArgumentException('Claude hook group must be a JSON object.');
                }
                $handlers = $group['hooks'] ?? null;
                if (!is_array($handlers) || !array_is_list($handlers)) {
                    throw new InvalidArgumentException('Claude hook group must contain a hook list.');
                }
                $handlers = array_values(array_filter(
                    $handlers,
                    static fn ($handler): bool => !is_array($handler) || ($handler['command'] ?? null) !== $command,
                ));
                if ($handlers === []) {
                    continue;
                }
                $group['hooks'] = $handlers;
                $keptGroups[] = $group;
            }

            if ($keptGroups === []) {
                unset($hooks[$event]);
            } else {
                $hooks[$event] = $keptGroups;
            }
        }
    }

    private function normalize(bool|float|int|string|null|stdClass|array $value): bool|float|int|string|null|stdClass|array
    {
        if ($value instanceof stdClass) {
            return $this->normalize(get_object_vars($value));
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
