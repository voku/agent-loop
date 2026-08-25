<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use RuntimeException;

/**
 * Raised when a caller tries to apply a plan that no longer matches the
 * repository.
 *
 * Deliberately typed and deliberately fatal to the operation: the alternative
 * is applying a removal that was reasoned about against different files.
 */
final class StaleRepositorySetupPlan extends RuntimeException
{
    public function __construct(
        public readonly string $expectedState,
        public readonly string $observedState,
    ) {
        parent::__construct(
            'The repository changed after this setup plan was computed. '
            . 'Expected state ' . $expectedState . ' but observed ' . $observedState . '. '
            . 'Re-read the plan and confirm it again before applying.',
        );
    }
}
