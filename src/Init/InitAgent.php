<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitAgent
{
    /**
     * @var list<string>
     */
    private const array CANONICAL = ['codex', 'claude', 'copilot', 'antigravity'];

    private function __construct(
        private string $canonicalName,
        private bool $all,
        /**
         * @var list<string>
         */
        private array $messages,
    ) {
    }

    /**
     * @param list<string> $allowedCanonicalAgents
     * @param array<string, array<string, string>> $configOverrides
     */
    public static function parse(
        string $requestedName,
        array $allowedCanonicalAgents,
        bool $allowAll = false,
        array $configOverrides = [],
    ): self {
        $normalizedRequestedName = strtolower(trim($requestedName));
        if ($normalizedRequestedName === '') {
            throw new InvalidArgumentException('Missing required option: --agent');
        }

        if ($allowAll && $normalizedRequestedName === 'all') {
            return new self('all', true, []);
        }

        $resolved = self::resolveConfiguredAlias($normalizedRequestedName, $configOverrides)
            ?? self::resolveBuiltInAlias($normalizedRequestedName);

        if ($resolved === null || !in_array($resolved['canonical'], self::CANONICAL, true)) {
            throw new InvalidArgumentException('Unknown agent: ' . $requestedName);
        }

        if (!in_array($resolved['canonical'], $allowedCanonicalAgents, true)) {
            throw new InvalidArgumentException('Unknown agent: ' . $requestedName);
        }

        return new self($resolved['canonical'], false, $resolved['messages']);
    }

    public function canonicalName(): string
    {
        return $this->canonicalName;
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @param array<string, array<string, string>> $configOverrides
     *
     * @return array{canonical: string, messages: list<string>}|null
     */
    private static function resolveConfiguredAlias(string $requestedName, array $configOverrides): ?array
    {
        $config = $configOverrides[$requestedName] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $canonical = $config['maps_to'] ?? null;
        if (!is_string($canonical) || $canonical === '') {
            return null;
        }

        $messages = [];
        if (($config['status'] ?? '') === 'legacy_alias' && $requestedName !== $canonical) {
            $messages[] = '[WARN] Agent "' . $requestedName . '" is treated as a legacy Google coding-agent alias.';
            $messages[] = '[INFO] Using canonical agent "' . $canonical . '".';
        }

        return [
            'canonical' => $canonical,
            'messages' => $messages,
        ];
    }

    /**
     * @return array{canonical: string, messages: list<string>}|null
     */
    private static function resolveBuiltInAlias(string $requestedName): ?array
    {
        return match ($requestedName) {
            'codex', 'claude', 'copilot', 'antigravity' => [
                'canonical' => $requestedName,
                'messages' => [],
            ],
            'openai-codex' => [
                'canonical' => 'codex',
                'messages' => [],
            ],
            'claude-code' => [
                'canonical' => 'claude',
                'messages' => [],
            ],
            'github-copilot' => [
                'canonical' => 'copilot',
                'messages' => [],
            ],
            'agy', 'google-antigravity' => [
                'canonical' => 'antigravity',
                'messages' => [],
            ],
            'gemini', 'gemini-cli' => [
                'canonical' => 'antigravity',
                'messages' => [
                    '[WARN] Agent "' . $requestedName . '" is treated as a legacy Google coding-agent alias.',
                    '[INFO] Using canonical agent "antigravity".',
                ],
            ],
            default => null,
        };
    }
}
