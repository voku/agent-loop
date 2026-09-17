<?php

declare(strict_types=1);

namespace voku\AgentLoop\Cli;

/**
 * Logical command groupings for discovery, help, and presentation.
 */
enum CommandGroup: string
{
    case Workflow = 'workflow';
    case EvidenceLearning = 'evidence-learning';
    case Inspection = 'inspection';
    case SetupOps = 'setup-ops';

    public function title(): string
    {
        return match ($this) {
            self::Workflow => 'Workflow & Lifecycle',
            self::EvidenceLearning => 'Evidence & Learning',
            self::Inspection => 'Inspection & Navigation',
            self::SetupOps => 'Setup & Operational',
        };
    }
}
