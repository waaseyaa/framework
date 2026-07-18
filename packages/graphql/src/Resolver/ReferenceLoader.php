<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Resolver;

use GraphQL\Deferred;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;

/**
 * DataLoader-style deferred batching for `entity_reference` GraphQL fields.
 *
 * Matches the usual DataLoader contract at a high level: enqueue many
 * independent loads during the same resolver wave, flush once per entity
 * type when {@see Deferred} completes, then satisfy each deferred callback
 * from an in-memory map. Uses {@see EntityTypeManagerInterface::getRepository()}
 * and {@see \Waaseyaa\Entity\Repository\EntityRepositoryInterface::findMany()}
 * so each type sees one SQL round-trip per queue flush instead of N.
 *
 * Lifecycle: construct a new instance per GraphQL request (see
 * {@see \Waaseyaa\GraphQL\GraphQlEndpoint::handle}) so buffers do not leak
 * across operations or users.
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

            $allowed = $this->guard->filterFields($entity, EntityValues::ordinaryFieldNames($entity), 'view');
            $data = EntityValues::toCastAwareMap($entity, $allowed);
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
            // C-22 WP3: read path now goes through the canonical repository.
            // findMany() returns a plain list; re-key by id to preserve the lookup below.
            $repository = $this->entityTypeManager->getRepository($entityTypeId);
            $entities = [];
            foreach ($repository->findMany(array_values($toLoad)) as $loadedEntity) {
                $entities[$loadedEntity->id()] = $loadedEntity;
            }
            foreach ($toLoad as $id) {
                $this->loaded[$entityTypeId][$id] = $entities[$id] ?? null;
            }
        }
    }
}
