<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use Throwable;
use voku\AgentLoop\AgentGuidance\HostSessionBinding;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;

/** Manages behavioral preferences for an explicitly identified host session. */
final readonly class WorkflowInteractionCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $directory = HostSessionBinding::preferenceDirectory()
                ?? throw new RuntimeException('Set AGENT_LOOP_INTERACTION_DIR to a host-owned preference directory.');
            $language = null;
            $languageGiven = false;
            $defaultLanguage = null;
            $defaultLanguageGiven = false;
            $positional = [];
            foreach ($args as $arg) {
                if (str_starts_with($arg, '--default-language')) {
                    if ($defaultLanguageGiven || !str_starts_with($arg, '--default-language=')) {
                        throw new RuntimeException('Use --default-language=<BCP47> or --default-language=inherit, at most once.');
                    }
                    $defaultLanguageGiven = true;
                    $value = substr($arg, strlen('--default-language='));
                    $defaultLanguage = $value === 'inherit' ? null : WorkflowPresentationLanguage::normalize($value);
                    continue;
                }
                if (str_starts_with($arg, '--language')) {
                    if ($languageGiven || !str_starts_with($arg, '--language=')) {
                        throw new RuntimeException('Use --language=<BCP47> or --language=inherit, at most once.');
                    }
                    $languageGiven = true;
                    $value = substr($arg, strlen('--language='));
                    $language = $value === 'inherit' ? null : WorkflowPresentationLanguage::normalize($value);
                    continue;
                }
                $positional[] = $arg;
            }
            $args = $positional;
            if ($args === [] && $defaultLanguageGiven && !$languageGiven) {
                // The default language belongs to the user, not to one session.
                (new InteractionPreferenceStore($directory))->setDefaultLanguage($defaultLanguage);
                echo self::defaultLanguageMessage($defaultLanguage);

                return 0;
            }
            $session = $args[0] ?? '';
            $mode = $args[1] ?? '';
            $task = $args[2] ?? null;
            if (count($args) > 3 || trim($session) === '' || ($task !== null && trim($task) === '') || ($mode === '' && !$languageGiven && !$defaultLanguageGiven)) {
                throw new RuntimeException('Usage: workflow interaction <host-session-id> [<collaborative|autonomous> [task-id]] [--language=<BCP47>|--language=inherit] [--default-language=<BCP47>|--default-language=inherit], or workflow interaction --default-language=<BCP47>|inherit.');
            }
            if (!HostSessionBinding::isValidSessionId($session)) {
                throw new RuntimeException('Host session identity must match [A-Za-z0-9._-]+ and be at most 128 bytes.');
            }
            if ($mode === '') {
                $store = new InteractionPreferenceStore($directory);
                if ($languageGiven) {
                    $store->bindSession($session);
                    $store->setLanguage($session, $language);
                    echo '[OK] session language ' . ($language ?? 'cleared (the default language or project prompting.language applies)') . "; presentation only, it grants no authority.\n";
                }
                if ($defaultLanguageGiven) {
                    $store->setDefaultLanguage($defaultLanguage);
                    echo self::defaultLanguageMessage($defaultLanguage);
                }

                return 0;
            }
            if ($task !== null && $mode !== '--reuse') {
                $task = (new WorkflowTaskId($task))->value;
            }
            if ($mode === 'inherit' && $task === null) {
                throw new RuntimeException('A task identity is required to clear an override.');
            }
            $store = new InteractionPreferenceStore($directory);
            if ($defaultLanguageGiven && $task !== null && $mode !== '--reuse' && !$store->hasSharedDefault()) {
                // Refuse before the task override is written, so a failure leaves nothing half-saved.
                throw new RuntimeException('Set a shared default mode before setting a default language.');
            }
            if ($mode === '--reuse') {
                if ($task === null) {
                    throw new RuntimeException('Provide the previous host session identity to reuse.');
                }
                $previous = $store->find($task);
                if ($previous === null) {
                    throw new RuntimeException('The previous host session preference is unavailable.');
                }
                $mode = $previous->defaultMode;
                $task = null;
            }
            if ($task === null) {
                $store->setDefault($session, $mode);
            } else {
                $store->update($session, $mode, $task);
            }
            if ($languageGiven) {
                $store->setLanguage($session, $language);
            }
            if ($defaultLanguageGiven) {
                $store->setDefaultLanguage($defaultLanguage);
            }
            echo "[OK] interaction preference saved; it grants no authority.\n";
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow interaction: ' . $exception->getMessage() . "\n");
            return 1;
        }
    }

    private static function defaultLanguageMessage(?string $language): string
    {
        return '[OK] default language ' . ($language ?? 'cleared (project prompting.language applies)')
            . " for every host session of this user; a session language still overrides it. Presentation only, it grants no authority.\n";
    }
}
