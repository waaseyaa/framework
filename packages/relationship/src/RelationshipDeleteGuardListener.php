<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;

/**
 * Blocks deletion of any entity that is still referenced as a relationship
 * endpoint, so deletes cannot silently orphan edge rows.
 *
 * Covers EVERY entity type: relationship endpoints are free-form
 * (type, id) pairs — RelationshipValidator accepts any registered entity
 * type — so scoping the guard to one type (the historical hardcoded
 * 'node') silently orphaned edges for all other relatable types.
 * Registered by RelationshipServiceProvider::boot() on
 * EntityEvents::PRE_DELETE; the throw aborts the delete before the row
 * is removed. Note: deleteMany() buffers lifecycle events until after
 * commit (UnitOfWork), so only the single-delete path is guarded.
 */
final class RelationshipDeleteGuardListener
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function __invoke(EntityEvent $event): void
    {
        $entity = $event->entity;
        $entityId = $entity->id();
        if ($entityId === null) {
            return;
        }

        if (!$this->entityTypeManager->hasDefinition('relationship')) {
            return;
        }

        $entityType = $entity->getEntityTypeId();

        // C-22 WP2: the query builder now lives on the repository.
        $relationshipRepository = $this->entityTypeManager->getRepository('relationship');
        $idString = (string) $entityId;

        // Endpoints may reference the entity by primary id OR by uuid
        // (RelationshipValidator accepts both) — match both identifiers or
        // UUID-referenced edges would orphan straight through the guard.
        $uuid = trim($entity->uuid());
        $endpointIds = ($uuid !== '' && $uuid !== $idString) ? [$idString, $uuid] : [$idString];

        $outbound = $relationshipRepository->getQuery()
            ->condition('from_entity_type', $entityType)
            ->condition('from_entity_id', $endpointIds, 'IN')
            // system context: referential-integrity check spans access boundaries
            ->accessCheck(false)
            ->execute();
        $inbound = $relationshipRepository->getQuery()
            ->condition('to_entity_type', $entityType)
            ->condition('to_entity_id', $endpointIds, 'IN')
            // system context: referential-integrity check spans access boundaries
            ->accessCheck(false)
            ->execute();

        $linkedIds = array_values(array_unique([
            ...$outbound,
            ...$inbound,
        ]));
        if ($linkedIds === []) {
            return;
        }

        sort($linkedIds);

        throw new \RuntimeException(sprintf(
            'Safe-delete blocked for %s %s: linked relationship IDs [%s].',
            $entityType,
            $idString,
            implode(', ', array_map(static fn($id): string => (string) $id, $linkedIds)),
        ));
    }
}
