<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Resolver;

use GraphQL\Deferred;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;

/**
 * Deferred batched loader for entity_reference fields.
 *
 * Accumulates reference IDs during field resolution, then batch-loads
 * via loadMultiple() per entity type when Deferred::runQueue() fires.
 * Prevents N+1 queries on nested entity references.
 */
final class ReferenceLoader
{
    /** @var array<string, list<int|string>> */
    private array $buffer = [];

    /** @var array<string, array<int|string, \Waaseyaa\Entity\EntityInterface|null>> */
    private array $loaded = [];

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly GraphQlAccessGuard $guard,
        private readonly int $maxDepth = 3,
    ) {}

    /**
     * Enqueue a reference for batched loading. Returns a Deferred that
     * resolves to a field-access-filtered array, or null.
     */
    public function load(string $targetEntityTypeId, int|string $targetId, int $currentDepth): Deferred
    {
        if ($currentDepth >= $this->maxDepth) {
            return new Deferred(static fn() => null);
        }

        if (!$this->entityTypeManager->hasDefinition($targetEntityTypeId)) {
            return new Deferred(static fn() => null);
        }

        $this->buffer[$targetEntityTypeId][] = $targetId;

        return new Deferred(function () use ($targetEntityTypeId, $targetId, $currentDepth): ?array {
            $this->loadBuffered($targetEntityTypeId);
            $entity = $this->loaded[$targetEntityTypeId][$targetId] ?? null;

            if ($entity === null) {
                return null;
            }

            // Entity stored as the original object for access checking
            if (!$this->guard->canView($entity)) {
                return null;
            }

            $values = $entity->toArray();
            $allowed = $this->guard->filterFields($entity, array_keys($values), 'view');
            $data = array_intersect_key($values, array_flip($allowed));
            $data['_graphql_depth'] = $currentDepth;

            return $data;
        });
    }

    private function loadBuffered(string $entityTypeId): void
    {
        if (!isset($this->buffer[$entityTypeId]) || $this->buffer[$entityTypeId] === []) {
            return;
        }

        $ids = array_unique($this->buffer[$entityTypeId]);
        $this->buffer[$entityTypeId] = [];

        if (!isset($this->loaded[$entityTypeId])) {
            $this->loaded[$entityTypeId] = [];
        }

        $toLoad = array_filter(
            $ids,
            fn(int|string $id): bool => !array_key_exists($id, $this->loaded[$entityTypeId]),
        );

        if ($toLoad !== []) {
            $storage = $this->entityTypeManager->getStorage($entityTypeId);
            $entities = $storage->loadMultiple(array_values($toLoad));
            foreach ($toLoad as $id) {
                $this->loaded[$entityTypeId][$id] = $entities[$id] ?? null;
            }
        }
    }
}
