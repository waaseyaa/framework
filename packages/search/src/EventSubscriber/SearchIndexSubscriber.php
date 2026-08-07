<?php

declare(strict_types=1);

namespace Waaseyaa\Search\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

/**
 * CW-v1 option-1 (#1920 PR-2, design §3.3): `onPostSave()` re-sources from
 * `repository->find()` (the SERVED base row) rather than indexing the
 * in-memory `$event->entity` (the just-saved tip) directly — under any
 * semantics a forward-draft save must not put its own unreviewed draft
 * content into the FTS index; a promotion save re-indexes the newly-live
 * content; every undisciplined save is byte-identical (`find()` === the
 * tip there).
 *
 * Also subscribes to {@see RevisionPointerMovedEvent} and the legacy
 * `EntityEvents::REVISION_REVERTED` (mirrors
 * `Waaseyaa\Cache\Listener\EntityCacheSubscriber`'s pattern): a standalone
 * pointer move (rollback/revert/promote with no accompanying `save()`) now
 * changes served content with no POST_SAVE of its own — without these two
 * subscriptions, a live-view rollback would leave a stale FTS entry until
 * the next ordinary edit.
 *
 * When no {@see EntityTypeManagerInterface} is wired (degraded/standalone
 * construction), `onPostSave()`/`onRevisionReverted()` fall back to the
 * triggering event's own entity object (pre-option-1 behavior, unchanged);
 * `onRevisionPointerMoved()` (which carries no entity object at all) then
 * has nothing to re-source and no-ops.
 *
 * #2270: entities that do not implement {@see SearchIndexableInterface} are
 * resolved through the shared {@see EntitySearchProjectionRegistry} — the
 * same projection contract `search:reindex` and query-time candidate
 * resolution use — so ordinary Node content is indexed on save, refreshed on
 * pointer moves, and removed on delete. Deletion derives the document id from
 * entity identity alone (no projection), so it succeeds even for content
 * whose fields are no longer readable. The framework provider always wires
 * the shared registry. The optional final constructor argument preserves
 * source compatibility for standalone consumers, which receive an empty
 * registry and retain self-indexable behavior until they opt into projectors.
 */
final class SearchIndexSubscriber implements EventSubscriberInterface
{
    private readonly LoggerInterface $logger;

    private readonly EntitySearchProjectionRegistry $projectionRegistry;

    public function __construct(
        private readonly SearchIndexerInterface $indexer,
        ?LoggerInterface $logger = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
        ?EntitySearchProjectionRegistry $projectionRegistry = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->projectionRegistry = $projectionRegistry ?? new EntitySearchProjectionRegistry([]);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::POST_SAVE->value => 'onPostSave',
            EntityEvents::POST_DELETE->value => 'onPostDelete',
            RevisionPointerMovedEvent::class => 'onRevisionPointerMoved',
            EntityEvents::REVISION_REVERTED->value => 'onRevisionReverted',
        ];
    }

    public function onPostSave(EntityEvent $event): void
    {
        $this->reindex($event->entity->getEntityTypeId(), $event->entity->id(), $event->entity);
    }

    public function onPostDelete(EntityEvent $event): void
    {
        $entity = $event->entity;

        try {
            $documentId = $this->projectionRegistry->documentIdFor($entity);
            if ($documentId === null) {
                return;
            }

            $this->indexer->remove($documentId);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Search index removal failed for a deleted %s entity: %s',
                $entity->getEntityTypeId(),
                $e->getMessage(),
            ));
        }
    }

    /**
     * @api
     */
    public function onRevisionPointerMoved(RevisionPointerMovedEvent $event): void
    {
        $this->reindex($event->entityTypeId, $event->entityId, null);
    }

    /**
     * @api
     */
    public function onRevisionReverted(EntityEvent $event): void
    {
        $this->reindex($event->entity->getEntityTypeId(), $event->entity->id(), $event->entity);
    }

    /**
     * @see class docblock for the re-source rule and the fallback rule.
     */
    private function reindex(string $entityType, int|string|null $entityId, ?EntityInterface $fallbackEntity): void
    {
        if ($entityId === null || $entityId === '') {
            return;
        }

        if ($this->entityTypeManager !== null) {
            $entity = $this->entityTypeManager->getRepository($entityType)->find((string) $entityId);
        } else {
            $entity = $fallbackEntity;
        }

        if ($entity === null) {
            return;
        }

        try {
            $indexable = $this->projectionRegistry->resolveIndexable($entity);
            if ($indexable === null) {
                // A projector may deliberately stop projecting an entity (for
                // example, when it becomes unpublished). Remove any document
                // written by an earlier projection so lifecycle indexing does
                // not leave stale or newly-ineligible content searchable.
                $documentId = $this->projectionRegistry->documentIdFor($entity);
                if ($documentId !== null) {
                    $this->indexer->remove($documentId);
                }

                return;
            }

            $this->indexer->index($indexable);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Search indexing failed for a %s entity: %s', $entityType, $e->getMessage()));
        }
    }
}
