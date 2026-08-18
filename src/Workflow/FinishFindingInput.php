<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

/**
 * Authority-bearing Learning content supplied to `finish`.
 *
 * Evidence, task/session lineage, scope, ID allocation and storage are derived
 * by the lifecycle / Learning owners and therefore deliberately do not live here.
 */
final readonly class FinishFindingInput
{
    public function __construct(
        public string $observation,
        public string $hypothesis,
        public string $validatedConclusion,
        public string $confidence,
        public string $sensitivity,
    ) {
        foreach ([
            'observation' => $this->observation,
            'hypothesis' => $this->hypothesis,
            'validated_conclusion' => $this->validatedConclusion,
            'confidence' => $this->confidence,
            'sensitivity' => $this->sensitivity,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Finish Finding ' . $name . ' must be non-empty.');
            }
        }
        if (!in_array($this->confidence, ['low', 'medium', 'high'], true)) {
            throw new InvalidArgumentException('Finish Finding confidence must be low, medium, or high.');
        }
    }
}
