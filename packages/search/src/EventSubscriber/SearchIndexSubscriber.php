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
 */
final class SearchIndexSubscriber implements EventSubscriberInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SearchIndexerInterface $indexer,
        ?LoggerInterface $logger = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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

        if (!$entity instanceof SearchIndexableInterface) {
            return;
        }

        try {
            $this->indexer->remove($entity->getSearchDocumentId());
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Search index removal failed for %s: %s', $entity->getSearchDocumentId(), $e->getMessage()));
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

        if (!$entity instanceof SearchIndexableInterface) {
            return;
        }

        try {
            $this->indexer->index($entity);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Search indexing failed for %s: %s', $entity->getSearchDocumentId(), $e->getMessage()));
        }
    }
}
