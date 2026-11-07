<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;
use voku\AgentRecallCompiler\OperatingPromptCatalog;
use voku\AgentRecallCompiler\Selection\BoundedChoicePromptBuilder;
use voku\AgentRecallCompiler\Selection\BoundedChoiceValidator;

/**
 * Opt-in read-only bridge to Recall's released bounded-choice protocol.
 *
 * Candidates come from the owner catalog. The injected host owns model
 * transport; this service does not run a provider, mutate workflow state,
 * persist raw model output, or grant execution authority.
 */
final readonly class WorkflowRecipeChoiceService
{
    private const int MAX_TASK_BYTES = 4096;
    private const int MAX_CANDIDATES = 5;

    /** @var (Closure(string): mixed)|null */
    private ?Closure $model;

    /** @param (callable(string): mixed)|null $model */
    public function __construct(?callable $model = null)
    {
        $this->model = $model === null ? null : Closure::fromCallable($model);
    }

    /**
     * The default call is deliberately inactive. A host must opt in *and*
     * supply its own model transport to evaluate existing catalog candidates.
     *
     * Null selection never means workflow approval or a semantic success.
     *
     * @param list<string> $candidateIds Source-owned, ordered recipe IDs.
     * @return array{
     *     status: 'disabled'|'no_candidates'|'selected'|'no_verified_selection'|'model_error'|'invalid_model_output',
     *     authority: 'context_only',
     *     selected_id: string|null,
     *     candidate_ids: list<string>,
     *     prompt_sha256: string|null,
     *     response_sha256: string|null,
     *     error_type: string|null
     * }
     */
    public function evaluate(string $taskIntent, array $candidateIds, bool $enabled = false): array
    {
        if (!$enabled) {
            return $this->result('disabled', [], null, null, null, null);
        }

        if ($this->model === null) {
            throw new LogicException('Opt-in recipe selection requires an explicit host model callback.');
        }

        if (!self::isList($candidateIds)) {
            throw new InvalidArgumentException('Recipe candidate IDs must be a zero-indexed list.');
        }

        if (count($candidateIds) > self::MAX_CANDIDATES) {
            throw new InvalidArgumentException('Too many recipe candidates; maximum is ' . self::MAX_CANDIDATES . '.');
        }

        if (trim($taskIntent) === '' || strlen($taskIntent) > self::MAX_TASK_BYTES) {
            throw new InvalidArgumentException('Recipe task intent must be non-empty and at most 4096 bytes.');
        }

        if ($candidateIds === []) {
            return $this->result('no_candidates', [], null, null, null, null);
        }

        $seen = [];
        $candidates = [];
        $catalog = OperatingPromptCatalog::bundled();
        foreach ($candidateIds as $id) {
            if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]*\z/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid recipe candidate ID.');
            }
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Duplicate recipe candidate: ' . $id);
            }
            $seen[$id] = true;
            // Unknown IDs throw from the source owner; never accept model IDs.
            $recipe = $catalog->recipe($id);
            $candidates[] = [
                'id' => $recipe->id,
                'title' => $recipe->title,
                'description' => $recipe->description,
                'purpose' => $recipe->purpose,
            ];
        }

        $prompt = (new BoundedChoicePromptBuilder())->build(
            $taskIntent,
            $candidates,
            enforceWorkflowApplicability: true,
        );
        $promptSha256 = hash('sha256', $prompt);

        try {
            $rawResponse = ($this->model)($prompt);
        } catch (Throwable $error) {
            return $this->result('model_error', $candidateIds, null, $promptSha256, null, $error::class);
        }

        if (!is_string($rawResponse)) {
            return $this->result('invalid_model_output', $candidateIds, null, $promptSha256, null, get_debug_type($rawResponse));
        }

        $responseSha256 = hash('sha256', $rawResponse);
        $selectedId = (new BoundedChoiceValidator())->validate($rawResponse, $candidateIds);

        return $this->result(
            $selectedId === null ? 'no_verified_selection' : 'selected',
            $candidateIds,
            $selectedId,
            $promptSha256,
            $responseSha256,
            null,
        );
    }

    /** @param mixed $value */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * @param 'disabled'|'no_candidates'|'selected'|'no_verified_selection'|'model_error'|'invalid_model_output' $status
     * @param list<string> $candidateIds
     * @return array{
     *     status: 'disabled'|'no_candidates'|'selected'|'no_verified_selection'|'model_error'|'invalid_model_output',
     *     authority: 'context_only',
     *     selected_id: string|null,
     *     candidate_ids: list<string>,
     *     prompt_sha256: string|null,
     *     response_sha256: string|null,
     *     error_type: string|null
     * }
     */
    private function result(
        string $status,
        array $candidateIds,
        ?string $selectedId,
        ?string $promptSha256,
        ?string $responseSha256,
        ?string $errorType,
    ): array {
        return [
            'status' => $status,
            'authority' => 'context_only',
            'selected_id' => $selectedId,
            'candidate_ids' => $candidateIds,
            'prompt_sha256' => $promptSha256,
            'response_sha256' => $responseSha256,
            'error_type' => $errorType,
        ];
    }
}
