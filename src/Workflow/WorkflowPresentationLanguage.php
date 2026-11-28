<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\AgentGuidance\HostSessionBinding;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\ProjectLayout;

/**
 * Language for human-facing decision text: the host session's language, else the
 * user's default language, else the project's prompting.language. The project
 * setting, recorded decisions and machine-readable tokens stay unchanged.
 * Presentation only.
 */
final readonly class WorkflowPresentationLanguage
{
    public const string SOURCE_HOST_SESSION = 'host_session';
    public const string SOURCE_USER_DEFAULT = 'user_default';
    public const string SOURCE_PROJECT = 'project';

    private function __construct(public string $language, public string $source)
    {
    }

    public static function resolve(string $rootPath): self
    {
        try {
            $binding = HostSessionBinding::fromEnvironment();
            $language = $binding->preference()?->language;
            if ($language !== null) {
                return new self($language, self::SOURCE_HOST_SESSION);
            }
            // The user's own default follows them into every new session; a session language still wins.
            $default = $binding->directory === null ? null : (new InteractionPreferenceStore($binding->directory))->defaultLanguage();
            if ($default !== null) {
                return new self($default, self::SOURCE_USER_DEFAULT);
            }
        } catch (Throwable) {
            // An unreadable host preference cannot change presentation; the project setting applies.
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
