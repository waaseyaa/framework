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
    public function ignores_non_guarded_entity_types(): void
    {
        $entity = $this->makeEntity('taxonomy_term', 1);
        $manager = $this->makeManager();
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $listener(new EntityEvent($entity));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function ignores_entities_with_null_id(): void
    {
        $entity = $this->makeEntity('node', null);
        $manager = $this->makeManager();
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $listener(new EntityEvent($entity));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function allows_deletion_when_no_linked_relationships(): void
    {
        $entity = $this->makeEntity('node', 1);
        $query = new FixedResultEntityQuery([[], []]);
        $storage = new StubEntityStorage(
            loadHandler: static fn () => null,
            query: $query,
            entityTypeId: 'relationship',
        );
        $hasDefinitionOverride = static fn (string $typeId): bool => true;
        $manager = new StubEntityTypeManager(
            knownTypes: [],
            storage: $storage,
            hasDefinitionOverride: $hasDefinitionOverride,
        );
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $listener(new EntityEvent($entity));
        $this->assertSame(2, $query->getCallCount());
    }

    #[Test]
    public function blocks_deletion_when_relationships_exist(): void
    {
        $entity = $this->makeEntity('node', 42);
        $manager = $this->makeManager(outboundIds: [10, 20, 30]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Safe-delete blocked for node 42');
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function exception_message_contains_sorted_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 5);
        $manager = $this->makeManager(outboundIds: [30, 10, 20]);
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
        $manager = $this->makeManager(outboundIds: [99]);
        $listener = new RelationshipDeleteGuardListener($manager);

        $this->expectException(\RuntimeException::class);
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function skips_when_relationship_type_not_defined(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = $this->makeManager(hasRelationshipType: false);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $listener(new EntityEvent($entity));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function deduplicates_outbound_and_inbound_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = $this->makeManager(outboundIds: [5], inboundIds: [5]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        try {
            $listener(new EntityEvent($entity));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('[5]', $e->getMessage());
            $this->assertStringNotContainsString('5, 5', $e->getMessage());
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
            loadHandler: static fn () => null,
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
