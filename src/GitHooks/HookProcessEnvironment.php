<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

/** Builds the environment passed to repository-declared shell hooks. */
final readonly class HookProcessEnvironment
{
    private const array SAFE_ADDITIONAL_VARIABLES = [
        'AGENT_LOOP_BIN',
        'AGENT_LOOP_CONTAINER_IMAGE',
        'AGENT_LOOP_CONTAINER_SERVICE',
        'AGENT_LOOP_CONTAINER_USER',
        'AGENT_LOOP_CONTAINER_WORKDIR',
        'AGENT_LOOP_DREAM_AUTORUN',
        'AGENT_LOOP_MAP_INDEX',
        'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS',
        'GIT_INDEX_FILE',
    ];

    private const string SAFE_STANDARD_ENVIRONMENT_PATTERN = '/^(PATH|HOME|USER|LOGNAME|SHELL|LANG|LC_[A-Z_]+|TERM|TMPDIR|TZ|PHP[A-Z_]*|COMPOSER_(?!AUTH$)[A-Z_]+)$/';
    private const string SENSITIVE_ENVIRONMENT_PATTERN = '/(?:^|_)(?:AUTHORIZATION|AUTH|TOKEN|SECRET|PASSWORD|PASSWD|PASSPHRASE|CRED|CREDENTIALS?|KEY|APIKEY|COOKIE|SESSION)(?:_|$)/i';

    /** @return array<string, string> */
    public static function inherited(): array
    {
        $environment = array_merge($_ENV, getenv());
        if (($environment['AGENT_LOOP_HOOK_ENV_PASSTHROUGH'] ?? '') === '1') {
            return array_filter($environment, 'is_string');
        }

        $allowed = [];
        foreach ($environment as $name => $value) {
            $name = (string) $name;
            if (!is_string($value) || preg_match(self::SENSITIVE_ENVIRONMENT_PATTERN, $name) === 1) {
                continue;
            }

            if (preg_match(self::SAFE_STANDARD_ENVIRONMENT_PATTERN, $name) === 1 || in_array($name, self::SAFE_ADDITIONAL_VARIABLES, true)) {
                $allowed[$name] = $value;
            }
        }

        return $allowed;
    }
}
