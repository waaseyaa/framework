<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipMaintenanceReader;
use Waaseyaa\Relationship\RelationshipTopologyReader;

#[CoversClass(Relationship::class)]
final class RelationshipTest extends TestCase
{
    #[Test]
    public function entity_type_id_is_relationship(): void
    {
        $entity = new Relationship();
        $this->assertSame('relationship', $entity->getEntityTypeId());
    }

    #[Test]
    public function default_values_are_applied(): void
    {
        $entity = new Relationship();
        $snapshot = new RelationshipMaintenanceReader()->read($entity);
        $this->assertSame('directed', $snapshot->directionality);
        $this->assertSame(1, $snapshot->status);
    }

    #[Test]
    public function constructor_values_override_defaults(): void
    {
        $entity = new Relationship([
            'directionality' => 'bidirectional',
            'status' => 0,
        ]);
        $snapshot = new RelationshipMaintenanceReader()->read($entity);
        $this->assertSame('bidirectional', $snapshot->directionality);
        $this->assertSame(0, $snapshot->status);
    }

    #[Test]
    public function entity_keys_map_correctly(): void
    {
        $entity = new Relationship([
            'rid' => 42,
            'uuid' => 'abc-123',
            'relationship_type' => 'references',
        ]);
        $this->assertSame(42, $entity->id());
        $this->assertSame('abc-123', $entity->uuid());
        $this->assertSame('references', $entity->label());
        $this->assertSame('references', $entity->bundle());
    }

    #[Test]
    public function implements_content_entity_and_fieldable_interfaces(): void
    {
        $entity = new Relationship();
        $this->assertInstanceOf(ContentEntityInterface::class, $entity);
        $this->assertInstanceOf(FieldableInterface::class, $entity);
    }

    #[Test]
    public function custom_fields_are_accessible(): void
    {
        $entity = new Relationship([
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'weight' => 5.0,
            'confidence' => 0.9,
            'notes' => 'Test note',
        ]);
        $topology = new RelationshipTopologyReader()->read($entity);
        $snapshot = new RelationshipMaintenanceReader()->read($entity);
        $this->assertNotNull($topology);
        $this->assertSame('node', $topology->fromType);
        $this->assertSame('1', $topology->fromId);
        $this->assertSame('node', $topology->toType);
        $this->assertSame('2', $topology->toId);
        $this->assertSame(5.0, $snapshot->weight);
        $this->assertSame(0.9, $snapshot->confidence);
        $this->assertSame('Test note', $snapshot->notes);
    }

    #[Test]
    public function set_mutates_field_values(): void
    {
        $entity = new Relationship(['weight' => 1.0]);
        $entity->set('weight', 5.0);
        $this->assertSame(5.0, new RelationshipMaintenanceReader()->read($entity)->weight);
    }

    #[Test]
    public function protected_values_require_a_read_context_for_array_export(): void
    {
        $entity = new Relationship([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
        ]);
        $this->expectException(MissingFieldReadContext::class);
        $entity->toArray();
    }
}
