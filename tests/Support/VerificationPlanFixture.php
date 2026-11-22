<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Support;

/**
 * A `verification-plan.json` with the full top-level shape agent-recall-compiler's writer emits.
 *
 * The owner's `VerificationPlanReader` rejects a plan without that shape, so a hand-built fixture
 * states only what a test is about and takes the rest of the writer's shape from here.
 */
final class VerificationPlanFixture
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function shape(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0',
            'task_id' => 'TASK-1',
            'target' => 'Demo\\Service::save',
            'map_digest' => 'sha256:' . str_repeat('0', 64),
            'analysis_fingerprint' => null,
            'knowledge_probes' => [],
            'omitted_probe_candidates' => [],
            'checklist' => [],
            'objective_gates' => [],
            'required_evidence_types' => ['source_location'],
            'generator' => ['name' => 'test-fixture', 'version' => '1', 'seed_sha256' => str_repeat('0', 64)],
        ], $overrides);
    }
}
