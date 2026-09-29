<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood\Routing;

use InvalidArgumentException;
use LogicException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\ManagedSubagentSourceResolver;

final readonly class RoutingExperimentFixture
{
    /** @var non-empty-list<string> */
    private array $candidateIds;

    /**
     * @param non-empty-list<string> $candidateIds
     */
    private function __construct(
        public string $fixtureId,
        public string $taskPrompt,
        array $candidateIds,
        public ?RoutingHint $hint,
    ) {
        if (trim($fixtureId) === '') {
            throw new InvalidArgumentException('Routing experiment fixture id must not be empty.');
        }
        if (trim($taskPrompt) === '') {
            throw new InvalidArgumentException('Routing experiment task prompt must not be empty.');
        }
        if ($hint !== null && !in_array($hint->candidateId, $candidateIds, true)) {
            throw new InvalidArgumentException('Routing hint candidate is outside the fixture candidate set.');
        }

        $this->candidateIds = $candidateIds;
    }

    public static function baseline(string $rootPath, string $fixtureId, string $taskPrompt): self
    {
        return new self(
            $fixtureId,
            $taskPrompt,
            self::managedCandidateIds($rootPath),
            null,
        );
    }

    public static function hinted(
        string $rootPath,
        string $fixtureId,
        string $taskPrompt,
        string $candidateId,
        float $probability,
    ): self {
        $candidateIds = self::managedCandidateIds($rootPath);

        return new self(
            $fixtureId,
            $taskPrompt,
            $candidateIds,
            new RoutingHint($candidateId, $probability, $candidateIds),
        );
    }

    public function arm(): string
    {
        return $this->hint === null ? 'baseline' : 'hinted';
    }

    /** @return non-empty-list<string> */
    public function candidateIds(): array
    {
        return $this->candidateIds;
    }

    public function renderParentPrompt(): string
    {
        $prompt = rtrim($this->taskPrompt);
        if ($this->hint === null) {
            return $prompt;
        }

        $hint = json_encode(
            [
                ...$this->hint->toArray(),
                'authority' => 'advisory',
                'host_retains_final_selection' => true,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $prompt
            . "\n\n<agent-loop-routing-hint>\n"
            . $hint
            . "\n</agent-loop-routing-hint>";
    }

    /** @return non-empty-list<string> */
    private static function managedCandidateIds(string $rootPath): array
    {
        $paths = AgentAssetSourcePaths::fromSources($rootPath);
        $candidateIds = array_keys((new ManagedSubagentSourceResolver($rootPath))->resolve($paths));
        sort($candidateIds, SORT_STRING);
        if ($candidateIds === []) {
            throw new LogicException('Routing experiment requires at least one managed subagent candidate.');
        }

        return $candidateIds;
    }
}
