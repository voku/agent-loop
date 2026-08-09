<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

final readonly class ExecutionContractDocument
{
    private const array SECTIONS = ['Goal', 'Context', 'Constraints', 'Verification', 'Done When'];

    public string $content;

    public function __construct(string $content)
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        if ($content === '') {
            throw new InvalidArgumentException('Execution contract must not be empty.');
        }

        preg_match_all('/^## ([^\n]+)$/m', $content, $matches, PREG_OFFSET_CAPTURE);
        $headings = array_map(
            static fn (array $match): string => $match[0],
            $matches[1],
        );
        if ($headings !== self::SECTIONS) {
            throw new InvalidArgumentException(
                'Execution contract must contain exactly these ordered H2 sections: '
                . implode(', ', self::SECTIONS)
                . '.',
            );
        }

        /** @var list<array{0:string,1:int}> $fullMatches */
        $fullMatches = $matches[0];
        foreach ($fullMatches as $index => $heading) {
            $bodyStart = $heading[1] + strlen($heading[0]);
            $bodyEnd = $fullMatches[$index + 1][1] ?? strlen($content);
            if (trim(substr($content, $bodyStart, $bodyEnd - $bodyStart)) === '') {
                throw new InvalidArgumentException('Execution contract section must not be empty: ' . self::SECTIONS[$index]);
            }
        }

        $this->content = $content . "\n";
    }

    public function sha256(): string
    {
        return 'sha256:' . hash('sha256', $this->content);
    }
}
