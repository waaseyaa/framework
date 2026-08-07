<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Projection;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Search\SearchIndexableInterface;

/**
 * Single resolution point from an entity to its indexable document view.
 *
 * Every search surface — full reindex, the save/delete/revision lifecycle
 * subscriber, and query-time candidate re-projection — resolves entities
 * through this registry, so index-time and query-time semantics cannot drift
 * apart. Resolution order:
 *
 *   1. An entity that itself implements {@see SearchIndexableInterface} is
 *      its own document view (unchanged legacy behaviour).
 *   2. Otherwise the first projector whose `supports()` accepts the entity
 *      owns it; that projector's `project()` result — including null — is
 *      final. Application projectors are registered ahead of the built-in
 *      ones, so an application can override the default node projection or
 *      deliberately exclude content.
 *   3. Entities nobody supports resolve to null and are not indexed.
 *
 * @api
 */
final class EntitySearchProjectionRegistry
{
    /** @var list<EntitySearchProjectorInterface> */
    private readonly array $projectors;

    /** @param array<EntitySearchProjectorInterface> $projectors Consulted in order; normalized to a list. */
    public function __construct(array $projectors)
    {
        foreach ($projectors as $projector) {
            // @phpstan-ignore instanceof.alwaysTrue (runtime API boundary)
            if (!$projector instanceof EntitySearchProjectorInterface) {
                throw new \InvalidArgumentException('Search projectors must implement EntitySearchProjectorInterface.');
            }
        }
        $this->projectors = array_values($projectors);
    }

    public function supports(EntityInterface $entity): bool
    {
        if ($entity instanceof SearchIndexableInterface) {
            return true;
        }
        foreach ($this->projectors as $projector) {
            if ($projector->supports($entity)) {
                return true;
            }
        }

        return false;
    }

    public function resolveIndexable(EntityInterface $entity): ?SearchIndexableInterface
    {
        if ($entity instanceof SearchIndexableInterface) {
            return $entity;
        }
        foreach ($this->projectors as $projector) {
            if ($projector->supports($entity)) {
                $indexable = $projector->project($entity);
                if ($indexable !== null && $indexable->getSearchDocumentId() !== EntitySearchDocumentId::fromEntity($entity)) {
                    throw new \UnexpectedValueException(sprintf(
                        'Search projector for %s must return canonical document id %s.',
                        $entity->getEntityTypeId(),
                        EntitySearchDocumentId::fromEntity($entity) ?? '(unavailable)',
                    ));
                }

                return $indexable;
            }
        }

        return null;
    }

    /**
     * Document id for lifecycle removal, derivable without a projection.
     *
     * Deletion must succeed even when the entity can no longer be projected
     * (already deleted state, unreadable fields), so projected entities use
     * the canonical identity-only form rather than a `project()` round trip.
     */
    public function documentIdFor(EntityInterface $entity): ?string
    {
        if ($entity instanceof SearchIndexableInterface) {
            return $entity->getSearchDocumentId();
        }
        foreach ($this->projectors as $projector) {
            if ($projector->supports($entity)) {
                return EntitySearchDocumentId::fromEntity($entity);
            }
        }

        return null;
    }
}
