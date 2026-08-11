<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\RelativePath;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

final readonly class ExecutionContractStore
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function inspect(string $taskId): array
    {
        $binding = $this->currentBinding($taskId);
        if (($binding['state'] ?? null) !== 'resolved') {
            return $binding;
        }
        if (($binding['required'] ?? false) !== true) {
            return [
                'owner' => 'agent-loop',
                'state' => 'not_required',
                'required' => false,
                'observation_mode' => 'checked',
            ];
        }

        $directory = $this->contractDirectory($taskId);
        $metadataPath = $directory . '/execution-contract.json';
        $documentPath = $directory . '/execution-contract.md';
        if (!is_file($metadataPath)) {
            return $this->bindingReference($binding, 'missing');
        }

        try {
            $metadata = $this->decodeJson($metadataPath);
            $status = ExecutionContractStatus::tryFrom($this->requiredString($metadata, 'status', $metadataPath));
            if (!$status instanceof ExecutionContractStatus) {
                throw new RuntimeException('Unsupported execution contract status in ' . $metadataPath);
            }

            foreach ([
                'task_id' => $taskId,
                'work_brief_revision' => $binding['work_brief_revision'],
                'recall_bundle_sha256' => $binding['recall_bundle_sha256'],
                'prompt_policy_sha256' => $binding['prompt_policy_sha256'],
            ] as $key => $expected) {
                if (($metadata[$key] ?? null) !== $expected) {
                    return $this->bindingReference($binding, 'stale', [
                        'reason' => 'Execution contract binding does not match current ' . $key . '.',
                        'source' => $this->artifact($metadataPath),
                    ]);
                }
            }

            if ($status !== ExecutionContractStatus::READY) {
                return $this->bindingReference($binding, $status->value, [
                    'reason' => is_string($metadata['reason'] ?? null) ? $metadata['reason'] : null,
                    'evidence' => $this->stringList($metadata['evidence'] ?? []),
                    'affected_constraint' => is_string($metadata['affected_constraint'] ?? null) ? $metadata['affected_constraint'] : null,
                    'minimum_contract_change' => is_string($metadata['minimum_contract_change'] ?? null) ? $metadata['minimum_contract_change'] : null,
                    'source' => $this->artifact($metadataPath),
                ]);
            }

            if (!is_file($documentPath)) {
                throw new RuntimeException('Ready execution contract is missing execution-contract.md.');
            }
            $content = file_get_contents($documentPath);
            if ($content === false) {
                throw new RuntimeException('Unable to read execution contract document.');
            }
            $document = new ExecutionContractDocument($content);
            if (($metadata['content_sha256'] ?? null) !== $document->sha256()) {
                throw new RuntimeException('Execution contract content digest does not match metadata.');
            }

            return $this->bindingReference($binding, 'ready', [
                'source' => $this->artifact($metadataPath),
                'document' => $this->artifact($documentPath),
                'created_by' => is_string($metadata['created_by'] ?? null) ? $metadata['created_by'] : null,
            ]);
        } catch (RuntimeException | InvalidArgumentException $exception) {
            return $this->bindingReference($binding, 'invalid', [
                'reason' => $exception->getMessage(),
                'source' => $this->artifact($metadataPath),
            ]);
        }
    }

    public function writeReady(string $taskId, string $actor, string $content): string
    {
        $binding = $this->requireResolvedL2Binding($taskId);
        $document = new ExecutionContractDocument($content);
        $directory = $this->contractDirectory($taskId);
        $this->makeDirectory($directory);

        $documentPath = $directory . '/execution-contract.md';
        $metadataPath = $directory . '/execution-contract.json';
        $this->atomicWrite($documentPath, $document->content);
        $this->atomicWrite($metadataPath, CanonicalJson::pretty([
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'work_brief_revision' => $binding['work_brief_revision'],
            'recall_bundle_sha256' => $binding['recall_bundle_sha256'],
            'prompt_policy_sha256' => $binding['prompt_policy_sha256'],
            'prompt_ids' => $binding['prompt_ids'],
            'status' => ExecutionContractStatus::READY->value,
            'content_sha256' => $document->sha256(),
            'created_by' => $this->actor($actor),
            'created_at' => $this->now(),
        ]));

        return RelativePath::fromRoot($this->rootPath, $metadataPath);
    }

    /** @param list<string> $evidence */
    public function writeStopped(
        string $taskId,
        ExecutionContractStatus $status,
        string $actor,
        string $reason,
        array $evidence,
        ?string $affectedConstraint,
        string $minimumContractChange,
    ): string {
        if ($status === ExecutionContractStatus::READY) {
            throw new RuntimeException('writeStopped requires blocked or rejected status.');
        }
        $binding = $this->requireResolvedL2Binding($taskId);
        $reason = trim($reason);
        $minimumContractChange = trim($minimumContractChange);
        $evidence = array_values(array_unique(array_filter(array_map('trim', $evidence), static fn (string $item): bool => $item !== '')));
        if ($reason === '' || $minimumContractChange === '' || $evidence === []) {
            throw new RuntimeException('Blocked/rejected execution contracts require reason, evidence, and minimum contract change.');
        }

        $directory = $this->contractDirectory($taskId);
        $this->makeDirectory($directory);
        $metadataPath = $directory . '/execution-contract.json';
        $this->atomicWrite($metadataPath, CanonicalJson::pretty([
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'work_brief_revision' => $binding['work_brief_revision'],
            'recall_bundle_sha256' => $binding['recall_bundle_sha256'],
            'prompt_policy_sha256' => $binding['prompt_policy_sha256'],
            'prompt_ids' => $binding['prompt_ids'],
            'status' => $status->value,
            'content_sha256' => null,
            'reason' => $reason,
            'evidence' => $evidence,
            'affected_constraint' => $this->nullableTrim($affectedConstraint),
            'minimum_contract_change' => $minimumContractChange,
            'created_by' => $this->actor($actor),
            'created_at' => $this->now(),
        ]));

        return RelativePath::fromRoot($this->rootPath, $metadataPath);
    }

    public function assertReadyForMutation(string $taskId): void
    {
        $reference = $this->inspect($taskId);
        if (in_array($reference['state'] ?? null, ['not_required', 'not_applicable', 'ready'], true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Governed task %s is not implementation-ready: execution contract is %s. Run `agent-loop workflow status %s` and satisfy the contract gate before mutation.',
            $taskId,
            is_string($reference['state'] ?? null) ? $reference['state'] : 'unknown',
            $taskId,
        ));
    }

    /** @return array<string, mixed> */
    private function currentBinding(string $taskId): array
    {
        $session = $this->activeSession($taskId);
        if (!$session instanceof Session) {
            return [
                'owner' => 'agent-loop',
                'state' => 'not_applicable',
                'required' => false,
                'observation_mode' => 'checked',
                'reason' => 'No active governed session exists for this task.',
            ];
        }

        $briefPath = $session->path . '/work-brief.json';
        $approvalPath = $session->path . '/approval.json';
        if (!is_file($briefPath) || !is_file($approvalPath)) {
            return [
                'owner' => 'agent-loop',
                'state' => 'pending_approval',
                'required' => false,
                'observation_mode' => 'checked',
                'reason' => 'Current governed session is not approved yet.',
            ];
        }
        $brief = $this->decodeJson($briefPath);
        $approval = $this->decodeJson($approvalPath);
        $revision = $brief['revision'] ?? null;
        if (!is_int($revision) || ($brief['status'] ?? null) !== 'approved' || ($approval['work_brief_revision'] ?? null) !== $revision) {
            return [
                'owner' => 'agent-loop',
                'state' => 'pending_approval',
                'required' => false,
                'observation_mode' => 'checked',
                'reason' => 'Work brief and approval do not seal the same current revision.',
            ];
        }

        $selected = $brief['operating_prompts'] ?? [];
        if (!is_array($selected) || $selected === []) {
            return [
                'owner' => 'agent-loop',
                'state' => 'resolved',
                'required' => false,
                'observation_mode' => 'checked',
            ];
        }

        $selectedPromptIds = [];
        foreach ($selected as $selection) {
            if (!is_array($selection) || !is_string($selection['id'] ?? null) || trim($selection['id']) === '') {
                throw new RuntimeException('Approved work brief contains an invalid operating prompt selection.');
            }
            $selectedPromptIds[] = $selection['id'];
        }

        $factsPath = $this->contractDirectory($taskId) . '/facts.json';
        if (!is_file($factsPath)) {
            return [
                'owner' => 'agent-loop',
                'state' => 'pending_recall',
                'required' => true,
                'observation_mode' => 'checked',
                'reason' => 'Approved prompt policy exists but recall facts have not been compiled.',
            ];
        }
        $factsDocument = $this->decodeJson($factsPath);
        $facts = $factsDocument['facts'] ?? null;
        if (!is_array($facts)) {
            throw new RuntimeException('Recall facts.json requires a facts list.');
        }

        /** @var array<string, array{prompt_id:string, level:1|2, arguments:array<mixed>, template_sha256:?string, source_ref:?string}> $promptFacts */
        $promptFacts = [];
        foreach ($facts as $fact) {
            if (!is_array($fact) || ($fact['type'] ?? null) !== 'operating_prompt') {
                continue;
            }
            $payload = $fact['payload'] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            $promptId = $payload['prompt_id'] ?? null;
            $level = $payload['level'] ?? null;
            if (!is_string($promptId) || trim($promptId) === '' || ($level !== 1 && $level !== 2)) {
                throw new RuntimeException('Operating prompt fact has invalid prompt id or level.');
            }
            $promptFacts[$promptId] = [
                'prompt_id' => $promptId,
                'level' => $level,
                'arguments' => is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [],
                'template_sha256' => is_string($payload['template_sha256'] ?? null) ? $payload['template_sha256'] : null,
                'source_ref' => is_string($fact['source_ref'] ?? null) ? $fact['source_ref'] : null,
            ];
        }

        $selectedPromptFacts = [];
        $l2PromptIds = [];
        foreach ($selectedPromptIds as $id) {
            $promptFact = $promptFacts[$id] ?? null;
            if ($promptFact === null) {
                return [
                    'owner' => 'agent-loop',
                    'state' => 'pending_recall',
                    'required' => true,
                    'observation_mode' => 'checked',
                    'reason' => 'Current recall bundle does not contain the approved operating prompt selection: ' . $id,
                ];
            }
            $selectedPromptFacts[] = $promptFact;
            if ($promptFact['level'] === 2) {
                $l2PromptIds[] = $id;
            }
        }

        if ($l2PromptIds === []) {
            return [
                'owner' => 'agent-loop',
                'state' => 'resolved',
                'required' => false,
                'observation_mode' => 'checked',
            ];
        }

        $bundle = $factsDocument['bundle_sha256'] ?? null;
        if (!is_string($bundle) || preg_match('/\A[0-9a-f]{64}\z/', $bundle) !== 1) {
            throw new RuntimeException('Recall facts.json is missing a valid bundle_sha256.');
        }

        return [
            'owner' => 'agent-loop',
            'state' => 'resolved',
            'required' => true,
            'observation_mode' => 'checked',
            'work_brief_revision' => $revision,
            'recall_bundle_sha256' => $bundle,
            'prompt_policy_sha256' => hash('sha256', CanonicalJson::pretty(['prompts' => $selectedPromptFacts])),
            'prompt_ids' => array_values(array_unique($l2PromptIds)),
        ];
    }

    /** @return array<string, mixed> */
    private function requireResolvedL2Binding(string $taskId): array
    {
        $binding = $this->currentBinding($taskId);
        if (($binding['state'] ?? null) !== 'resolved' || ($binding['required'] ?? false) !== true) {
            throw new RuntimeException(sprintf(
                'Task %s does not have a current approved L2 prompt policy plus compiled recall bundle.',
                $taskId,
            ));
        }

        return $binding;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read JSON artifact: ' . $path);
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON artifact ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON artifact must decode to an object: ' . $path);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $document */
    private function requiredString(array $document, string $key, string $source): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($source . ' requires non-empty string ' . $key . '.');
        }

        return $value;
    }

    private function contractDirectory(string $taskId): string
    {
        return RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
    }

    private function activeSession(string $taskId): ?Session
    {
        $sessionsRoot = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($sessionsRoot)) {
            return null;
        }
        $sessions = array_values(array_filter(
            (new SessionStore())->all($sessionsRoot),
            static fn (Session $session): bool => $session->taskId === $taskId && !$session->status->isClosed(),
        ));
        if (count($sessions) > 1) {
            throw new RuntimeException('Multiple active sessions found for task ' . $taskId . '.');
        }

        return $sessions[0] ?? null;
    }

    /** @param array<string, mixed> $binding @param array<string, mixed> $extra @return array<string, mixed> */
    private function bindingReference(array $binding, string $state, array $extra = []): array
    {
        return [
            'owner' => 'agent-loop',
            'state' => $state,
            'required' => true,
            'observation_mode' => 'checked',
            'work_brief_revision' => $binding['work_brief_revision'] ?? null,
            'recall_bundle_sha256' => $binding['recall_bundle_sha256'] ?? null,
            'prompt_policy_sha256' => $binding['prompt_policy_sha256'] ?? null,
            'prompt_ids' => $binding['prompt_ids'] ?? [],
            ...$extra,
        ];
    }

    /** @return array{path: string, sha256: string|null} */
    private function artifact(string $path): array
    {
        return [
            'path' => RelativePath::fromRoot($this->rootPath, $path),
            'sha256' => is_file($path) ? hash_file('sha256', $path) ?: null : null,
        ];
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new RuntimeException('Execution contract actor must not be empty.');
        }

        return $actor;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create execution contract directory: ' . $path);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $this->makeDirectory(dirname($path));
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException('Unable to write temporary execution contract artifact: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to atomically publish execution contract artifact: ' . $path);
        }
    }
}
