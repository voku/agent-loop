<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentDecision\DecisionModel;
use voku\AgentDecision\Provider\SystemOneChoiceHttpClient;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\ProjectLayout;

/**
 * Advisory-only recommendation on an existing human decision projection.
 *
 * A bounded external model receives public structural metadata, NEVER the
 * Contract goal, file paths, review content, task ID, or private project data.
 * The authoritative next action and named human choice are never modified.
 */
final readonly class WorkflowDecisionAdvisor
{
    public function __construct(
        private string $rootPath,
        private ?DecisionModel $model = null,
    ) {
    }

    /**
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     */
    public function advise(string $type, array $subject): array
    {
        $config = (new InitConfigLoader($this->rootPath))->load((new ProjectLayout($this->rootPath))->configPath());
        $settings = $config['interaction']['decision_advice'];
        $mode = $settings['mode'];
        $choices = self::choices($type);
        $baseline = self::ruleRecommendation($type, $subject);
        $recommendation = [
            'schema_version' => '1.0',
            'authority' => 'advisory_only',
            'human_decision_required' => true,
            'provider_mode' => $mode,
            'source' => 'deterministic',
            'disposition' => $baseline['choice'] === null ? 'abstain' : 'suggest',
            'suggested_choice' => $baseline['choice'],
            'reason' => $baseline['reason'],
            'evidence_keys' => $baseline['evidence_keys'],
            'available_choices' => array_keys($choices),
            'provider' => null,
            'provider_disposition' => $mode === 'off' ? 'not_enabled' : null,
            'provider_observed_choice' => null,
            'provider_probability' => null,
            'provider_latency_ms' => null,
            'provider_error' => null,
        ];

        if (!in_array($mode, ['shadow', 'assist'], true) || $choices === []) {
            return $recommendation;
        }

        // The operator must explicitly classify this entire project as
        // public-metadata-safe before any advisory inference is attempted.
        if ($settings['data_classification'] !== 'public_metadata') {
            $recommendation['provider_disposition'] = 'not_enabled';

            return $recommendation;
        }

        $start = hrtime(true);
        try {
            $endpoint = $settings['endpoint'];
            if ($endpoint === null) {
                throw new \InvalidArgumentException('Missing explicit decision endpoint.');
            }
            $token = null;
            $tokenEnvironment = $settings['token_env'];

            if ($this->model === null) {
                // init.json is repository-controlled and therefore not a trusted
                // operator authorization source. Require an exact, out-of-band
                // process-environment grant BEFORE reading a credential or
                // constructing the network provider. Explicitly injected models
                // remain the trusted calling host's responsibility.
                $authorizedEndpoint = getenv('AGENT_LOOP_DECISION_APPROVED_ENDPOINT');
                $authorizedTokenEnvironment = getenv('AGENT_LOOP_DECISION_APPROVED_TOKEN_ENV');
                if (
                    !is_string($authorizedEndpoint)
                    || $authorizedEndpoint === ''
                    || !hash_equals($authorizedEndpoint, $endpoint)
                    || ($tokenEnvironment !== null && (
                        !is_string($authorizedTokenEnvironment)
                        || !hash_equals($authorizedTokenEnvironment, $tokenEnvironment)
                    ))
                ) {
                    $recommendation['provider_disposition'] = 'not_enabled';
                    $recommendation['provider_error'] = 'operator_authorization_required';

                    return $recommendation;
                }

                if ($tokenEnvironment !== null) {
                    $read = getenv($tokenEnvironment);
                    if (!is_string($read) || $read === '') {
                        throw new \InvalidArgumentException('Configured decision token is unavailable.');
                    }
                    $token = $read;
                }
            }

            $model = $this->model ?? new SystemOneChoiceHttpClient(
                $endpoint,
                $settings['timeout_ms'],
                $settings['allow_loopback'],
                $token,
            );
            $decision = $model->decide(
                self::publicMetadata($type, $subject),
                $choices,
                $settings['model'],
            );
            if (
                ($decision->candidateId !== null && !array_key_exists($decision->candidateId, $choices))
                || ($decision->candidateId === null && $decision->answerProbability !== null)
                || ($decision->candidateId !== null && $decision->answerProbability === null)
                || ($decision->answerProbability !== null && (
                    !is_finite($decision->answerProbability)
                    || $decision->answerProbability < 0.0
                    || $decision->answerProbability > 1.0
                ))
            ) {
                throw new \UnexpectedValueException('Decision model returned an invalid bounded recommendation.');
            }
            // Public structural metadata does not contain the full Contract, review,
            // residual risk or named human's acceptance. Never let a model infer
            // a positive authority-bearing action from counts alone.
            if ($decision->candidateId !== null && self::requiresHumanEvidence($decision->candidateId)) {
                $recommendation['provider_disposition'] = 'abstain';
                $recommendation['provider_error'] = 'insufficient_decision_evidence';
                $recommendation['provider_observed_choice'] = null;
                $recommendation['provider_probability'] = null;
                $recommendation['provider'] = 'system_one';
                $recommendation['provider_latency_ms'] = round((hrtime(true) - $start) / 1_000_000, 3);

                return $recommendation;
            }
            $recommendation['provider_disposition'] = $decision->candidateId === null ? 'abstain' : 'suggest';
            $recommendation['provider_observed_choice'] = $decision->candidateId;
            $recommendation['provider_probability'] = $decision->answerProbability;
            if ($mode === 'assist' && $decision->candidateId !== null) {
                $recommendation['source'] = 'decision_model';
                $recommendation['disposition'] = 'suggest';
                $recommendation['suggested_choice'] = $decision->candidateId;
                $recommendation['reason'] = 'Model recommendation from structural metadata only. Verify the full decision subject yourself; the model does not know its private contents.';
                $recommendation['evidence_keys'] = ['public_structural_metadata_only'];
            }
        } catch (\UnexpectedValueException) {
            $recommendation['provider_disposition'] = 'invalid_response';
            $recommendation['provider_error'] = 'response_schema';
        } catch (Throwable) {
            $recommendation['provider_disposition'] = 'provider_unavailable';
            $recommendation['provider_error'] = 'configuration_or_transport';
        }
        $recommendation['provider'] = 'system_one';
        $recommendation['provider_latency_ms'] = round((hrtime(true) - $start) / 1_000_000, 3);

        return $recommendation;
    }

    private static function requiresHumanEvidence(string $choice): bool
    {
        return in_array($choice, [
            'approve',
            'acknowledge_review',
            'findings_recorded',
            'no_durable_learning',
            'follow_up_required',
            'accept_risk',
            'supersede_contract',
        ], true);
    }

    /**
     * Only these options can be suggested, never arbitrary commands or approval tokens.
     *
     * @return array<string, non-empty-string>
     */
    private static function choices(string $type): array
    {
        return match ($type) {
            'contract_approval' => [
                'approve' => 'Approve the exact Contract revision after independent review.',
                'request_changes' => 'Request a revised Contract; do not approve yet.',
                'reject' => 'Reject the proposed Contract and require a new revision.',
            ],
            'review_acknowledgement' => [
                'acknowledge_review' => 'Acknowledge only after actually reviewing the exact report.',
                'defer_acknowledgement' => 'Keep the human review acknowledgement pending.',
            ],
            'learning_disposition' => [
                'no_durable_learning' => 'No reusable finding identified.',
                'findings_recorded' => 'Record a verified reusable finding.',
                'follow_up_required' => 'Record a concrete follow-up requirement.',
            ],
            'risk_acceptance' => [
                'accept_risk' => 'Explicitly accept the stated residual risk.',
                'defer_risk_acceptance' => 'Do not accept risk until its evidence and consequences are understood.',
            ],
            'contract_supersession' => [
                'supersede_contract' => 'Submit a new Contract revision for human approval.',
                'defer_supersession' => 'Keep the existing approved scope unchanged.',
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $subject
     * @return array{choice: string|null, reason: string, evidence_keys: list<string>}
     */
    private static function ruleRecommendation(string $type, array $subject): array
    {
        if ($type === 'contract_approval') {
            $missing = [];
            foreach (['scope', 'validation', 'acceptance_criteria'] as $key) {
                if (!is_array($subject[$key] ?? null) || $subject[$key] === []) {
                    $missing[] = $key;
                }
            }

            if ($missing !== []) {
                return [
                    'choice' => 'request_changes',
                    'reason' => 'The proposed Contract is missing reviewable scope, validation, or acceptance evidence.',
                    'evidence_keys' => $missing,
                ];
            }
        }

        if ($type === 'risk_acceptance') {
            return [
                'choice' => 'defer_risk_acceptance',
                'reason' => 'Residual risk requires an explicit human judgment against evidence, never inference from workflow state.',
                'evidence_keys' => ['blockers'],
            ];
        }
        if ($type === 'review_acknowledgement') {
            return [
                'choice' => 'defer_acknowledgement',
                'reason' => 'A report status does not prove the named human has read the exact review.',
                'evidence_keys' => ['review_sha256', 'findings_summary'],
            ];
        }
        if ($type === 'contract_supersession') {
            return [
                'choice' => 'defer_supersession',
                'reason' => 'Do not change an approved Contract without inspecting the minimum necessary scope change.',
                'evidence_keys' => ['current_contract_revision', 'minimum_contract_change'],
            ];
        }

        return [
            'choice' => null,
            'reason' => 'Available workflow evidence does not justify a decision. The named human must inspect the exact subject.',
            'evidence_keys' => [],
        ];
    }

    /**
     * No task text, file paths, goals, reviews or blocker messages cross this boundary.
     * @param array<string, mixed> $subject
     */
    private static function publicMetadata(string $type, array $subject): string
    {
        $signals = ['decision_type' => $type];
        if ($type === 'contract_approval') {
            foreach (['scope', 'validation', 'acceptance_criteria', 'non_goals'] as $key) {
                $items = $subject[$key] ?? null;
                $signals[$key . '_count'] = is_array($items) ? count($items) : 0;
            }
            $signals['has_revision_delta'] = is_array($subject['revision_delta'] ?? null);
        } elseif ($type === 'review_acknowledgement') {
            $summary = $subject['findings_summary'] ?? null;
            $count = is_array($summary) ? ($summary['total'] ?? null) : null;
            $signals['finding_count'] = is_int($count) ? $count : null;
        } elseif ($type === 'risk_acceptance' || $type === 'contract_supersession') {
            $items = $subject['blockers'] ?? null;
            $signals['blocker_count'] = is_array($items) ? count($items) : 0;
        } elseif ($type === 'learning_disposition') {
            $signals['has_review_sha256'] = is_string($subject['review_sha256'] ?? null);
        }

        return json_encode($signals, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
