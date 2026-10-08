<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\EntityStorage\Event\AfterDeleteEvent;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Event listener that invalidates listing cache entries when entities change.
 *
 * Subscribes to {@see AfterSaveEvent} and {@see AfterDeleteEvent} (event-subscription
 * wiring is owned by {@see \Waaseyaa\Listing\ServiceProvider}). Canonical
 * repository POST_DELETE notifications also call this invalidator. On each
 * event the invalidator computes a small set of tag strings derived from the entity
 * and asks the {@see TaggedCacheInterface} to evict all entries that carry them.
 *
 * Canonical tag vocabulary (FR-039):
 *
 * - `entity:<type>`              — any entity of `<type>` saved/deleted
 * - `entity:<type>:<id>`         — specific entity saved/deleted
 * - `entity:<type>:<id>:<langcode>` — translatable entity affected per langcode
 *
 * Backwards compatibility: when the dispatching driver did not populate
 * {@see AfterSaveEvent::affectedLangcodes()} (or the entity is not translatable),
 * the invalidator falls back to a single langcode tag derived from
 * {@see TranslatableInterface::activeLangcode()}. This preserves single-langcode
 * invalidation behaviour for callers that have not yet adopted the additive event
 * surface patch.
 *
 * Best-effort semantics (FR-040): cache backend errors are caught + logged at
 * warning level via {@see LoggerInterface} and execution continues. The
 * invalidator MUST NOT crash the request that triggered the save/delete.
 *
 * @see \Waaseyaa\EntityStorage\Event\AfterSaveEvent
 * @see \Waaseyaa\EntityStorage\Event\AfterDeleteEvent
 * @see TaggedCacheInterface
 */
final class ListingCacheInvalidator
{
    private readonly TaggedCacheInterface|null $cache;

    private readonly LoggerInterface $logger;

    public function __construct(
        ?TaggedCacheInterface $cache = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->cache = $cache;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Handle an {@see AfterSaveEvent} by invalidating the entity's listing tags.
     *
     */
    public function onAfterSave(AfterSaveEvent $event): void
    {
        $this->invalidate($event->entity(), $event->affectedLangcodes());
    }

    /**
     * Handle an {@see AfterDeleteEvent} by invalidating the entity's listing tags.
     *
     */
    public function onAfterDelete(AfterDeleteEvent $event): void
    {
        $this->invalidate($event->entity(), $event->affectedLangcodes());
    }

    /**
     * Compute the affected tag set for an entity + emit best-effort invalidations.
     *
     * @param list<string>|null $affectedLangcodes
     */
    public function onPostDelete(EntityEvent $event): void
    {
        $this->invalidate($event->entity, null);
    }

    private function invalidate(object $entity, ?array $affectedLangcodes): void
    {
        if ($this->cache === null) {
            return;
        }

        // {@see EntityInterface::id()} returns int|string|null. Skip invalidation
        // for entities without an id (defensive — should not happen post-save).
        if (!method_exists($entity, 'id') || !method_exists($entity, 'getEntityTypeId')) {
            return;
        }

        $id = $entity->id();
        if ($id === null) {
            return;
        }

        $entityTypeId = $entity->getEntityTypeId();
        $idString = (string) $id;

        $tags = [
            'entity:' . $entityTypeId,
            'entity:' . $entityTypeId . ':' . $idString,
        ];

        if ($entity instanceof TranslatableInterface) {
            $langcodes = $affectedLangcodes ?? [$entity->activeLangcode()];
            foreach ($langcodes as $langcode) {
                $tags[] = 'entity:' . $entityTypeId . ':' . $idString . ':' . $langcode;
            }
        }

        foreach ($tags as $tag) {
            try {
                $this->cache->invalidateByTag($tag);
            } catch (\Throwable $t) {
                $this->logger->warning('listing-cache invalidation failed', [
                    'tag' => $tag,
                    'exception' => $t::class,
                    'message' => $t->getMessage(),
                ]);
            }
        }
    }
}
