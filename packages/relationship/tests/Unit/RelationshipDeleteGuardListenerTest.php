<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Relationship\RelationshipDeleteGuardListener;
use Waaseyaa\Relationship\Tests\Fixtures\FixedResultEntityQuery;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityStorage;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityTypeManager;

#[CoversClass(RelationshipDeleteGuardListener::class)]
final class RelationshipDeleteGuardListenerTest extends TestCase
{
    #[Test]
    public function blocks_deletion_of_non_node_entity_types_with_linked_relationships(): void
    {
        // The guard covers EVERY relatable entity type, not just node —
        // deleting a referenced taxonomy term must not silently orphan edges.
        $entity = $this->makeEntity('taxonomy_term', 7);
        $manager = $this->makeManager(outboundIds: [11]);
        $listener = new RelationshipDeleteGuardListener($manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Safe-delete blocked for taxonomy_term 7');
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function allows_deletion_of_non_node_entity_without_linked_relationships(): void
    {
        $entity = $this->makeEntity('taxonomy_term', 7);
        $manager = $this->makeManager();
        $listener = new RelationshipDeleteGuardListener($manager);

        $listener(new EntityEvent($entity));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function blocks_deletion_of_relationship_entities_referenced_as_endpoints(): void
    {
        // A relationship entity can itself be an endpoint of a meta-relationship;
        // its deletion is guarded by the same endpoint rule.
        $entity = $this->makeEntity('relationship', 9);
        $manager = $this->makeManager(inboundIds: [4]);
        $listener = new RelationshipDeleteGuardListener($manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Safe-delete blocked for relationship 9');
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function ignores_entities_with_null_id(): void
    {
        $entity = $this->makeEntity('node', null);
        $manager = $this->makeManager();
        $listener = new RelationshipDeleteGuardListener($manager);

        $listener(new EntityEvent($entity));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function allows_deletion_when_no_linked_relationships(): void
    {
        $entity = $this->makeEntity('node', 1);
        $query = new FixedResultEntityQuery([[], []]);
        $storage = new StubEntityStorage(
            loadHandler: static fn() => null,
            query: $query,
            entityTypeId: 'relationship',
        );
        $hasDefinitionOverride = static fn(string $typeId): bool => true;
        $manager = new StubEntityTypeManager(
            knownTypes: [],
            storage: $storage,
            hasDefinitionOverride: $hasDefinitionOverride,
        );
        $listener = new RelationshipDeleteGuardListener($manager);

        $listener(new EntityEvent($entity));
        $this->assertSame(2, $query->getCallCount());
    }

    #[Test]
    public function blocks_deletion_when_relationships_exist(): void
    {
        $entity = $this->makeEntity('node', 42);
        $manager = $this->makeManager(outboundIds: [10, 20, 30]);
        $listener = new RelationshipDeleteGuardListener($manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Safe-delete blocked for node 42');
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function exception_message_contains_sorted_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 5);
        $manager = $this->makeManager(outboundIds: [30, 10, 20]);
        $listener = new RelationshipDeleteGuardListener($manager);

        try {
            $listener(new EntityEvent($entity));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('10, 20, 30', $e->getMessage());
        }
    }

    #[Test]
    public function skips_when_relationship_type_not_defined(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = $this->makeManager(hasRelationshipType: false);
        $listener = new RelationshipDeleteGuardListener($manager);

        $listener(new EntityEvent($entity));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function deduplicates_outbound_and_inbound_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = $this->makeManager(outboundIds: [5], inboundIds: [5]);
        $listener = new RelationshipDeleteGuardListener($manager);

        try {
            $listener(new EntityEvent($entity));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('[5]', $e->getMessage());
            $this->assertStringNotContainsString('5, 5', $e->getMessage());
        }
    }

    #[Test]
    public function guard_matches_endpoints_by_uuid_as_well_as_primary_id(): void
    {
        // Relationship endpoints may legitimately reference an entity by UUID
        // (RelationshipValidator::validateEndpoint accepts them via its
        // entityExistsByUuid fallback), so the guard must match BOTH
        // identifiers — matching only the primary id lets a UUID-referenced
        // entity delete straight through, silently orphaning the edge.
        $entity = $this->makeEntity('node', 5, uuid: 'abc-uuid-123');
        $query = new ConditionRecordingEntityQuery([[], []]);
        $storage = new StubEntityStorage(
            loadHandler: static fn() => null,
            query: $query,
            entityTypeId: 'relationship',
        );
        $manager = new StubEntityTypeManager(
            knownTypes: [],
            storage: $storage,
            hasDefinitionOverride: static fn(string $typeId): bool => true,
        );
        $listener = new RelationshipDeleteGuardListener($manager);

        $listener(new EntityEvent($entity));

        $endpointIdConditions = array_values(array_filter(
            $query->conditions,
            static fn(array $condition): bool => in_array($condition[0], ['from_entity_id', 'to_entity_id'], true),
        ));
        $this->assertCount(2, $endpointIdConditions, 'one endpoint-id condition per direction');
        foreach ($endpointIdConditions as [$field, $value, $operator]) {
            $this->assertSame('IN', $operator);
            $this->assertContains('5', (array) $value, $field . ' must match the primary id');
            $this->assertContains('abc-uuid-123', (array) $value, $field . ' must match the uuid');
        }
    }

    /**
     * @param list<int|string> $outboundIds IDs returned for first query (outbound)
     * @param list<int|string> $inboundIds  IDs returned for second query (inbound)
     */
    private function makeManager(
        array $outboundIds = [],
        array $inboundIds = [],
        bool $hasRelationshipType = true,
    ): EntityTypeManagerInterface {
        $query = new FixedResultEntityQuery([$outboundIds, $inboundIds]);
        $storage = new StubEntityStorage(
            loadHandler: static fn() => null,
            query: $query,
            entityTypeId: 'relationship',
        );

        $hasDefinitionOverride = static function (string $typeId) use ($hasRelationshipType): bool {
            return $typeId === 'relationship' ? $hasRelationshipType : true;
        };

        return new StubEntityTypeManager(
            knownTypes: [],
            storage: $storage,
            hasDefinitionOverride: $hasDefinitionOverride,
        );
    }

    private function makeEntity(string $entityTypeId, int|string|null $id, string $uuid = ''): EntityInterface
    {
        return new class ($entityTypeId, $id, $uuid) implements EntityInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly int|string|null $id,
                private readonly string $uuid,
            ) {}

            public function id(): int|string|null
            {
                return $this->id;
            }

            public function uuid(): string
            {
                return $this->uuid;
            }

            public function label(): string
            {
                return 'test';
            }

            public function getEntityTypeId(): string
            {
                return $this->entityTypeId;
            }

            public function bundle(): string
            {
                return 'default';
            }

            public function isNew(): bool
            {
                return false;
            }

            public function get(string $name): mixed
            {
                return null;
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function toArray(): array
            {
                return [];
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }
}

/**
 * Records every condition() call so tests can pin the guard's query shape.
 */
final class ConditionRecordingEntityQuery extends FixedResultEntityQuery
{
    /** @var list<array{0: string, 1: mixed, 2: string}> */
    public array $conditions = [];

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = [$field, $value, $operator];

        return parent::condition($field, $value, $operator);
    }
}
