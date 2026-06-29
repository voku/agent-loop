<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final readonly class InitAssetKind
{
    /**
     * @var list<string>
     */
    private const array SUPPORTED = ['skills', 'subagents', 'hooks', 'all'];

    private function __construct(private string $value)
    {
    }

    public static function fromCli(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!in_array($value, self::SUPPORTED, true)) {
            return null;
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isSkills(): bool
    {
        return $this->value === 'skills';
    }

    public function isSubagents(): bool
    {
        return $this->value === 'subagents';
    }

    public function isHooks(): bool
    {
        return $this->value === 'hooks';
    }

    public function isAll(): bool
    {
        return $this->value === 'all';
    }
}
