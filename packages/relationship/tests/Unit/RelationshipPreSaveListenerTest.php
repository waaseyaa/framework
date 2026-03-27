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
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipPreSaveListener;
use Waaseyaa\Relationship\RelationshipValidator;

#[CoversClass(RelationshipPreSaveListener::class)]
final class RelationshipPreSaveListenerTest extends TestCase
{
    #[Test]
    public function ignores_non_relationship_entities(): void
    {
        $entity = new class implements EntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return 'node'; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };

        $manager = new PreSaveStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function normalizes_and_updates_relationship_entity_fields(): void
    {
        $entity = new Relationship([
            'relationship_type' => '  references  ',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 'true',
            'weight' => '3.5',
        ]);

        $manager = new PreSaveStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $listener(new EntityEvent($entity));

        $this->assertSame('references', $entity->get('relationship_type'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame(3.5, $entity->get('weight'));
    }

    #[Test]
    public function throws_on_invalid_relationship_data(): void
    {
        $entity = new Relationship([
            'relationship_type' => '',
            'directionality' => 'invalid',
        ]);

        $manager = new PreSaveStubEntityTypeManager([]);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship validation failed');
        $listener(new EntityEvent($entity));
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/** @internal */
final class PreSaveStubEntityTypeManager implements EntityTypeManagerInterface
{
    /** @param list<string> $knownTypes */
    public function __construct(private readonly array $knownTypes) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return new class($entityTypeId) implements EntityTypeInterface {
            public function __construct(private readonly string $id) {}
            public function id(): string { return $this->id; }
            public function getLabel(): string { return $this->id; }
            public function getClass(): string { return ''; }
            public function getStorageClass(): string { return ''; }
            public function getKeys(): array { return ['id' => 'id', 'uuid' => 'uuid']; }
            public function isRevisionable(): bool { return false; }
            public function getRevisionDefault(): bool { return false; }
            public function isTranslatable(): bool { return false; }
            public function getBundleEntityType(): ?string { return null; }
            public function getConstraints(): array { return []; }
            public function getFieldDefinitions(): array { return []; }
            public function getGroup(): ?string { return null; }
            public function getDescription(): ?string { return null; }
        };
    }

    public function getDefinitions(): array { return []; }

    public function hasDefinition(string $entityTypeId): bool
    {
        return in_array($entityTypeId, $this->knownTypes, true);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return new PreSaveStubEntityStorage();
    }

    public function registerEntityType(EntityTypeInterface $type): void {}
    public function registerCoreEntityType(EntityTypeInterface $type): void {}
}

/** @internal */
final class PreSaveStubEntityStorage implements EntityStorageInterface
{
    public function create(array $values = []): EntityInterface { throw new \RuntimeException('Not needed.'); }

    public function load(int|string $id): ?EntityInterface
    {
        return new class($id) implements EntityInterface {
            public function __construct(private readonly int|string $id) {}
            public function id(): int|string|null { return $this->id; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return 'node'; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }
    public function loadMultiple(array $ids = []): array { return []; }
    public function save(EntityInterface $entity): int { throw new \RuntimeException('Not needed.'); }
    public function delete(array $entities): void {}

    public function getQuery(): EntityQueryInterface
    {
        return new PreSaveStubEntityQuery();
    }

    public function getEntityTypeId(): string { return 'node'; }
}

/** @internal */
final class PreSaveStubEntityQuery implements EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }
    public function execute(): array { return []; }
}
