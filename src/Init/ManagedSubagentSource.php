<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/** Stable identity and definition for one managed subagent source. */
final readonly class ManagedSubagentSource
{
    public function __construct(
        public string $name,
        public string $path,
        public ManagedAssetSource $assetSource,
    ) {
    }

    public function definition(): SubagentDefinition
    {
        $errors = SubagentDefinition::validationErrors($this->path);
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid subagent ' . basename($this->path) . ': ' . implode('; ', $errors));
        }

        return SubagentDefinition::fromCanonicalFile($this->path);
    }
}
