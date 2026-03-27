<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Relationship\RelationshipDeleteGuardListener;

#[CoversClass(RelationshipDeleteGuardListener::class)]
final class RelationshipDeleteGuardListenerTest extends TestCase
{
    #[Test]
    public function ignores_non_guarded_entity_types(): void
    {
        $entity = $this->makeEntity('taxonomy_term', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: []);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function ignores_entities_with_null_id(): void
    {
        $entity = $this->makeEntity('node', null);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: []);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function allows_deletion_when_no_linked_relationships(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: []);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function blocks_deletion_when_relationships_exist(): void
    {
        $entity = $this->makeEntity('node', 42);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [10, 20, 30]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Safe-delete blocked for node 42');
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function exception_message_contains_sorted_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 5);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [30, 10, 20]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        try {
            $listener(new EntityEvent($entity));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('10, 20, 30', $e->getMessage());
        }
    }

    #[Test]
    public function defaults_to_guarding_node_entity_type(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [99]);
        $listener = new RelationshipDeleteGuardListener($manager);

        $this->expectException(\RuntimeException::class);
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function skips_when_relationship_type_not_defined(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [], hasRelationshipType: false);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function deduplicates_outbound_and_inbound_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [5], inboundIds: [5]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        try {
            $listener(new EntityEvent($entity));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('[5]', $e->getMessage());
            $this->assertStringNotContainsString('5, 5', $e->getMessage());
        }
    }

    private function makeEntity(string $entityTypeId, int|string|null $id): EntityInterface
    {
        return new class ($entityTypeId, $id) implements EntityInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly int|string|null $id,
            ) {}

            public function id(): int|string|null { return $this->id; }

            public function uuid(): string { return ''; }

            public function label(): string { return 'test'; }

            public function getEntityTypeId(): string { return $this->entityTypeId; }

            public function bundle(): string { return 'default'; }

            public function isNew(): bool { return false; }

            public function get(string $name): mixed { return null; }

            public function set(string $name, mixed $value): static { return $this; }

            public function toArray(): array { return []; }

            public function language(): string { return 'en'; }
        };
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/** @internal */
final class DeleteGuardStubEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param list<int|string> $linkedIds IDs returned for outbound query
     * @param list<int|string> $inboundIds IDs returned for inbound query (defaults to empty)
     */
    public function __construct(
        private readonly array $linkedIds,
        private readonly array $inboundIds = [],
        private readonly bool $hasRelationshipType = true,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getDefinitions(): array { return []; }

    public function hasDefinition(string $entityTypeId): bool
    {
        if ($entityTypeId === 'relationship') {
            return $this->hasRelationshipType;
        }

        return true;
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return new DeleteGuardStubStorage($this->linkedIds, $this->inboundIds);
    }

    public function registerEntityType(EntityTypeInterface $type): void {}

    public function registerCoreEntityType(EntityTypeInterface $type): void {}
}

/** @internal */
final class DeleteGuardStubStorage implements EntityStorageInterface
{
    private int $queryCallCount = 0;

    /**
     * @param list<int|string> $outboundIds
     * @param list<int|string> $inboundIds
     */
    public function __construct(
        private readonly array $outboundIds,
        private readonly array $inboundIds = [],
    ) {}

    public function create(array $values = []): EntityInterface { throw new \RuntimeException('Not needed.'); }

    public function load(int|string $id): ?EntityInterface { return null; }

    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }

    public function loadMultiple(array $ids = []): array { return []; }

    public function save(EntityInterface $entity): int { throw new \RuntimeException('Not needed.'); }

    public function delete(array $entities): void {}

    public function getEntityTypeId(): string { return 'relationship'; }

    public function getQuery(): EntityQueryInterface
    {
        $this->queryCallCount++;
        $ids = $this->queryCallCount <= 1 ? $this->outboundIds : $this->inboundIds;

        return new DeleteGuardStubQuery($ids);
    }
}

/** @internal */
final class DeleteGuardStubQuery implements EntityQueryInterface
{
    /** @param list<int|string> $resultIds */
    public function __construct(private readonly array $resultIds) {}

    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }

    public function exists(string $field): static { return $this; }

    public function notExists(string $field): static { return $this; }

    public function sort(string $field, string $direction = 'ASC'): static { return $this; }

    public function range(int $offset, int $limit): static { return $this; }

    public function count(): static { return $this; }

    public function accessCheck(bool $check = true): static { return $this; }

    public function execute(): array { return $this->resultIds; }
}
