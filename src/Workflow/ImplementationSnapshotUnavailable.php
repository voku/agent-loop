<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;

/**
 * The approved implementation scope is valid but does not yet contain
 * capturable implementation content.
 */
final class ImplementationSnapshotUnavailable extends RuntimeException
{
}
