<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Relationship\Relationship;

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
        $this->assertSame('directed', $entity->get('directionality'));
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function constructor_values_override_defaults(): void
    {
        $entity = new Relationship([
            'directionality' => 'bidirectional',
            'status' => 0,
        ]);
        $this->assertSame('bidirectional', $entity->get('directionality'));
        $this->assertSame(0, $entity->get('status'));
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
        $this->assertSame('node', $entity->get('from_entity_type'));
        $this->assertSame('1', $entity->get('from_entity_id'));
        $this->assertSame('node', $entity->get('to_entity_type'));
        $this->assertSame('2', $entity->get('to_entity_id'));
        $this->assertSame(5.0, $entity->get('weight'));
        $this->assertSame(0.9, $entity->get('confidence'));
        $this->assertSame('Test note', $entity->get('notes'));
    }

    #[Test]
    public function set_mutates_field_values(): void
    {
        $entity = new Relationship(['weight' => 1.0]);
        $entity->set('weight', 5.0);
        $this->assertSame(5.0, $entity->get('weight'));
    }

    #[Test]
    public function to_array_returns_all_values(): void
    {
        $entity = new Relationship([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
        ]);
        $array = $entity->toArray();
        $this->assertSame('references', $array['relationship_type']);
        $this->assertSame('node', $array['from_entity_type']);
        $this->assertSame('directed', $array['directionality']);
        $this->assertSame(1, $array['status']);
    }
}
