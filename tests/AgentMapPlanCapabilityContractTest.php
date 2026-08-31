<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\CliApplication;

final class AgentMapPlanCapabilityContractTest extends TestCase
{
    /** Proves released Map 0.9 exposes the complete ten-plan governed registry. */
    public function testReleasedMap09PublishesCompleteGovernedPlanRegistry(): void
    {
        ob_start();
        try {
            $exit = (new CliApplication())->run(['agent-map', 'plan-capabilities', '--format=json']);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame(0, $exit);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('plan_capabilities', $payload['type'] ?? null);

        $contracts = [];
        foreach (is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [] as $capability) {
            self::assertIsArray($capability);
            $family = $capability['family'] ?? null;
            $planType = $capability['plan_type'] ?? null;
            $version = $capability['contract_version'] ?? null;
            self::assertIsString($family);
            self::assertIsString($planType);
            self::assertIsString($version);
            $contracts[] = $family . ':' . $planType . '@' . $version;
        }
        sort($contracts, SORT_STRING);

        self::assertSame([
            'move:class_move_plan@1.0',
            'removal:class_constant_removal_plan@1.0',
            'removal:method_removal_plan@1.0',
            'removal:property_removal_plan@1.0',
            'rename:class_constant_rename_plan@1.0',
            'rename:class_rename_plan@1.0',
            'rename:function_rename_plan@1.0',
            'rename:method_rename_plan@1.0',
            'rename:parameter_rename_plan@1.0',
            'rename:property_rename_plan@1.0',
        ], $contracts);
    }
}
