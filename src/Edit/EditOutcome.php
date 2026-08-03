<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

final readonly class EditOutcome
{
    public function __construct(
        public string $outputDirectory,
        public string $promptPath,
        public string $executionPath,
        public string $mapDigest,
        public string $recallBundleDigest,
        public EditRunResult $runResult,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->runResult->succeeded();
    }
}
