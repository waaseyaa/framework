<?php

declare(strict_types=1);

namespace Waaseyaa\Search\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

final class SearchIndexSubscriber implements EventSubscriberInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SearchIndexerInterface $indexer,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::POST_SAVE->value => 'onPostSave',
            EntityEvents::POST_DELETE->value => 'onPostDelete',
        ];
    }

    public function onPostSave(EntityEvent $event): void
    {
        $entity = $event->entity;

        if (!$entity instanceof SearchIndexableInterface) {
            return;
        }

        try {
            $this->indexer->index($entity);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Search indexing failed for %s: %s', $entity->getSearchDocumentId(), $e->getMessage()));
        }
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
}
