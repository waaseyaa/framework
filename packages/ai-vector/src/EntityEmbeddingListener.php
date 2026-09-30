<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Workflows\WorkflowVisibility;

/**
 * CW-v1 option-1 (#1920 PR-2, design §3.3): re-sources from the SERVED
 * content (`repository->find()`) rather than trusting the triggering
 * event's own entity/revision object. This closes two bugs in one fix:
 *
 * - A forward-draft save must not index its own unreviewed tip content —
 *   `onPostSave()`'s in-memory `$event->entity` IS that tip under
 *   discipline.
 * - Editing a published node into a forward draft must not delete the
 *   still-served embedding (the previously documented WP-2 de-index bug,
 *   `docs/specs/content-workflow.md` "Visibility (read side)") — the tip's
 *   `workflow_state` says 'draft' even though the published pointer (and
 *   `status`) still serve it live; re-sourcing via `find()` returns the
 *   served (base-row) content, whose `workflow_state` and `status` agree,
 *   so {@see WorkflowVisibility::isEntityServedPublicForEntity()} evaluates
 *   correctly WITHOUT the previously-sketched precedence flip (the spec's
 *   "Visibility (read side)" follow-up is retired by this fix — see
 *   {@see \Waaseyaa\Workflows\WorkflowVisibility}, whose own precedence and
 *   pinned tests stay untouched).
 *
 * Additionally subscribes to {@see RevisionPointerMovedEvent} and the
 * legacy `EntityEvents::REVISION_REVERTED` (mirrors
 * `Waaseyaa\Cache\Listener\EntityCacheSubscriber`'s pattern): a standalone
 * pointer move (rollback/revert/promote with no accompanying `save()`) now
 * changes served content with no POST_SAVE of its own — without these two
 * subscriptions, a live-view rollback would leave a stale embedding until
 * the next ordinary edit.
 *
 * Indexing requires a repository manager that reads fresh served rows. Missing
 * composition refuses indexing without mutation; event content is never a
 * freshness fallback. Standalone callers must supply the same source fence.
 *
 * HTTP indexing embeds current served, indexable content after commit.
 * Transactional source-change notifications own production invalidation.
 * The retained `invalidateOnly` option refuses without mutation: delayed
 * post-commit invalidation must never erase a newer vector.
 *
 * Every storage and re-sourcing call is best-effort. These listeners run
 * after the entity mutation has committed, so a failure is logged once as an
 * error and never surfaced as a failure of the committed mutation.
 */
final class EntityEmbeddingListener
{
    private readonly LoggerInterface $logger;
    private readonly EmbeddingIndexPolicy $indexPolicy;

    public function __construct(
        private readonly ?EmbeddingStorageInterface $storage = null,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
        ?LoggerInterface $logger = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
        private readonly bool $invalidateOnly = false,
        ?EmbeddingIndexPolicy $indexPolicy = null,
        private readonly ?EmbeddingExecutionGuardInterface $executionGuard = null,
    ) {
        $this->indexPolicy = $indexPolicy ?? EmbeddingIndexPolicy::fromArray([], $this->workflowVisibility);
        $this->logger = $logger ?? new NullLogger();
    }

    public function onPostSave(EntityEvent $event): void
    {
        $this->reindex($event->entity->getEntityTypeId(), $event->entity->id());
    }

    /**
     * @api
     */
    public function onRevisionPointerMoved(RevisionPointerMovedEvent $event): void
    {
        $this->reindex($event->entityTypeId, $event->entityId);
    }

    /**
     * @api
     */
    public function onRevisionReverted(EntityEvent $event): void
    {
        $this->reindex($event->entity->getEntityTypeId(), $event->entity->id());
    }

    /**
     * Reads current served content through the repository under the shared fence.
     */
    private function reindex(string $entityType, int|string|null $entityId): void
    {
        if ($entityId === null || $entityId === '') {
            return;
        }

        try {
            if ($this->invalidateOnly) {
                throw new \LogicException('[AIV-EXECUTION-008] Invalidation requires the transactional source-change event.');
            }
            if ($this->storage === null || $this->executionGuard === null) {
                throw new \LogicException('[AIV-EXECUTION-001] Indexing requires storage and a shared execution guard.');
            }
            $executor = new EmbeddingExecutor($this->storage, $this->executionGuard, $this->indexPolicy, $this->embeddingProvider);
            if ($this->embeddingProvider !== null && !$this->embeddingProvider instanceof EmbeddingSaveProviderInterface) {
                throw new \LogicException('[AIV-EXECUTION-002] HTTP indexing requires a save-budget provider.');
            }
            if ($this->entityTypeManager === null) {
                throw new \LogicException('[AIV-EXECUTION-005] Indexing requires fresh served repository reads.');
            }
            $load = fn(): ?EntityInterface => $this->entityTypeManager->getRepository($entityType)->find((string) $entityId);
            $executor->index($entityType, (string) $entityId, $load, save: true);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                'Embedding update failed for %s:%s: %s',
                $entityType,
                (string) $entityId,
                $exception->getMessage(),
            ));
        }
    }

}
