<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/** One contributor to the desired repository setup projection. */
final readonly class RepositorySetupContributor
{
    public const string SCOPE_CONSUMER = 'consumer';

    public const string SCOPE_OWNER_REPOSITORY = 'owner_repository';

    public const string SCOPE_PROJECT = 'project';

    public const string SCOPE_LOCAL = 'local';

    /** @var non-empty-string */
    public string $owner;

    /** @var self::SCOPE_CONSUMER|self::SCOPE_OWNER_REPOSITORY|self::SCOPE_PROJECT|self::SCOPE_LOCAL */
    public string $scope;

    /** @var int<0, max> */
    public int $skillCount;

    /** @var int<0, max> */
    public int $subagentCount;

    /** @var int<0, max> */
    public int $instructionCount;

    public function __construct(
        string $owner,
        string $scope,
        int $skillCount,
        int $subagentCount,
        int $instructionCount,
    ) {
        if ($owner === '') {
            throw new InvalidArgumentException('Repository setup contributor owner must not be empty.');
        }
        if (!in_array($scope, [
            self::SCOPE_CONSUMER,
            self::SCOPE_OWNER_REPOSITORY,
            self::SCOPE_PROJECT,
            self::SCOPE_LOCAL,
        ], true)) {
            throw new InvalidArgumentException('Unsupported repository setup contributor scope: ' . $scope);
        }
        if ($skillCount < 0 || $subagentCount < 0 || $instructionCount < 0) {
            throw new InvalidArgumentException('Repository setup contributor counts must not be negative.');
        }

        $this->owner = $owner;
        $this->scope = $scope;
        $this->skillCount = $skillCount;
        $this->subagentCount = $subagentCount;
        $this->instructionCount = $instructionCount;
    }

    /**
     * @return array{
     *     owner: non-empty-string,
     *     scope: self::SCOPE_CONSUMER|self::SCOPE_OWNER_REPOSITORY|self::SCOPE_PROJECT|self::SCOPE_LOCAL,
     *     skill_count: int<0, max>,
     *     subagent_count: int<0, max>,
     *     instruction_count: int<0, max>
     * }
     */
    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            'scope' => $this->scope,
            'skill_count' => $this->skillCount,
            'subagent_count' => $this->subagentCount,
            'instruction_count' => $this->instructionCount,
        ];
    }
}
