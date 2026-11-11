<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use Throwable;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Exception\AgentKanbanException;
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Repository\BoardContext;
use voku\AgentKanban\Repository\BoardContextResolver;
use voku\AgentKanban\Transition\TransitionPolicy;
use voku\AgentLoop\ProjectLayout;

/**
 * Synchronize Kanban card lane with governed lifecycle transitions.
 *
 * Loop delegates card mutations to agent-kanban's typed owner APIs and
 * respects the board's configured lanes and transitions.
 */
final readonly class KanbanLaneSynchronizer
{
    private const string CARD_ID_PATTERN = '/^[A-Z][A-Z0-9]*-[1-9][0-9]*$/';

    public function __construct(private string $rootPath)
    {
    }

    public function synchronizeWorkStart(TaskContract $contract, ?string $actor = null): ?KanbanLaneSyncResult
    {
        $taskId = trim($contract->taskId);
        if (str_contains($taskId, "\0") || preg_match(self::CARD_ID_PATTERN, strtoupper($taskId)) !== 1) {
            return null;
        }

        $cardId = CardId::fromString(strtoupper($taskId));
        $context = $this->resolveBoardContext($cardId);
        if ($context === null || !$context->repository->exists($cardId)) {
            return null;
        }

        $card = $context->repository->load($cardId);
        $currentLane = $card->lane;
        $currentRevision = $card->revision;

        $activeLane = $this->resolveActiveLane($context->config);
        if ($activeLane === null) {
            return new KanbanLaneSyncResult(
                taskId: $contract->taskId,
                previousLane: $currentLane->toString(),
                currentLane: $currentLane->toString(),
                mutated: false,
                warning: 'Kanban board has no recognized active lane.',
                newRevision: $currentRevision->toString(),
            );
        }

        if ($currentLane->equals($activeLane)) {
            return new KanbanLaneSyncResult(
                taskId: $contract->taskId,
                previousLane: $currentLane->toString(),
                currentLane: $activeLane->toString(),
                mutated: false,
                newRevision: $currentRevision->toString(),
            );
        }

        if (in_array(strtoupper($currentLane->toString()), ['VERIFY', 'DONE', 'CLOSED', 'ARCHIVED'], true)) {
            return new KanbanLaneSyncResult(
                taskId: $contract->taskId,
                previousLane: $currentLane->toString(),
                currentLane: $currentLane->toString(),
                mutated: false,
                newRevision: $currentRevision->toString(),
            );
        }

        $transitionPolicy = new TransitionPolicy($context->config);
        $path = $this->findTransitionPath($transitionPolicy, $currentLane, $activeLane);
        if ($path === null) {
            return new KanbanLaneSyncResult(
                taskId: $contract->taskId,
                previousLane: $currentLane->toString(),
                currentLane: $currentLane->toString(),
                mutated: false,
                warning: sprintf(
                    'Card %s cannot transition from %s to %s: no permitted transition path in board configuration.',
                    $cardId,
                    $currentLane,
                    $activeLane,
                ),
                newRevision: $currentRevision->toString(),
            );
        }

        $effectiveActor = trim($actor ?? $contract->approvedBy ?? $contract->plannedBy);
        if ($effectiveActor === '') {
            $effectiveActor = 'agent-loop';
        }

        $now = new DateTimeImmutable();
        if ($card->claim !== null && !$card->claim->isExpired($now) && $card->claim->actor !== $effectiveActor) {
            return new KanbanLaneSyncResult(
                taskId: $contract->taskId,
                previousLane: $currentLane->toString(),
                currentLane: $currentLane->toString(),
                mutated: false,
                warning: sprintf(
                    'Card %s is already claimed by "%s". Retaining source lane %s.',
                    $cardId,
                    $card->claim->actor,
                    $currentLane,
                ),
                newRevision: $currentRevision->toString(),
            );
        }

        try {
            $mutationService = new CardMutationService(
                $context->rootPath,
                $context->config,
                $context->repository,
                $transitionPolicy,
            );

            foreach ($path as $stepLane) {
                $card = $this->satisfyRequiredFields($mutationService, $context->config, $card, $stepLane, $contract);

                if ($stepLane->equals($activeLane)) {
                    if ($transitionPolicy->canTransition($card->lane, $activeLane)) {
                        $claimResult = $mutationService->claim(
                            $cardId,
                            $effectiveActor,
                            moveToDoing: $activeLane->equals(Lane::fromString('DOING')),
                            expectedRevision: $card->revision,
                        );
                        $card = $claimResult->card;
                        if (!$card->lane->equals($activeLane)) {
                            $moveResult = $mutationService->move(
                                $cardId,
                                $activeLane,
                                actor: $effectiveActor,
                                expectedRevision: $card->revision,
                            );
                            $card = $moveResult->card;
                        }
                    } else {
                        $moveResult = $mutationService->move(
                            $cardId,
                            $stepLane,
                            actor: $effectiveActor,
                            expectedRevision: $card->revision,
                        );
                        $card = $moveResult->card;
                    }
                } else {
                    $moveResult = $mutationService->move(
                        $cardId,
                        $stepLane,
                        actor: $effectiveActor,
                        expectedRevision: $card->revision,
                    );
                    $card = $moveResult->card;
                }
            }

            return new KanbanLaneSyncResult(
                taskId: $contract->taskId,
                previousLane: $currentLane->toString(),
                currentLane: $card->lane->toString(),
                mutated: true,
                newRevision: $card->revision->toString(),
            );
        } catch (Throwable $exception) {
            return new KanbanLaneSyncResult(
                taskId: $contract->taskId,
                previousLane: $currentLane->toString(),
                currentLane: $card->lane->toString(),
                mutated: false,
                warning: sprintf(
                    'Kanban lane synchronization refused for %s: %s. Source lane retained.',
                    $cardId,
                    $exception->getMessage(),
                ),
                newRevision: $card->revision->toString(),
            );
        }
    }

    public function synchronizeWorkClose(string $taskId, ?string $actor = null): ?KanbanLaneSyncResult
    {
        $normalized = trim($taskId);
        if (str_contains($normalized, "\0") || preg_match(self::CARD_ID_PATTERN, strtoupper($normalized)) !== 1) {
            return null;
        }

        $cardId = CardId::fromString(strtoupper($normalized));
        $context = $this->resolveBoardContext($cardId);
        if ($context === null || !$context->repository->exists($cardId)) {
            return null;
        }

        $doneLane = null;
        foreach (['DONE', 'CLOSED'] as $candidate) {
            if ($context->config->supportsLane(Lane::fromString($candidate))) {
                $doneLane = Lane::fromString($candidate);
                break;
            }
        }

        if ($doneLane === null) {
            return null;
        }

        $card = $context->repository->load($cardId);
        $currentLane = $card->lane;
        if ($currentLane->equals($doneLane)) {
            return new KanbanLaneSyncResult(
                taskId: $taskId,
                previousLane: $currentLane->toString(),
                currentLane: $doneLane->toString(),
                mutated: false,
                newRevision: $card->revision->toString(),
            );
        }

        $transitionPolicy = new TransitionPolicy($context->config);
        if (!$transitionPolicy->canTransition($currentLane, $doneLane)) {
            return null;
        }

        $effectiveActor = $actor !== null && trim($actor) !== '' ? trim($actor) : 'agent-loop';

        try {
            $mutationService = new CardMutationService(
                $context->rootPath,
                $context->config,
                $context->repository,
                $transitionPolicy,
            );

            $result = $mutationService->move(
                $cardId,
                $doneLane,
                actor: $effectiveActor,
                expectedRevision: $card->revision,
            );

            return new KanbanLaneSyncResult(
                taskId: $taskId,
                previousLane: $currentLane->toString(),
                currentLane: $result->card->lane->toString(),
                mutated: true,
                newRevision: $result->newRevision->toString(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveBoardContext(CardId $cardId): ?BoardContext
    {
        try {
            $boardRoot = (new ProjectLayout($this->rootPath))->boardRoot();
            $resolver = new BoardContextResolver();
            $context = $resolver->resolveOptional($boardRoot);
            if ($context === null) {
                return null;
            }

            if ($context->config->projectPrefix !== $cardId->prefix) {
                $context = $this->contextForPrefix($resolver->resolveAll($boardRoot), $cardId->prefix);
            }

            return $context;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, BoardContext> $contexts */
    private function contextForPrefix(array $contexts, string $prefix): ?BoardContext
    {
        foreach ($contexts as $context) {
            if ($context->config->projectPrefix === $prefix) {
                return $context;
            }
        }

        return null;
    }

    private function resolveActiveLane(BoardConfig $config): ?Lane
    {
        if ($config->supportsLane(Lane::fromString('DOING'))) {
            return Lane::fromString('DOING');
        }

        foreach (['IN_PROGRESS', 'IN PROGRESS', 'ACTIVE'] as $candidate) {
            if ($config->supportsLane(Lane::fromString($candidate))) {
                return Lane::fromString($candidate);
            }
        }

        return null;
    }

    /**
     * @return list<Lane>|null
     */
    private function findTransitionPath(TransitionPolicy $policy, Lane $from, Lane $to): ?array
    {
        if ($policy->canTransition($from, $to)) {
            return [$to];
        }

        /** @var list<list<Lane>> $queue */
        $queue = [[$from]];
        $visited = [$from->toString() => true];

        while ($queue !== []) {
            $currentPath = array_shift($queue);
            $lastLane = $currentPath[array_key_last($currentPath)] ?? null;
            if (!$lastLane instanceof Lane) {
                continue;
            }
            $targets = $policy->allowedTargets($lastLane);

            foreach ($targets as $targetStr) {
                $targetLane = Lane::fromString($targetStr);
                if ($targetLane->equals($to)) {
                    /** @var list<Lane> $fullPath */
                    $fullPath = array_slice(array_merge($currentPath, [$targetLane]), 1);
                    return $fullPath;
                }

                if (!isset($visited[$targetStr])) {
                    $visited[$targetStr] = true;
                    $queue[] = array_merge($currentPath, [$targetLane]);
                }
            }
        }

        return null;
    }

    private function satisfyRequiredFields(
        CardMutationService $mutationService,
        BoardConfig $config,
        Card $card,
        Lane $stepLane,
        TaskContract $contract,
    ): Card {
        $requiredFields = $config->requiredFieldsByLane[$stepLane->toString()] ?? [];
        if (in_array('taskBrief', $requiredFields, true) && $card->taskBrief === '') {
            $brief = trim($contract->goal);
            if ($brief !== '') {
                $updateResult = $mutationService->update(
                    $card->id,
                    taskBrief: $brief,
                    expectedRevision: $card->revision,
                );
                return $updateResult->card;
            }
        }

        return $card;
    }
}
