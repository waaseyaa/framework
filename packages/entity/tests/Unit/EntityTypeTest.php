<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
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
        $this->assertNull($type->getRequiredMutationInvariant());
    }

    public function testRequiredMutationInvariantCanBeConfigured(): void
    {
        $type = new EntityType(
            id: 'test', label: 'Test', class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            requiredMutationInvariant: 'app.invariant',
        );

        $this->assertSame('app.invariant', $type->getRequiredMutationInvariant());
    }

    public function testRequiredMutationInvariantRejectsWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EntityType(
            id: 'test', label: 'Test', class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            requiredMutationInvariant: "  \t",
        );
    }

    public function testAllProperties(): void
    {
        // T007 (WP02): translatable:true requires langcode + default_langcode keys and a
        // TranslatableInterface entity class. TestContentEntity extends ContentEntityBase
        // which implements TranslatableInterface.
        $keys = ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'vid', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'];
        $constraints = ['UniqueField' => ['field' => 'title']];

        $type = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestContentEntity::class,
            storageClass: 'Some\\Storage\\Class',
            keys: $keys,
            revisionable: true,
            translatable: true,
            bundleEntityType: 'node_type',
            constraints: $constraints,
        );

        $this->assertSame('node', $type->id());
        $this->assertSame('Content', $type->getLabel());
        $this->assertSame(TestContentEntity::class, $type->getClass());
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
            'status' => new FieldDefinition(
                name: 'status',
                type: 'boolean',
                settings: ['weight' => 10],
                label: 'Published',
            ),
            'uid' => new FieldDefinition(
                name: 'uid',
                type: 'entity_reference',
                settings: ['target_entity_type_id' => 'user', 'weight' => 20],
                label: 'Author',
            ),
        ];

        $type = TestEntityType::stub(
            'node',
            $fields,
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            label: 'Content',
        );

        $defs = $type->getFieldDefinitions();
        $this->assertArrayHasKey('status', $defs);
        $this->assertArrayHasKey('uid', $defs);
        $this->assertInstanceOf(FieldDefinitionInterface::class, $defs['status']);
        $this->assertInstanceOf(FieldDefinitionInterface::class, $defs['uid']);
        $this->assertSame('boolean', $defs['status']->getType());
        $this->assertSame('Published', $defs['status']->getLabel());
        $this->assertSame(10, $defs['status']->getSetting('weight'));
        $this->assertSame('entity_reference', $defs['uid']->getType());
        $this->assertSame('Author', $defs['uid']->getLabel());
        $this->assertSame('user', $defs['uid']->getSetting('target_entity_type_id'));
        $this->assertSame(20, $defs['uid']->getSetting('weight'));
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

    public function testTenancyDefaultsToNull(): void
    {
        $type = new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
        );

        $this->assertNull($type->getTenancy());
    }

    public function testTenancyCommunityScope(): void
    {
        $type = new EntityType(
            id: 'group',
            label: 'Group',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            tenancy: ['scope' => 'community'],
        );

        $this->assertSame(['scope' => 'community'], $type->getTenancy());
    }

    public function testTenancyRejectsUnknownScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scope.*region/i');

        new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            tenancy: ['scope' => 'region'],
        );
    }

    public function testTenancyRejectsMissingScopeKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/scope/i');

        new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            tenancy: [],
        );
    }

    public function testTenancyRejectsExtraKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EntityType(
            id: 'test',
            label: 'Test',
            class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
            tenancy: ['scope' => 'community', 'extra' => 'nope'],
        );
    }
}
