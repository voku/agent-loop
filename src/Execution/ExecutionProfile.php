<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class ExecutionProfile
{
    /**
     * @param list<ExecutionRole> $roles
     * @param list<ExecutionStage> $stages
     */
    public function __construct(
        public ExecutionProfileName $name,
        public array $roles,
        public array $stages,
    ) {
    }

    public static function firstParty(ExecutionProfileName $name): self
    {
        $investigator = new ExecutionRole('investigator', false, ['inspect', 'report']);
        $builder = new ExecutionRole('builder', true, ['inspect', 'mutate', 'validate']);
        $reviewer = new ExecutionRole('reviewer', false, ['inspect', 'review']);
        $correctness = new ExecutionRole('correctness-review', false, ['inspect', 'review']);
        $architecture = new ExecutionRole('architecture-review', false, ['inspect', 'architecture-review']);
        $hardening = new ExecutionRole('hardening', true, ['inspect', 'mutate', 'validate', 'hardening']);
        $independentVerification = new ExecutionRole('independent-verification', false, ['inspect', 'verify']);
        $blindspots = new ExecutionRole('blindspot-review', false, ['inspect', 'review']);

        return match ($name) {
            ExecutionProfileName::MANUAL => new self($name, [], []),
            ExecutionProfileName::SURGICAL => new self(
                $name,
                [$investigator, $builder, $reviewer],
                [
                    self::agent('investigate', 'investigator', false, [], [StageOutcome::COMPLETED->value => 'build']),
                    self::agent('build', 'builder', true, ['investigate'], [StageOutcome::COMPLETED->value => 'review']),
                    self::agent('review', 'reviewer', false, ['build'], [
                        StageOutcome::PASS->value => 'verify',
                        StageOutcome::CHANGES_REQUIRED->value => 'build',
                    ]),
                    self::deterministic('verify', ['review'], [StageOutcome::PASS->value => null]),
                ],
            ),
            ExecutionProfileName::STANDARD => new self(
                $name,
                [$investigator, $builder, $correctness, $blindspots],
                [
                    self::agent('investigate', 'investigator', false, [], [StageOutcome::COMPLETED->value => 'build']),
                    self::agent('build', 'builder', true, ['investigate'], [StageOutcome::COMPLETED->value => 'correctness-review']),
                    self::agent('correctness-review', 'correctness-review', false, ['build'], [
                        StageOutcome::PASS->value => 'blindspot-review',
                        StageOutcome::CHANGES_REQUIRED->value => 'build',
                    ]),
                    self::agent('blindspot-review', 'blindspot-review', false, ['correctness-review'], [
                        StageOutcome::PASS->value => 'verify',
                        StageOutcome::CHANGES_REQUIRED->value => 'build',
                    ]),
                    self::deterministic('verify', ['blindspot-review'], [StageOutcome::PASS->value => null]),
                ],
            ),
            ExecutionProfileName::HARDENED => new self(
                $name,
                [$investigator, $builder, $correctness, $architecture, $hardening, $independentVerification, $blindspots],
                [
                    self::agent('investigate', 'investigator', false, [], [StageOutcome::COMPLETED->value => 'build']),
                    self::agent('build', 'builder', true, ['investigate'], [StageOutcome::COMPLETED->value => 'correctness-review']),
                    self::agent('correctness-review', 'correctness-review', false, ['build'], [
                        StageOutcome::PASS->value => 'architecture-review',
                        StageOutcome::CHANGES_REQUIRED->value => 'build',
                    ]),
                    self::agent('architecture-review', 'architecture-review', false, ['correctness-review'], [
                        StageOutcome::PASS->value => 'hardening',
                        StageOutcome::CHANGES_REQUIRED->value => 'build',
                    ]),
                    self::agent('hardening', 'hardening', true, ['architecture-review'], [StageOutcome::COMPLETED->value => 'independent-verification']),
                    self::agent('independent-verification', 'independent-verification', false, ['hardening'], [
                        StageOutcome::PASS->value => 'blindspot-review',
                        StageOutcome::CHANGES_REQUIRED->value => 'hardening',
                    ]),
                    self::agent('blindspot-review', 'blindspot-review', false, ['independent-verification'], [
                        StageOutcome::PASS->value => 'verify',
                        StageOutcome::CHANGES_REQUIRED->value => 'build',
                    ]),
                    self::deterministic('verify', ['blindspot-review'], [StageOutcome::PASS->value => null]),
                ],
            ),
        };
    }

    /**
     * @param list<non-empty-string> $requires
     * @param array<string, non-empty-string|null> $transitions
     */
    private static function agent(string $id, string $role, bool $mayMutate, array $requires, array $transitions): ExecutionStage
    {
        return new ExecutionStage($id, ExecutionStageKind::AGENT, $role, $mayMutate, $requires, $transitions);
    }

    /**
     * @param list<non-empty-string> $requires
     * @param array<string, non-empty-string|null> $transitions
     */
    private static function deterministic(string $id, array $requires, array $transitions): ExecutionStage
    {
        return new ExecutionStage($id, ExecutionStageKind::DETERMINISTIC, null, false, $requires, $transitions);
    }
}
