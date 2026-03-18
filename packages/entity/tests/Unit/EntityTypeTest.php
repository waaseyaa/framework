<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Waaseyaa\Entity\EntityType
 */
class EntityTypeTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $type = new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
        );

        $this->assertInstanceOf(EntityTypeInterface::class, $type);
    }

    public function testRequiredProperties(): void
    {
        $type = new EntityType(
            id: 'node',
            label: 'Content',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
        );

        $this->assertSame('node', $type->id());
        $this->assertSame('Content', $type->getLabel());
        $this->assertSame('Waaseyaa\\Entity\\Tests\\Unit\\TestEntity', $type->getClass());
    }

    public function testDefaults(): void
    {
        $type = new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
        );

        $this->assertSame('', $type->getStorageClass());
        $this->assertSame([], $type->getKeys());
        $this->assertFalse($type->isRevisionable());
        $this->assertFalse($type->isTranslatable());
        $this->assertNull($type->getBundleEntityType());
        $this->assertSame([], $type->getConstraints());
        $this->assertNull($type->getGroup());
    }

    public function testAllProperties(): void
    {
        $keys = ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'];
        $constraints = ['UniqueField' => ['field' => 'title']];

        $type = new EntityType(
            id: 'node',
            label: 'Content',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            storageClass: 'Some\\Storage\\Class',
            keys: $keys,
            revisionable: true,
            translatable: true,
            bundleEntityType: 'node_type',
            constraints: $constraints,
        );

        $this->assertSame('node', $type->id());
        $this->assertSame('Content', $type->getLabel());
        $this->assertSame('Waaseyaa\\Entity\\Tests\\Unit\\TestEntity', $type->getClass());
        $this->assertSame('Some\\Storage\\Class', $type->getStorageClass());
        $this->assertSame($keys, $type->getKeys());
        $this->assertTrue($type->isRevisionable());
        $this->assertTrue($type->isTranslatable());
        $this->assertSame('node_type', $type->getBundleEntityType());
        $this->assertSame($constraints, $type->getConstraints());
        $this->assertNull($type->getGroup());
    }

    public function testGroupProperty(): void
    {
        $type = new EntityType(
            id: 'event',
            label: 'Event',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            group: 'events',
        );

        $this->assertSame('events', $type->getGroup());
    }

    public function testFieldDefinitionsDefaultsToEmptyArray(): void
    {
        $type = new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
        );

        $this->assertSame([], $type->getFieldDefinitions());
    }

    public function testFieldDefinitionsWithValues(): void
    {
        $fields = [
            'status' => [
                'type' => 'boolean',
                'label' => 'Published',
                'weight' => 10,
            ],
            'uid' => [
                'type' => 'entity_reference',
                'label' => 'Author',
                'target_entity_type_id' => 'user',
                'weight' => 20,
            ],
        ];

        $type = new EntityType(
            id: 'node',
            label: 'Content',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            fieldDefinitions: $fields,
        );

        $this->assertSame($fields, $type->getFieldDefinitions());
    }

    public function testIsReadonly(): void
    {
        $type = new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
        );

        $reflection = new \ReflectionClass($type);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }
}
