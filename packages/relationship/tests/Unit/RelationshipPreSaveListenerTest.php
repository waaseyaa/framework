<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipMaintenanceReader;
use Waaseyaa\Relationship\RelationshipPreSaveListener;
use Waaseyaa\Relationship\RelationshipValidator;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityTypeManager;

#[CoversClass(RelationshipPreSaveListener::class)]
final class RelationshipPreSaveListenerTest extends TestCase
{
    #[Test]
    public function ignores_non_relationship_entities(): void
    {
        $entity = new class implements EntityInterface {
            public function id(): int|string|null
            {
                return 1;
            }
            public function uuid(): string
            {
                return '';
            }
            public function label(): string
            {
                return 'test';
            }
            public function getEntityTypeId(): string
            {
                return 'node';
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

        $manager = new StubEntityTypeManager(['node']);
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

        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $listener(new EntityEvent($entity));

        $this->assertSame('references', $entity->get('relationship_type'));
        $snapshot = new RelationshipMaintenanceReader()->read($entity);
        $this->assertSame(1, $snapshot->status);
        $this->assertSame(3.5, $snapshot->weight);
    }

    #[Test]
    public function normalizes_all_null_optional_fields(): void
    {
        $entity = new Relationship([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'weight' => null,
            'confidence' => null,
            'start_date' => null,
            'end_date' => null,
        ]);

        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $listener(new EntityEvent($entity));

        $snapshot = new RelationshipMaintenanceReader()->read($entity);
        $this->assertNull($snapshot->weight);
        $this->assertNull($snapshot->confidence);
        $this->assertNull($snapshot->startDate);
        $this->assertNull($snapshot->endDate);
    }

    #[Test]
    public function normalizes_boundary_confidence_values(): void
    {
        $entity = new Relationship([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'confidence' => '0.0',
        ]);

        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $listener(new EntityEvent($entity));

        $this->assertSame(0.0, new RelationshipMaintenanceReader()->read($entity)->confidence);
    }

    #[Test]
    public function normalizes_date_string_through_listener(): void
    {
        $entity = new Relationship([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'start_date' => '2025-01-15',
        ]);

        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $listener(new EntityEvent($entity));

        $startDate = new RelationshipMaintenanceReader()->read($entity)->startDate;
        $this->assertIsInt($startDate);
        $this->assertGreaterThan(0, $startDate);
    }

    #[Test]
    public function throws_on_invalid_relationship_data(): void
    {
        $entity = new Relationship([
            'relationship_type' => '',
            'directionality' => 'invalid',
        ]);

        $manager = new StubEntityTypeManager([]);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship validation failed');
        $listener(new EntityEvent($entity));
    }
}
