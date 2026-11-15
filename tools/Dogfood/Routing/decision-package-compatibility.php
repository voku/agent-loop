<?php

declare(strict_types=1);

use voku\AgentDecision\BoundedDecisionAdvisor;
use voku\AgentDecision\DataClassification;
use voku\AgentDecision\DecisionChoiceResult as PortableChoiceResult;
use voku\AgentDecision\DecisionDisposition as PortableDisposition;
use voku\AgentDecision\DecisionMode;
use voku\AgentDecision\DecisionModel as PortableModel;
use voku\AgentDecision\DecisionRequest;
use voku\AgentLoop\Decision\DecisionChoiceResult as LoopChoiceResult;
use voku\AgentLoop\Decision\DecisionModel as LoopModel;
use voku\AgentLoop\Decision\DecisionRouteCommand;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$portableAutoload = getenv('AGENT_DECISION_CONSUMER_AUTOLOAD');
if (!is_string($portableAutoload) || !is_file($portableAutoload)) {
    throw new RuntimeException('Installed portable package autoloader is required.');
}
require $portableAutoload;

/** An offline, deterministic fixture implementing the actual Composer package interface. */
$portable = new class('agent-loop-investigator') implements PortableModel {
    public function __construct(private readonly string $choice)
    {
    }

    public function decide(string $publicState, array $criteria, ?string $model): PortableChoiceResult
    {
        if (!array_key_exists($this->choice, $criteria)) {
            throw new UnexpectedValueException('Fixture choice must be canonical.');
        }

        return new PortableChoiceResult($this->choice, 0.8);
    }
};

$criteria = [
    'agent-loop-investigator' => 'Locate the public code owner.',
    'agent-loop-code-reviewer' => 'Review the public change.',
];
$request = new DecisionRequest(
    criteria: $criteria,
    publicState: '{"task_type":"public_issue","candidate_count":2}',
    classification: DataClassification::Public,
);
$advisor = new BoundedDecisionAdvisor($portable);

$off = $advisor->advise($request, DecisionMode::Off);
if ($off->providerDisposition !== PortableDisposition::NotEnabled || $off->suggestedChoice !== null) {
    throw new RuntimeException('Portable package default must remain offline and advisory.');
}

$shadow = $advisor->advise($request, DecisionMode::Shadow);
if (
    $shadow->suggestedChoice !== null
    || $shadow->providerObservedChoice !== 'agent-loop-investigator'
    || $shadow->providerProbability !== 0.8
) {
    throw new RuntimeException('Portable shadow mode changed the caller baseline or lost the observation.');
}

$assist = $advisor->advise($request, DecisionMode::Assist);
if (
    $assist->suggestedChoice !== 'agent-loop-investigator'
    || $assist->providerDisposition !== PortableDisposition::Suggest
    || $assist->toArray()['lifecycle_mutation'] !== false
) {
    throw new RuntimeException('Portable assist contract or human-authority boundary changed.');
}

$protected = new DecisionRequest(
    criteria: ['approve' => 'Approve a Contract.', 'request_changes' => 'Request evidence.'],
    publicState: '{"decision_type":"contract_approval"}',
    classification: DataClassification::Public,
    baselineChoice: 'request_changes',
    choicesRequiringEvidence: ['approve'],
);
$protectedModel = new class implements PortableModel {
    public function decide(string $publicState, array $criteria, ?string $model): PortableChoiceResult
    {
        return new PortableChoiceResult('approve', 0.99);
    }
};
$guard = (new BoundedDecisionAdvisor($protectedModel))->advise($protected, DecisionMode::Assist);
if (
    $guard->suggestedChoice !== 'request_changes'
    || $guard->providerObservedChoice !== null
    || $guard->providerError !== 'insufficient_decision_evidence'
) {
    throw new RuntimeException('Portable provider recommended a protected positive decision.');
}

// Exercise the existing agent-loop public CLI through a narrow cross-namespace
// adapter. No runtime Composer dependency is added before a reviewed release.
$adapter = new class($portable) implements LoopModel {
    public function __construct(private readonly PortableModel $portable)
    {
    }

    public function decide(string $publicState, array $criteria, ?string $model): LoopChoiceResult
    {
        $choice = $this->portable->decide($publicState, $criteria, $model);

        return new LoopChoiceResult($choice->candidateId, $choice->answerProbability);
    }
};

ob_start();
$exitCode = (new DecisionRouteCommand(dirname(__DIR__, 3), $adapter))->run([
    'route',
    '--mode=assist',
    '--candidate=agent-loop-investigator',
    '--data-classification=public',
    '--public-state=Locate an owner for a public issue.',
    '--endpoint=https://example.org/v1/systemone',
    '--format=json',
]);
$output = (string) ob_get_clean();
$receipt = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
if (
    $exitCode !== 0
    || !is_array($receipt)
    || ($receipt['routing_hint']['candidate_id'] ?? null) !== 'agent-loop-investigator'
    || ($receipt['routing_hint']['answer_probability'] ?? null) !== 0.8
    || ($receipt['routing_hint']['host_retains_final_selection'] ?? null) !== true
    || ($receipt['lifecycle_mutation'] ?? null) !== false
) {
    throw new RuntimeException('Actual package result is not compatible with agent-loop CLI routing.');
}

echo "PASS: pinned Composer package installed and bridged to actual agent-loop CLI; offline, shadow, assist, protected choices and authority verified\n";
