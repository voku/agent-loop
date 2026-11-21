<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\ProjectLayout;

/**
 * Language for human-facing decision text. A host session may override the
 * project's prompting.language for itself only; the project setting, recorded
 * decisions and machine-readable tokens stay unchanged. Presentation only.
 */
final readonly class WorkflowPresentationLanguage
{
    public const string SOURCE_HOST_SESSION = 'host_session';
    public const string SOURCE_PROJECT = 'project';

    private function __construct(public string $language, public string $source)
    {
    }

    public static function resolve(string $rootPath): self
    {
        $directory = getenv('AGENT_LOOP_INTERACTION_DIR');
        $session = getenv('AGENT_LOOP_HOST_SESSION_ID');
        if (is_string($directory) && $directory !== '' && is_string($session) && $session !== '') {
            try {
                $language = (new InteractionPreferenceStore($directory))->find($session)?->language;
                if ($language !== null) {
                    return new self($language, self::SOURCE_HOST_SESSION);
                }
            } catch (Throwable) {
                // An unreadable host preference cannot change presentation; the project setting applies.
            }
        }

        $config = (new InitConfigLoader($rootPath))->load((new ProjectLayout($rootPath))->configPath());

        return new self($config['prompting']['language'], self::SOURCE_PROJECT);
    }

    /** Same tag grammar as init config's prompting.language, lower-cased. */
    public static function normalize(string $tag): string
    {
        if (strlen($tag) > 35 || preg_match('/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\z/', $tag) !== 1) {
            throw new InvalidArgumentException('Language must be a BCP 47 language tag such as de-DE or en.');
        }

        return strtolower($tag);
    }

    public function isGerman(): bool
    {
        return $this->language === 'de' || str_starts_with($this->language, 'de-');
    }

    /** Decision text exists in English and German only; this is the language actually rendered. */
    public function rendered(): string
    {
        return $this->isGerman() ? 'de' : 'en';
    }
}
