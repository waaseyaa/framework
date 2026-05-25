<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Media\Media;

/**
 * Sweeps all MediaVersion rows when a parent Media entity is deleted.
 *
 * Best-effort: exceptions are caught and logged; the parent delete is
 * never disrupted (per CLAUDE.md §Logging best-effort pattern).
 *
 * @api
 */
final class MediaCascadeDeleteSubscriber implements EventSubscriberInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MediaVersionRepository $versionRepo,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::POST_DELETE->value => 'onMediaPostDelete',
        ];
    }

    public function onMediaPostDelete(EntityEvent $event): void
    {
        try {
            $entity = $event->entity;

            if (!$entity instanceof Media) {
                return;
            }

            $mediaUuid = $entity->uuid();
            if ($mediaUuid === '') {
                return;
            }

            $this->versionRepo->deleteAllForMedia($mediaUuid);
        } catch (\Throwable $e) {
            $this->logger->warning('MediaCascadeDeleteSubscriber.onMediaPostDelete failed: ' . $e->getMessage());
        }
    }
}
