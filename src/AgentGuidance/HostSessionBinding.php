<?php

declare(strict_types=1);

namespace voku\AgentLoop\AgentGuidance;

/**
 * Which host session this process runs in, read from variables the host itself
 * exports, and where that user's interaction preferences live. Presentation
 * context only: an identity here conveys no actor identity and no authority.
 */
final readonly class HostSessionBinding
{
    public const string UNBOUND = 'unbound';
    public const string INVALID = 'invalid';
    public const string BOUND = 'bound';

    public const string EXPLICIT_VARIABLE = 'AGENT_LOOP_HOST_SESSION_ID';
    public const string DIRECTORY_VARIABLE = 'AGENT_LOOP_INTERACTION_DIR';

    /**
     * Session variables observed in a real host shell, in order of precedence.
     * Add another host only after observing its variable; a guessed name would
     * bind nothing or, worse, something that is not a session.
     *
     * @var list<non-empty-string>
     */
    private const array SESSION_VARIABLES = [self::EXPLICIT_VARIABLE, 'CLAUDE_CODE_SESSION_ID'];

    private function __construct(
        public string $state,
        public ?string $sessionId,
        public ?string $source,
        public ?string $directory,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $directory = self::preferenceDirectory();
        foreach (self::SESSION_VARIABLES as $variable) {
            $sessionId = self::environment($variable);
            if ($sessionId === null) {
                continue;
            }

            // The first variable that is set decides. Falling through from an
            // invalid identity would silently bind a different session.
            return self::isValidSessionId($sessionId)
                ? new self(self::BOUND, $sessionId, $variable, $directory)
                : new self(self::INVALID, null, $variable, $directory);
        }

        return new self(self::UNBOUND, null, null, $directory);
    }

    public static function isValidSessionId(string $sessionId): bool
    {
        return strlen($sessionId) <= 128 && preg_match('/\A(?!-)[A-Za-z0-9._-]+\z/', $sessionId) === 1;
    }

    /** The configured directory, else the user's own state directory; null when neither is known. */
    public static function preferenceDirectory(): ?string
    {
        $configured = self::environment(self::DIRECTORY_VARIABLE);
        if ($configured !== null) {
            return $configured;
        }
        $state = self::environment('XDG_STATE_HOME');
        if ($state === null || !str_starts_with($state, '/')) {
            $home = self::environment('HOME');
            if ($home === null || !str_starts_with($home, '/')) {
                return null;
            }
            $state = rtrim($home, '/') . '/.local/state';
        }

        return rtrim($state, '/') . '/agent-loop/interaction';
    }

    /** The saved or inherited preference; null when unbound or none is saved. Read-only. */
    public function preference(): ?InteractionPreference
    {
        return $this->sessionId === null || $this->directory === null
            ? null
            : (new InteractionPreferenceStore($this->directory))->effective($this->sessionId);
    }

    /** Whether the session has no preference of its own yet and would use the shared default. */
    public function inheritsSharedDefault(): bool
    {
        return $this->sessionId !== null && $this->directory !== null
            && (new InteractionPreferenceStore($this->directory))->find($this->sessionId) === null
            && $this->preference() !== null;
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
