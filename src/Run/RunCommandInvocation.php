<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use InvalidArgumentException;

final readonly class RunCommandInvocation
{
    /**
     * @param non-empty-string $executable
     * @param list<string> $arguments
     */
    public function __construct(
        public string $executable,
        public array $arguments,
        public bool $template = false,
    ) {
        if (trim($executable) === '') {
            throw new InvalidArgumentException('Run command invocation requires a non-empty executable.');
        }
    }

    /**
     * @return array{
     *     executable: non-empty-string,
     *     arguments: list<string>,
     *     template: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'executable' => $this->executable,
            'arguments' => $this->arguments,
            'template' => $this->template,
        ];
    }
}
